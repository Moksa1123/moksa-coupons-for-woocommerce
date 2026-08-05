<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponConditions;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces coupon conditions (schedule / role / cart) at apply + recalculation
 * time via the shared woocommerce_coupon_is_valid path. A failed condition throws
 * an Exception whose message is shown to the customer; for the Block checkout
 * (Store API) the message is also surfaced through woocommerce_coupon_error.
 *
 * The pure verdict helpers (schedule_verdict / role_is_blocked) are unit-tested
 * without WooCommerce.
 */
final class Validator {

	/** @var array<int,string> Custom error message per coupon id, for the Block error filter. */
	private static array $last_error = array();

	public static function boot(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'validate' ), 10, 2 );
		add_filter( 'woocommerce_coupon_error', array( self::class, 'block_error' ), 10, 3 );
		// Payment-method condition can only be checked at checkout (the gateway is unknown at
		// cart-apply time): classic posts $data['payment_method']; the Block/Store API builds
		// the order then runs this before payment is processed.
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'validate_payment_classic' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'validate_payment_store' ), 10, 2 );
	}

	/**
	 * @param mixed $valid  WC's prior validity result.
	 * @param mixed $coupon WC_Coupon.
	 * @return mixed
	 */
	public static function validate( $valid, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon ) {
			return $valid;
		}
		$id = $coupon->get_id();

		$schedule = self::schedule_block( $coupon );
		if ( null !== $schedule ) {
			self::fail( $id, $schedule );
		}

		$role = self::role_block( $coupon );
		if ( null !== $role ) {
			self::fail( $id, $role );
		}

		if ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			$cart = self::cart_block( $coupon, WC()->cart );
			if ( null !== $cart ) {
				self::fail( $id, $cart );
			}
		}

		$customer = self::customer_block( $coupon );
		if ( null !== $customer ) {
			self::fail( $id, $customer );
		}

		$product = self::product_block( $coupon );
		if ( null !== $product ) {
			self::fail( $id, $product );
		}

		$shipping = self::shipping_block( $coupon );
		if ( null !== $shipping ) {
			self::fail( $id, $shipping );
		}

		$daytime = self::daytime_block( $coupon );
		if ( null !== $daytime ) {
			self::fail( $id, $daytime );
		}

		return $valid;
	}

	/**
	 * @throws \Exception Always — carries the customer-facing message.
	 */
	private static function fail( int $id, string $message ): void {
		self::$last_error[ $id ] = $message;
		throw new \Exception( wp_kses_post( self::maybe_entity_encode( $message ) ) );
	}

	/**
	 * Block checkout strips message HTML; encode entities so it survives.
	 */
	private static function maybe_entity_encode( string $message ): string {
		$store_api = isset( $_SERVER['REQUEST_URI'] )
			&& false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '/wc/store/' );
		$wc_94     = defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.4.0', '>=' );
		if ( ( $store_api || $wc_94 ) && function_exists( 'htmlentities2' ) ) {
			return htmlentities2( $message );
		}
		return $message;
	}

	/**
	 * Store API shows WC_Coupon::get_error_message() (by code), not the thrown
	 * Exception text — restore our custom message here.
	 *
	 * @param mixed $message Default WC message.
	 * @param mixed $code    Error code.
	 * @param mixed $coupon  WC_Coupon.
	 * @return mixed
	 */
	public static function block_error( $message, $code, $coupon ) {
		if ( $coupon instanceof \WC_Coupon && isset( self::$last_error[ $coupon->get_id() ] ) ) {
			return self::$last_error[ $coupon->get_id() ];
		}
		return $message;
	}

	/* ---------------- schedule ---------------- */

	public static function schedule_block( \WC_Coupon $coupon ): ?string {
		if ( 'yes' !== $coupon->get_meta( Keys::SCHEDULE_ENABLED, true ) ) {
			return null;
		}
		$start   = SiteTime::to_timestamp( (string) $coupon->get_meta( Keys::SCHEDULE_START, true ) );
		$end     = SiteTime::to_timestamp( (string) $coupon->get_meta( Keys::SCHEDULE_END, true ) );
		$verdict = self::schedule_verdict( $start, $end, time() );
		if ( 'start' === $verdict ) {
			return self::msg( $coupon, Keys::SCHEDULE_MSG_START, __( 'This coupon has not started yet.', 'moksa-coupons-for-woocommerce' ) );
		}
		if ( 'expire' === $verdict ) {
			return self::msg( $coupon, Keys::SCHEDULE_MSG_END, __( 'This coupon is past its usable time.', 'moksa-coupons-for-woocommerce' ) );
		}
		return null;
	}

	/** Pure: 'start' (not yet), 'expire' (ended) or null (within window). */
	public static function schedule_verdict( ?int $start, ?int $end, int $now ): ?string {
		if ( null !== $start && $now < $start ) {
			return 'start';
		}
		if ( null !== $end && $now > $end ) {
			return 'expire';
		}
		return null;
	}

	/* ---------------- role ---------------- */

	public static function role_block( \WC_Coupon $coupon ): ?string {
		if ( 'yes' !== $coupon->get_meta( Keys::ROLE_ENABLED, true ) ) {
			return null;
		}
		$type = $coupon->get_meta( Keys::ROLE_TYPE, true );
		$type = 'disallowed' === $type ? 'disallowed' : 'allowed';
		// (array) '' is array( '' ), not array() — filter empties so "restriction on,
		// no roles chosen" does not spuriously block everyone.
		$roles = array_values( array_filter( (array) $coupon->get_meta( Keys::ROLE_LIST, true ), static fn( $r ): bool => is_string( $r ) && '' !== $r ) );

		$user       = wp_get_current_user();
		$user_roles = ( $user && $user->ID ) ? (array) $user->roles : array( 'guest' );

		if ( self::role_is_blocked( $type, $roles, $user_roles ) ) {
			return self::msg( $coupon, Keys::ROLE_MSG, __( 'Your current account does not have permission to use this coupon.', 'moksa-coupons-for-woocommerce' ) );
		}
		return null;
	}

	/**
	 * Pure. allowed: blocked when the user has none of the roles. disallowed:
	 * blocked when the user has any of the roles. No roles configured = no limit.
	 *
	 * @param string            $type       'allowed' | 'disallowed'.
	 * @param array<int,string> $roles      Configured roles.
	 * @param array<int,string> $user_roles The current user's roles.
	 */
	public static function role_is_blocked( string $type, array $roles, array $user_roles ): bool {
		if ( array() === $roles ) {
			return false;
		}
		$hit = (bool) array_intersect( $roles, $user_roles );
		return 'disallowed' === $type ? $hit : ! $hit;
	}

	/* ---------------- cart (minimum semantics, no user operators) ---------------- */

	public static function cart_block( \WC_Coupon $coupon, \WC_Cart $cart ): ?string {
		$min_subtotal = (string) $coupon->get_meta( Keys::MIN_SUBTOTAL, true );
		$min_qty      = (int) $coupon->get_meta( Keys::MIN_QTY, true );
		if ( '' === $min_subtotal && $min_qty <= 0 ) {
			return null;
		}

		if ( '' !== $min_subtotal ) {
			$subtotal = (float) $cart->get_subtotal();
			if ( 'yes' === $coupon->get_meta( Keys::MIN_SUBTOTAL_INCL_TAX, true ) ) {
				$subtotal += (float) $cart->get_subtotal_tax();
			}
			/** Seam for multi-currency add-ons (default returns the value unchanged). */
			$subtotal = (float) apply_filters( 'moksafocou_condition_amount', $subtotal, $coupon );
			if ( $subtotal < (float) $min_subtotal ) {
				return self::msg(
					$coupon,
					Keys::CART_MSG,
					sprintf(
						/* translators: %s: formatted minimum subtotal. */
						__( 'The cart subtotal must reach %s to use this coupon.', 'moksa-coupons-for-woocommerce' ),
						wp_strip_all_tags( wc_price( (float) $min_subtotal ) )
					)
				);
			}
		}

		if ( $min_qty > 0 ) {
			$qty = 0;
			foreach ( $cart->get_cart_contents() as $item ) {
				if ( apply_filters( 'moksafocou_condition_item_valid', true, $item ) ) {
					$qty += (int) ( $item['quantity'] ?? 0 );
				}
			}
			if ( $qty < $min_qty ) {
				return self::msg(
					$coupon,
					Keys::CART_MSG,
					sprintf(
						/* translators: %d: minimum item count. */
						__( 'The cart must have at least %d item(s) to use this coupon.', 'moksa-coupons-for-woocommerce' ),
						$min_qty
					)
				);
			}
		}

		return null;
	}

	/* ---------------- customer history (first order / order count / total spent) ---------------- */

	/** @var array<int,array{count:int,spent:float}> Per-request memo of customer paid-order history. */
	private static array $history_cache = array();

	public static function customer_block( \WC_Coupon $coupon ): ?string {
		if ( 'yes' !== $coupon->get_meta( Keys::CUST_ENABLED, true ) ) {
			return null;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$history = self::customer_history( $user_id );

		$verdict = self::customer_verdict(
			'yes' === $coupon->get_meta( Keys::CUST_FIRST_ONLY, true ),
			self::int_or_null( $coupon->get_meta( Keys::CUST_MIN_ORDERS, true ) ),
			self::int_or_null( $coupon->get_meta( Keys::CUST_MAX_ORDERS, true ) ),
			self::float_or_null( $coupon->get_meta( Keys::CUST_MIN_SPENT, true ) ),
			self::float_or_null( $coupon->get_meta( Keys::CUST_MAX_SPENT, true ) ),
			$history['count'],
			$history['spent']
		);
		if ( null === $verdict ) {
			return null;
		}
		return self::msg( $coupon, Keys::CUST_MSG, self::customer_default_message( $verdict ) );
	}

	/**
	 * The current customer's PAID-order count + lifetime spend (guests = 0/0). Memoized
	 * per request so repeated is_coupon_valid calls don't re-query the order store.
	 *
	 * @return array{count:int,spent:float}
	 */
	private static function customer_history( int $user_id ): array {
		if ( isset( self::$history_cache[ $user_id ] ) ) {
			return self::$history_cache[ $user_id ];
		}
		$count = 0;
		$spent = 0.0;
		if ( $user_id > 0 && function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_is_paid_statuses' ) ) {
			// Hot path (runs per coupon validation): count via a COUNT query — return ids and read
			// paginate->total — instead of hydrating every order object. Lifetime spend comes from
			// WooCommerce's own paid-status aggregate (cached in the _money_spent user meta). Both
			// keep the exact "paid statuses only" semantics of the previous object scan.
			$result = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'status'      => wc_get_is_paid_statuses(),
					'type'        => 'shop_order',
					'limit'       => 1,
					'return'      => 'ids',
					'paginate'    => true,
				)
			);
			$count  = ( is_object( $result ) && isset( $result->total ) ) ? (int) $result->total : 0;
			$spent  = function_exists( 'wc_get_customer_total_spent' ) ? (float) wc_get_customer_total_spent( $user_id ) : 0.0;
		}
		self::$history_cache[ $user_id ] = array(
			'count' => $count,
			'spent' => $spent,
		);
		return self::$history_cache[ $user_id ];
	}

	/**
	 * Pure verdict for customer-history conditions. Returns the failing rule key
	 * ('first_only' | 'min_orders' | 'max_orders' | 'min_spent' | 'max_spent') or null.
	 */
	public static function customer_verdict( bool $first_only, ?int $min_orders, ?int $max_orders, ?float $min_spent, ?float $max_spent, int $order_count, float $total_spent ): ?string {
		if ( $first_only && $order_count > 0 ) {
			return 'first_only';
		}
		if ( null !== $min_orders && $order_count < $min_orders ) {
			return 'min_orders';
		}
		if ( null !== $max_orders && $order_count > $max_orders ) {
			return 'max_orders';
		}
		if ( null !== $min_spent && $total_spent < $min_spent ) {
			return 'min_spent';
		}
		if ( null !== $max_spent && $total_spent > $max_spent ) {
			return 'max_spent';
		}
		return null;
	}

	/* ---------------- product / category cart presence ---------------- */

	public static function product_block( \WC_Coupon $coupon ): ?string {
		$req_products    = self::id_list( $coupon->get_meta( Keys::REQ_PRODUCTS, true ) );
		$req_categories  = self::id_list( $coupon->get_meta( Keys::REQ_CATEGORIES, true ) );
		$excl_products   = self::id_list( $coupon->get_meta( Keys::EXCL_PRODUCTS, true ) );
		$excl_categories = self::id_list( $coupon->get_meta( Keys::EXCL_CATEGORIES, true ) );
		if ( array() === $req_products && array() === $req_categories && array() === $excl_products && array() === $excl_categories ) {
			return null;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return null;
		}

		$p_mode = 'all' === $coupon->get_meta( Keys::REQ_PRODUCTS_MODE, true ) ? 'all' : 'any';
		$c_mode = 'all' === $coupon->get_meta( Keys::REQ_CATEGORIES_MODE, true ) ? 'all' : 'any';

		$cart_products   = array();
		$cart_categories = array();
		foreach ( WC()->cart->get_cart_contents() as $item ) {
			$pid = (int) ( $item['product_id'] ?? 0 );
			$vid = (int) ( $item['variation_id'] ?? 0 );
			if ( $pid > 0 ) {
				$cart_products[] = $pid;
				if ( function_exists( 'wc_get_product_cat_ids' ) ) {
					foreach ( wc_get_product_cat_ids( $pid ) as $cid ) {
						$cart_categories[] = (int) $cid;
					}
				}
			}
			if ( $vid > 0 ) {
				$cart_products[] = $vid;
			}
		}

		$verdict = self::product_verdict( $req_products, $p_mode, $req_categories, $c_mode, $cart_products, $cart_categories );
		if ( null !== $verdict ) {
			return self::msg( $coupon, Keys::PRODUCT_MSG, __( 'The cart does not meet this coupon\'s product conditions.', 'moksa-coupons-for-woocommerce' ) );
		}

		$excluded = self::exclude_verdict( $excl_products, $excl_categories, $cart_products, $cart_categories );
		if ( null !== $excluded ) {
			return self::msg( $coupon, Keys::EXCL_MSG, __( 'The cart contains products that are not eligible for this coupon.', 'moksa-coupons-for-woocommerce' ) );
		}
		return null;
	}

	/**
	 * Pure verdict for exclusions: the cart must NOT contain any listed product /
	 * category. Returns 'excl_products' | 'excl_categories' | null.
	 *
	 * @param array<int,int> $excl_products
	 * @param array<int,int> $excl_categories
	 * @param array<int,int> $cart_products
	 * @param array<int,int> $cart_categories
	 */
	public static function exclude_verdict( array $excl_products, array $excl_categories, array $cart_products, array $cart_categories ): ?string {
		if ( array() !== $excl_products && array() !== array_intersect( $excl_products, $cart_products ) ) {
			return 'excl_products';
		}
		if ( array() !== $excl_categories && array() !== array_intersect( $excl_categories, $cart_categories ) ) {
			return 'excl_categories';
		}
		return null;
	}

	/**
	 * Pure verdict for product / category cart-presence. Returns the failing rule key
	 * ('products' | 'categories') or null when the cart satisfies both.
	 *
	 * @param array<int,int> $req_products
	 * @param string         $p_mode
	 * @param array<int,int> $req_categories
	 * @param string         $c_mode
	 * @param array<int,int> $cart_products
	 * @param array<int,int> $cart_categories
	 */
	public static function product_verdict( array $req_products, string $p_mode, array $req_categories, string $c_mode, array $cart_products, array $cart_categories ): ?string {
		if ( array() !== $req_products && ! self::set_matches( $req_products, $cart_products, $p_mode ) ) {
			return 'products';
		}
		if ( array() !== $req_categories && ! self::set_matches( $req_categories, $cart_categories, $c_mode ) ) {
			return 'categories';
		}
		return null;
	}

	/**
	 * 'all' = every required id present; 'any' = at least one present.
	 *
	 * @param array<int,int> $required
	 * @param array<int,int> $present
	 */
	private static function set_matches( array $required, array $present, string $mode ): bool {
		if ( 'all' === $mode ) {
			foreach ( $required as $r ) {
				if ( ! in_array( $r, $present, true ) ) {
					return false;
				}
			}
			return true;
		}
		return array() !== array_intersect( $required, $present );
	}

	/**
	 * Coerce a meta value (array of ids, or empty) into a clean, de-duplicated int list.
	 *
	 * @param mixed $value
	 * @return array<int,int>
	 */
	private static function id_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $v ) {
			$id = (int) $v;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/* ---------------- shipping region (destination country) ---------------- */

	public static function shipping_block( \WC_Coupon $coupon ): ?string {
		if ( 'yes' !== $coupon->get_meta( Keys::SHIPREGION_ENABLED, true ) ) {
			return null;
		}
		$countries = self::upper_list( $coupon->get_meta( Keys::SHIPREGION_COUNTRIES, true ) );
		if ( array() === $countries ) {
			return null;
		}
		if ( ! function_exists( 'WC' ) || ! ( WC()->customer instanceof \WC_Customer ) ) {
			return null;
		}
		$dest = strtoupper( (string) WC()->customer->get_shipping_country() );
		if ( '' === $dest ) {
			return null; // Unknown destination — don't block (matches WC's lenient cart behaviour).
		}
		$mode = 'disallow' === $coupon->get_meta( Keys::SHIPREGION_MODE, true ) ? 'disallow' : 'allow';
		if ( self::region_is_blocked( $mode, $countries, $dest ) ) {
			return self::msg( $coupon, Keys::SHIPREGION_MSG, __( 'This coupon does not apply to your shipping region.', 'moksa-coupons-for-woocommerce' ) );
		}
		return null;
	}

	/**
	 * Pure. allow: blocked when the destination is NOT listed. disallow: blocked when it IS.
	 *
	 * @param string            $mode      'allow' | 'disallow'.
	 * @param array<int,string> $countries Configured uppercase country codes.
	 * @param string            $dest      The cart's destination country code.
	 */
	public static function region_is_blocked( string $mode, array $countries, string $dest ): bool {
		$hit = in_array( $dest, $countries, true );
		return 'disallow' === $mode ? $hit : ! $hit;
	}

	/* ---------------- payment method (enforced at checkout) ---------------- */

	/**
	 * Classic checkout: WooCommerce posts the chosen gateway in $data['payment_method'].
	 *
	 * @param mixed $data   Posted checkout data.
	 * @param mixed $errors WP_Error bag.
	 */
	public static function validate_payment_classic( $data, $errors ): void {
		if ( ! is_array( $data ) || ! $errors instanceof \WP_Error ) {
			return;
		}
		$chosen  = isset( $data['payment_method'] ) ? sanitize_key( (string) $data['payment_method'] ) : '';
		$message = self::payment_violation_message( self::applied_coupon_codes(), $chosen );
		if ( null !== $message ) {
			$errors->add( 'moksafocou_payment', $message );
		}
	}

	/**
	 * Block / Store API checkout: the order is built (with its coupons + payment method) and
	 * this runs before payment is taken, so a RouteException cleanly aborts the order.
	 *
	 * @param mixed $order   WC_Order built from the request.
	 * @param mixed $request REST request (unused).
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the chosen gateway is not allowed.
	 */
	public static function validate_payment_store( $order, $request ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$chosen  = sanitize_key( (string) $order->get_payment_method() );
		$message = self::payment_violation_message( $order->get_coupon_codes(), $chosen );
		if ( null !== $message && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'moksafocou_payment', esc_html( wp_strip_all_tags( $message ) ), 400 );
		}
	}

	/**
	 * First payment-method violation among the applied coupons, or null.
	 *
	 * @param array<int,string> $codes Applied coupon codes.
	 */
	private static function payment_violation_message( array $codes, string $chosen ): ?string {
		foreach ( $codes as $code ) {
			$coupon = new \WC_Coupon( (string) $code );
			if ( 0 === $coupon->get_id() ) {
				continue;
			}
			$message = self::payment_block( $coupon, $chosen );
			if ( null !== $message ) {
				return $message;
			}
		}
		return null;
	}

	public static function payment_block( \WC_Coupon $coupon, string $chosen ): ?string {
		if ( 'yes' !== $coupon->get_meta( Keys::PAYMENT_ENABLED, true ) ) {
			return null;
		}
		$methods = self::string_list( $coupon->get_meta( Keys::PAYMENT_METHODS, true ) );
		if ( array() === $methods || '' === $chosen ) {
			return null;
		}
		$mode = 'disallow' === $coupon->get_meta( Keys::PAYMENT_MODE, true ) ? 'disallow' : 'allow';
		if ( self::payment_is_blocked( $mode, $methods, $chosen ) ) {
			return self::msg( $coupon, Keys::PAYMENT_MSG, __( 'This coupon does not apply to your selected payment method.', 'moksa-coupons-for-woocommerce' ) );
		}
		return null;
	}

	/**
	 * Pure. allow: blocked when the gateway is NOT listed. disallow: blocked when it IS.
	 *
	 * @param string            $mode    'allow' | 'disallow'.
	 * @param array<int,string> $methods Configured gateway ids.
	 * @param string            $chosen  The chosen gateway id.
	 */
	public static function payment_is_blocked( string $mode, array $methods, string $chosen ): bool {
		$hit = in_array( $chosen, $methods, true );
		return 'disallow' === $mode ? $hit : ! $hit;
	}

	/** @return array<int,string> */
	private static function applied_coupon_codes(): array {
		if ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			return WC()->cart->get_applied_coupons();
		}
		return array();
	}

	/**
	 * @param mixed $value
	 * @return array<int,string>
	 */
	private static function upper_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $v ) {
			$code = strtoupper( (string) $v );
			if ( '' !== $code && ! in_array( $code, $out, true ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $value
	 * @return array<int,string>
	 */
	private static function string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $value ), static fn( string $v ): bool => '' !== $v ) );
	}

	/* ---------------- day-of-week / time-of-day window (site timezone) ---------------- */

	public static function daytime_block( \WC_Coupon $coupon ): ?string {
		if ( 'yes' !== $coupon->get_meta( Keys::DAYTIME_ENABLED, true ) ) {
			return null;
		}
		$days  = self::parse_weekdays( $coupon->get_meta( Keys::DAYTIME_DAYS, true ) );
		$start = self::hhmm_to_min( (string) $coupon->get_meta( Keys::DAYTIME_START, true ) );
		$end   = self::hhmm_to_min( (string) $coupon->get_meta( Keys::DAYTIME_END, true ) );
		if ( array() === $days && null === $start && null === $end ) {
			return null;
		}

		$now     = SiteTime::now_parts();
		$verdict = self::daytime_verdict( $days, $start, $end, $now['weekday'], $now['minutes'] );
		if ( null === $verdict ) {
			return null;
		}
		return self::msg( $coupon, Keys::DAYTIME_MSG, __( 'This coupon is currently outside its usable time slot.', 'moksa-coupons-for-woocommerce' ) );
	}

	/**
	 * Pure verdict for the day-of-week / time-of-day window. Returns 'day', 'time' or
	 * null. The window supports overnight spans (start > end, e.g. 22:00–02:00). A null
	 * start/end means that side is unbounded.
	 *
	 * @param array<int,int> $allowed_days Allowed weekdays (0=Sun..6=Sat); empty = any.
	 * @param int|null       $start_min    Window start (minutes-of-day) or null.
	 * @param int|null       $end_min      Window end (minutes-of-day) or null.
	 * @param int            $weekday      Current weekday (0=Sun..6=Sat).
	 * @param int            $minutes      Current minutes-of-day.
	 */
	public static function daytime_verdict( array $allowed_days, ?int $start_min, ?int $end_min, int $weekday, int $minutes ): ?string {
		if ( array() !== $allowed_days && ! in_array( $weekday, $allowed_days, true ) ) {
			return 'day';
		}
		if ( null !== $start_min && null !== $end_min ) {
			$in = $start_min <= $end_min
				? ( $minutes >= $start_min && $minutes <= $end_min )
				: ( $minutes >= $start_min || $minutes <= $end_min );
			if ( ! $in ) {
				return 'time';
			}
		} elseif ( null !== $start_min && $minutes < $start_min ) {
			return 'time';
		} elseif ( null !== $end_min && $minutes > $end_min ) {
			return 'time';
		}
		return null;
	}

	/**
	 * Coerce a stored weekday list into a clean 0–6 int set. Distinct from id_list():
	 * weekday 0 (Sunday) is VALID and must NOT be dropped as "empty".
	 *
	 * @param mixed $value
	 * @return array<int,int>
	 */
	public static function parse_weekdays( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $d ) {
			$n = (int) $d;
			if ( $n >= 0 && $n <= 6 && ! in_array( $n, $out, true ) ) {
				$out[] = $n;
			}
		}
		return $out;
	}

	/**
	 * Parse 'HH:MM' to minutes-of-day (0–1439), or null when empty / invalid.
	 */
	public static function hhmm_to_min( string $value ): ?int {
		$value = trim( $value );
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m ) ) {
			return null;
		}
		return ( (int) $m[1] * 60 ) + (int) $m[2];
	}

	private static function customer_default_message( string $verdict ): string {
		switch ( $verdict ) {
			case 'first_only':
				return __( 'This coupon is only for first-time customers.', 'moksa-coupons-for-woocommerce' );
			case 'min_orders':
			case 'max_orders':
				return __( 'Your order history does not qualify you for this coupon.', 'moksa-coupons-for-woocommerce' );
			default:
				return __( 'Your accumulated spend does not qualify you for this coupon.', 'moksa-coupons-for-woocommerce' );
		}
	}

	/** @param mixed $value */
	private static function int_or_null( $value ): ?int {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' === $value ? null : (int) $value;
	}

	/** @param mixed $value */
	private static function float_or_null( $value ): ?float {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' === $value ? null : (float) $value;
	}

	private static function msg( \WC_Coupon $coupon, string $key, string $fallback ): string {
		$message = (string) $coupon->get_meta( $key, true );
		return '' !== trim( $message ) ? $message : $fallback;
	}
}
