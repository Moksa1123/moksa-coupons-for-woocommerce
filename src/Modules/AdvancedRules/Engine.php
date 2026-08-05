<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\AdvancedRules;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\Rules;
use Moksafocou\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces the advanced rule-builder tree. Most rules resolve at cart-apply time on
 * woocommerce_coupon_is_valid; payment_method rules are deferred to checkout (the gateway is
 * unknown earlier), where the full tree is re-evaluated with the chosen gateway. The SAME
 * context builder feeds both points, so a non-payment rule can never pass at the cart yet
 * fail at checkout on a boundary difference.
 *
 * Runs at priority 12 — after the simple CouponConditions validator (10) — and is a separate,
 * independently-gated module, so it carries its own error-message plumbing (mirroring the
 * conditions validator) rather than sharing state with it.
 */
final class Engine {

	/** @var array<int,string> coupon id => customer-facing message, for the Block error filter. */
	private static array $last_error = array();

	/** @var array<int,array{count:int,spent:float}> Per-request memo of customer paid-order history. */
	private static array $history = array();

	public static function boot(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'validate_cart' ), 12, 2 );
		add_filter( 'woocommerce_coupon_error', array( self::class, 'block_error' ), 12, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'validate_checkout_classic' ), 12, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'validate_checkout_store' ), 12, 2 );
	}

	/* ---------------- cart-apply enforcement ---------------- */

	/**
	 * @param mixed $valid
	 * @param mixed $coupon
	 * @return mixed
	 */
	public static function validate_cart( $valid, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( Keys::RULES_ENABLED, true ) ) {
			return $valid;
		}
		$set = Rules::parse( (string) $coupon->get_meta( Keys::RULES, true ) );
		if ( array() === $set['groups'] ) {
			return $valid;
		}
		if ( ! Rules::evaluate( $set, self::context( null, $set, $coupon ), true ) ) {
			self::fail( $coupon->get_id(), self::message( $coupon ) );
		}
		return $valid;
	}

	/**
	 * @throws \Exception Always — carries the customer-facing message.
	 */
	private static function fail( int $id, string $message ): void {
		self::$last_error[ $id ] = $message;
		throw new \Exception( wp_kses_post( self::entity_encode( $message ) ) );
	}

	/** Block checkout strips message HTML; encode entities so it survives (mirrors Validator). */
	private static function entity_encode( string $message ): string {
		$store_api = isset( $_SERVER['REQUEST_URI'] )
			&& false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '/wc/store/' );
		$wc_94     = defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.4.0', '>=' );
		if ( ( $store_api || $wc_94 ) && function_exists( 'htmlentities2' ) ) {
			return htmlentities2( $message );
		}
		return $message;
	}

	/**
	 * @param mixed $message
	 * @param mixed $code
	 * @param mixed $coupon
	 * @return mixed
	 */
	public static function block_error( $message, $code, $coupon ) {
		if ( $coupon instanceof \WC_Coupon && isset( self::$last_error[ $coupon->get_id() ] ) ) {
			return self::$last_error[ $coupon->get_id() ];
		}
		return $message;
	}

	/* ---------------- checkout enforcement (payment now known) ---------------- */

	/**
	 * @param mixed $data
	 * @param mixed $errors
	 */
	public static function validate_checkout_classic( $data, $errors ): void {
		if ( ! is_array( $data ) || ! $errors instanceof \WP_Error ) {
			return;
		}
		$chosen  = isset( $data['payment_method'] ) ? sanitize_key( (string) $data['payment_method'] ) : '';
		$message = self::checkout_violation( self::applied_codes(), $chosen );
		if ( null !== $message ) {
			$errors->add( 'moksafocou_rules', $message );
		}
	}

	/**
	 * @param mixed $order
	 * @param mixed $request
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When a rule set fails at checkout.
	 */
	public static function validate_checkout_store( $order, $request ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$chosen  = sanitize_key( (string) $order->get_payment_method() );
		$message = self::checkout_violation( $order->get_coupon_codes(), $chosen );
		if ( null !== $message && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'moksafocou_rules', esc_html( wp_strip_all_tags( $message ) ), 400 );
		}
	}

	/**
	 * First rule-set violation among the applied coupons (full evaluation, payment known).
	 *
	 * @param array<int,string> $codes
	 */
	private static function checkout_violation( array $codes, string $chosen ): ?string {
		foreach ( $codes as $code ) {
			$coupon = new \WC_Coupon( (string) $code );
			if ( 0 === $coupon->get_id() || 'yes' !== $coupon->get_meta( Keys::RULES_ENABLED, true ) ) {
				continue;
			}
			$set = Rules::parse( (string) $coupon->get_meta( Keys::RULES, true ) );
			if ( array() !== $set['groups'] && ! Rules::evaluate( $set, self::context( $chosen, $set, $coupon ), false ) ) {
				return self::message( $coupon );
			}
		}
		return null;
	}

	private static function message( \WC_Coupon $coupon ): string {
		$message = (string) $coupon->get_meta( Keys::RULES_MSG, true );
		return '' !== trim( $message ) ? $message : __( 'This coupon does not meet the usage conditions.', 'moksa-coupons-for-woocommerce' );
	}

	/** @return array<int,string> */
	private static function applied_codes(): array {
		if ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			return WC()->cart->get_applied_coupons();
		}
		return array();
	}

	/* ---------------- context ---------------- */

	/** Sentinel "no data" for the hours-since metrics, so a "within N hours" (lte) test fails. */
	private const HOURS_NONE = 1.0e12;

	/**
	 * Build the evaluation context from WooCommerce. $payment is null at cart-apply time
	 * (deferred) and the chosen gateway id at checkout. The parsed rule $set drives which
	 * expensive fields (purchase history, shipping zone, hours-since, custom taxonomy / meta)
	 * are computed — only when a rule actually needs them.
	 *
	 * @param string|null                    $payment Chosen gateway id at checkout, or null at cart time.
	 * @param array{groups:array<int,mixed>} $set     The parsed rule set.
	 * @return array<string,mixed>
	 */
	private static function context( ?string $payment, array $set = array(), ?\WC_Coupon $coupon = null ): array {
		$types = Rules::types_used( $set );
		$now   = SiteTime::now_parts();
		$parts = array(
			'subtotal'               => 0.0,
			'qty'                    => 0,
			'coupon_usage_count'     => $coupon instanceof \WC_Coupon ? (int) $coupon->get_usage_count() : 0,
			'weight'                 => 0.0,
			'products'               => array(),
			'categories'             => array(),
			'product_qty'            => array(),
			'stock_statuses'         => array(),
			'applied_coupons'        => array(),
			'country'                => '',
			'shipping_zone'          => '',
			'roles'                  => self::current_roles(),
			'order_count'            => 0,
			'total_spent'            => 0.0,
			'ordered_products'       => array(),
			'ordered_categories'     => array(),
			'category_spent'         => array(),
			'hours_since_registered' => self::HOURS_NONE,
			'hours_since_last_order' => self::HOURS_NONE,
			'taxonomy_terms'         => array(),
			'user_meta'              => array(),
			'cart_item_meta'         => array(),
			'weekday'                => (string) $now['weekday'],
			'minutes'                => $now['minutes'],
			'now'                    => time(),
			'payment'                => $payment,
		);

		if ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			$cart                     = WC()->cart;
			$parts['subtotal']        = (float) $cart->get_subtotal();
			$parts['qty']             = (int) $cart->get_cart_contents_count();
			$parts['weight']          = (float) $cart->get_cart_contents_weight();
			$parts['applied_coupons'] = array_map( 'strtolower', (array) $cart->get_applied_coupons() );
			foreach ( $cart->get_cart_contents() as $item ) {
				$pid = (int) ( $item['product_id'] ?? 0 );
				$vid = (int) ( $item['variation_id'] ?? 0 );
				$qty = (int) ( $item['quantity'] ?? 0 );
				if ( $pid > 0 ) {
					$parts['products'][]          = $pid;
					$parts['product_qty'][ $pid ] = ( $parts['product_qty'][ $pid ] ?? 0 ) + $qty;
					if ( function_exists( 'wc_get_product_cat_ids' ) ) {
						foreach ( wc_get_product_cat_ids( $pid ) as $cid ) {
							$parts['categories'][] = (int) $cid;
						}
					}
				}
				if ( $vid > 0 ) {
					$parts['products'][]          = $vid;
					$parts['product_qty'][ $vid ] = ( $parts['product_qty'][ $vid ] ?? 0 ) + $qty;
				}
				$product = $item['data'] ?? null;
				if ( $product instanceof \WC_Product ) {
					$status = (string) $product->get_stock_status();
					if ( '' !== $status && ! in_array( $status, $parts['stock_statuses'], true ) ) {
						$parts['stock_statuses'][] = $status;
					}
				}
			}
		}
		if ( function_exists( 'WC' ) && WC()->customer instanceof \WC_Customer ) {
			$parts['country'] = strtoupper( (string) WC()->customer->get_shipping_country() );
		}

		$history              = self::customer_history();
		$parts['order_count'] = $history['count'];
		$parts['total_spent'] = $history['spent'];

		if ( in_array( 'shipping_zone', $types, true ) ) {
			$parts['shipping_zone'] = self::matched_zone();
		}
		if ( array() !== array_intersect( $types, array( 'ordered_product', 'ordered_category', 'category_spent' ) ) ) {
			$ph                          = self::purchase_history();
			$parts['ordered_products']   = $ph['products'];
			$parts['ordered_categories'] = $ph['categories'];
			$parts['category_spent']     = $ph['category_spent'];
		}
		if ( in_array( 'hours_since_registered', $types, true ) ) {
			$parts['hours_since_registered'] = self::hours_since_registered();
		}
		if ( in_array( 'hours_since_last_order', $types, true ) ) {
			$parts['hours_since_last_order'] = self::hours_since_last_order();
		}

		$refs = Rules::value_refs( $set );
		if ( array() !== $refs['taxonomies'] ) {
			$parts['taxonomy_terms'] = self::taxonomy_terms( $parts['products'], $refs['taxonomies'] );
		}
		if ( array() !== $refs['user_meta'] ) {
			$parts['user_meta'] = self::user_meta_map( $refs['user_meta'] );
		}
		if ( array() !== $refs['cart_meta'] ) {
			$parts['cart_item_meta'] = self::cart_item_meta_map( $refs['cart_meta'] );
		}

		return $parts;
	}

	/**
	 * For each referenced custom taxonomy, the set of term ids present across the cart's
	 * products.
	 *
	 * @param array<int,int>    $products
	 * @param array<int,string> $taxonomies
	 * @return array<string,array<int,int>>
	 */
	private static function taxonomy_terms( array $products, array $taxonomies ): array {
		$out = array();
		if ( ! function_exists( 'wp_get_post_terms' ) ) {
			return $out;
		}
		foreach ( $taxonomies as $tax ) {
			$terms = array();
			foreach ( array_unique( $products ) as $pid ) {
				$ids = wp_get_post_terms( (int) $pid, $tax, array( 'fields' => 'ids' ) );
				if ( is_array( $ids ) ) {
					foreach ( $ids as $tid ) {
						$tid = (int) $tid;
						if ( ! in_array( $tid, $terms, true ) ) {
							$terms[] = $tid;
						}
					}
				}
			}
			$out[ $tax ] = $terms;
		}
		return $out;
	}

	/**
	 * The current user's value for each referenced user-meta key.
	 *
	 * @param array<int,string> $keys
	 * @return array<string,string>
	 */
	private static function user_meta_map( array $keys ): array {
		$out     = array();
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return $out;
		}
		foreach ( $keys as $key ) {
			$out[ $key ] = (string) get_user_meta( $user_id, $key, true );
		}
		return $out;
	}

	/**
	 * For each referenced cart-item-meta key, the set of values present across cart items.
	 * Covers plugins that store custom data as a top-level cart-item array key.
	 *
	 * @param array<int,string> $keys
	 * @return array<string,array<int,string>>
	 */
	private static function cart_item_meta_map( array $keys ): array {
		$out = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = array();
		}
		if ( ! function_exists( 'WC' ) || ! ( WC()->cart instanceof \WC_Cart ) ) {
			return $out;
		}
		foreach ( WC()->cart->get_cart_contents() as $item ) {
			foreach ( $keys as $key ) {
				if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ) {
					$value = (string) $item[ $key ];
					if ( ! in_array( $value, $out[ $key ], true ) ) {
						$out[ $key ][] = $value;
					}
				}
			}
		}
		return $out;
	}

	/** The shipping zone id matching the cart's destination, or '' (no zone / no cart). */
	private static function matched_zone(): string {
		if ( ! function_exists( 'WC' ) || ! ( WC()->cart instanceof \WC_Cart ) || ! class_exists( '\WC_Shipping_Zones' ) ) {
			return '';
		}
		foreach ( WC()->cart->get_shipping_packages() as $package ) {
			$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );
			if ( $zone ) {
				return (string) $zone->get_id();
			}
		}
		return '';
	}

	/** @var array{products:array<int,int>,categories:array<int,int>,category_spent:array<int,float>}|null */
	private static ?array $purchase = null;

	/**
	 * Upper bound on how many of a customer's paid orders the rule engine loads into memory
	 * when summing spend / collecting purchased products. High enough that it rarely bites a
	 * real shopper, bounded so a pathological account can't exhaust memory; filterable for
	 * stores that genuinely need a larger window.
	 */
	private static function order_scan_limit(): int {
		return max( 1, (int) apply_filters( 'moksafocou_customer_order_scan_limit', 500 ) );
	}

	/**
	 * The current customer's purchased product ids, category ids and per-category spend (paid
	 * orders). Memoized per request — one scan covers all three derived fields.
	 *
	 * @return array{products:array<int,int>,categories:array<int,int>,category_spent:array<int,float>}
	 */
	private static function purchase_history(): array {
		if ( null !== self::$purchase ) {
			return self::$purchase;
		}
		$products   = array();
		$categories = array();
		$cat_spent  = array();
		$user_id    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id > 0 && function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_is_paid_statuses' ) ) {
			$orders = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'status'      => wc_get_is_paid_statuses(),
					'type'        => 'shop_order',
					'limit'       => self::order_scan_limit(),
					'return'      => 'objects',
				)
			);
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				foreach ( $order->get_items() as $item ) {
					if ( ! $item instanceof \WC_Order_Item_Product ) {
						continue;
					}
					$pid  = (int) $item->get_product_id();
					$line = (float) $item->get_total();
					if ( $pid > 0 && ! in_array( $pid, $products, true ) ) {
						$products[] = $pid;
					}
					if ( $pid > 0 && function_exists( 'wc_get_product_cat_ids' ) ) {
						foreach ( wc_get_product_cat_ids( $pid ) as $cid ) {
							$cid = (int) $cid;
							if ( ! in_array( $cid, $categories, true ) ) {
								$categories[] = $cid;
							}
							$cat_spent[ $cid ] = ( $cat_spent[ $cid ] ?? 0 ) + $line;
						}
					}
				}
			}
		}
		self::$purchase = array(
			'products'       => $products,
			'categories'     => $categories,
			'category_spent' => $cat_spent,
		);
		return self::$purchase;
	}

	private static function hours_since_registered(): float {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return self::HOURS_NONE;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_registered ) ) {
			return self::HOURS_NONE;
		}
		$ts = strtotime( $user->user_registered . ' UTC' );
		return $ts ? max( 0.0, ( time() - $ts ) / 3600 ) : self::HOURS_NONE;
	}

	private static function hours_since_last_order(): float {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_is_paid_statuses' ) ) {
			return self::HOURS_NONE;
		}
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => wc_get_is_paid_statuses(),
				'type'        => 'shop_order',
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);
		if ( empty( $orders ) || ! $orders[0] instanceof \WC_Order ) {
			return self::HOURS_NONE;
		}
		$date = $orders[0]->get_date_created();
		return $date ? max( 0.0, ( time() - $date->getTimestamp() ) / 3600 ) : self::HOURS_NONE;
	}

	/** @return array<int,string> */
	private static function current_roles(): array {
		if ( function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			if ( $user && $user->ID ) {
				return (array) $user->roles;
			}
		}
		return array( 'guest' );
	}

	/** @return array{count:int,spent:float} */
	private static function customer_history(): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( isset( self::$history[ $user_id ] ) ) {
			return self::$history[ $user_id ];
		}
		$count = 0;
		$spent = 0.0;
		if ( $user_id > 0 && function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_is_paid_statuses' ) ) {
			// paginate => true gives the EXACT total order count in one cheap query (no full
			// load), while the object scan that sums spend is bounded by the filterable cap so a
			// customer with thousands of orders never loads them all into memory at once.
			$result = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'status'      => wc_get_is_paid_statuses(),
					'type'        => 'shop_order',
					'limit'       => self::order_scan_limit(),
					'paginate'    => true,
					'return'      => 'objects',
				)
			);
			$orders = ( is_object( $result ) && isset( $result->orders ) ) ? $result->orders : array();
			$count  = ( is_object( $result ) && isset( $result->total ) ) ? (int) $result->total : count( $orders );
			foreach ( $orders as $order ) {
				if ( $order instanceof \WC_Order ) {
					$spent += (float) $order->get_total();
				}
			}
		}
		self::$history[ $user_id ] = array(
			'count' => $count,
			'spent' => $spent,
		);
		return self::$history[ $user_id ];
	}
}
