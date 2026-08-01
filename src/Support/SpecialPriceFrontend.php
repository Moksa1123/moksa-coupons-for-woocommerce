<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Shared runtime for the three set_price special-price coupon types (BOGO / Mix & Match / Nth-item).
 * Each turns its coupon into a real discount by lowering reward-line prices in the cart via set_price
 * (never WC's coupon-amount engine), works on classic + Block/Store-API, keeps the 0-nominal coupon
 * applied (no get_discount_amount filter), and enforces one such coupon per cart.
 *
 * The three module Frontends were ~90% byte-identical; everything except the engine (apply()) and the
 * membership / notice wording lives here. A using class supplies its meta constants + these hooks:
 *   - coupon_type():   the coupon TYPE string it owns (e.g. BogoMeta::TYPE)
 *   - css_slug():      cart-markup slug segment ("bogo" / "mixmatch" / "nth")
 *   - priority_filter(): its before_calculate_totals priority filter name
 *   - apply():         the module-specific price engine (must set self::$price_display / $notices)
 * and two private consts ORDER_META / COUPON_LINE_META (the order + coupon-line meta keys).
 *
 * Anti-compounding: base_price() memoises each product's untouched catalog price per cart-item-key,
 * so repeated before_calculate_totals passes never read (and re-discount) an already-lowered price.
 */
trait SpecialPriceFrontend {

	/** @var array<string,array<string,array{name:string,quantity:int,total:float}>> code => key => savings record. */
	private static array $price_display = array();

	/** @var array<string,string> code => eligibility-notice text. */
	private static array $notices = array();

	/** @var array<string,float> Per cart-item-key base-price memo (anti-compounding). */
	private static array $base_memo = array();

	/** The set_price coupon TYPE this module owns (e.g. BogoMeta::TYPE). */
	abstract protected static function coupon_type(): string;

	/** Cart-markup slug segment used in the hint/summary CSS classes ("bogo" / "mixmatch" / "nth"). */
	abstract protected static function css_slug(): string;

	/** The apply()-priority filter hook name (e.g. 'moksafocou_bogo_priority'). */
	abstract protected static function priority_filter(): string;

	/**
	 * The price engine: read config, lower reward-line prices via set_price, and populate
	 * self::$price_display / self::$notices. Module-specific (different calc + membership rules).
	 *
	 * @param mixed $cart WC_Cart passed by the hook.
	 */
	abstract public static function apply( $cart ): void;

	public static function boot(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- priority_filter() is an abstract that each concrete module returns a moksafocou_-prefixed hook name from (moksafocou_bogo_priority / _nthitem_ / _mixmatch_).
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'apply' ), (int) apply_filters( static::priority_filter(), 11 ) );
		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'is_valid' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( self::class, 'coupon_html' ), 10, 3 );
		// set_price savings never appear as a coupon discount line, so feed them into the shared
		// savings summary when that module is on.
		add_filter( 'moksafocou_cart_savings_total', array( self::class, 'add_to_savings' ), 10, 1 );
		// Restore the reward line's pre-discount subtotal on the order so the saving shows
		// transparently (set_price lowered the unit price). Fires on classic + Store API.
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'order_line_subtotal' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_order_processed' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_block_order' ), 10, 1 );
	}

	/**
	 * The product's catalog price, unaffected by our own set_price (which sets only the 'price'
	 * prop). Memoised per cart-item-key: regular/sale prices are never mutated by set_price, but the
	 * last-resort get_price() fallback WOULD read our mutated price on a later before_calculate_totals
	 * pass and compound — the memo captures it on the first (untouched) pass so the base stays stable.
	 */
	private static function base_price( string $key, \WC_Product $product ): float {
		if ( isset( self::$base_memo[ $key ] ) ) {
			return self::$base_memo[ $key ];
		}
		$base = $product->is_on_sale() ? $product->get_sale_price() : $product->get_regular_price();
		if ( '' === $base || ! is_numeric( $base ) ) {
			$base = $product->get_regular_price();
		}
		if ( '' === $base || ! is_numeric( $base ) ) {
			$base = $product->get_price();
		}
		$value                   = max( 0.0, (float) $base );
		self::$base_memo[ $key ] = $value;
		return $value;
	}

	/**
	 * Apply the plan's blended prices to the reward lines and build the display/savings record.
	 * The engine (apply()) calls this after computing $plan['rewards'].
	 *
	 * @param \WC_Cart                                                                  $cart
	 * @param array<string,array{blended_price:float,disc_qty:int,unit_discount:float}> $rewards
	 * @return array<string,array{name:string,quantity:int,total:float}>
	 */
	private static function set_reward_prices( \WC_Cart $cart, array $rewards ): array {
		$display = array();
		foreach ( $rewards as $key => $reward ) {
			$item = $cart->get_cart_item( (string) $key );
			if ( ! $item || ! isset( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}
			$item['data']->set_price( $reward['blended_price'] );
			$display[ (string) $key ] = array(
				'name'     => $item['data']->get_name(),
				'quantity' => (int) $reward['disc_qty'],
				'total'    => (float) $reward['unit_discount'] * (int) $reward['disc_qty'],
			);
		}
		return $display;
	}

	private static function reset(): void {
		self::$price_display = array();
		self::$notices       = array();
	}

	/**
	 * @param mixed $valid
	 * @param mixed $coupon
	 * @return mixed
	 * @throws \Exception When another special-price coupon already holds the cart (one per cart).
	 */
	public static function is_valid( $valid, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon || ! $coupon->is_type( static::coupon_type() ) ) {
			return $valid;
		}
		// At most one set_price special-price coupon (BOGO / Nth-item / Mix & Match) per cart, so
		// overlapping item sets cannot clobber each other's prices. 0 nominal discount never throws.
		SpecialPriceTypes::assert_single( $coupon );
		return $valid;
	}

	/**
	 * @param mixed $html
	 * @param mixed $coupon
	 * @param mixed $discount_html
	 * @return mixed
	 */
	public static function coupon_html( $html, $coupon, $discount_html = '' ) {
		if ( ! $coupon instanceof \WC_Coupon || ! $coupon->is_type( static::coupon_type() ) ) {
			return $html;
		}
		$code = $coupon->get_code();

		// Strip the cosmetic $0.00 amount when this coupon contributes no WC discount line.
		if ( is_string( $html ) && '' !== (string) $discount_html && function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			$amount = (float) WC()->cart->get_coupon_discount_amount( $code, WC()->cart->display_cart_ex_tax );
			if ( 0.0 === $amount ) {
				$html = str_replace( $discount_html, '', $html );
			}
		}

		$summary = self::summary_html( $code );
		if ( '' !== $summary ) {
			return $html . $summary;
		}
		if ( isset( self::$notices[ $code ] ) ) {
			return $html . '<div class="moksafocou-' . static::css_slug() . '-hint" style="margin:6px 0 0;font-size:.9em;color:#996800;">' . esc_html( self::$notices[ $code ] ) . '</div>';
		}
		return $html;
	}

	/**
	 * Contribute this request's set_price savings (which never appear as a coupon discount line) to
	 * the shared savings-summary total.
	 *
	 * @param mixed $total Running savings total.
	 * @return float
	 */
	public static function add_to_savings( $total ): float {
		$extra = 0.0;
		foreach ( self::$price_display as $records ) {
			foreach ( $records as $record ) {
				$extra += (float) ( $record['total'] ?? 0 );
			}
		}
		return (float) $total + $extra;
	}

	private static function summary_html( string $code ): string {
		if ( empty( self::$price_display[ $code ] ) ) {
			return '';
		}
		$rows = '';
		foreach ( self::$price_display[ $code ] as $record ) {
			$rows .= sprintf(
				'<li>%1$s × %2$d: −%3$s</li>',
				esc_html( $record['name'] ),
				(int) $record['quantity'],
				wp_kses_post( wc_price( (float) $record['total'] ) )
			);
		}
		return '<ul class="moksafocou-' . static::css_slug() . '-summary" style="margin:6px 0 0;font-size:.9em;list-style:none;padding:0;">' . $rows . '</ul>';
	}

	/**
	 * Restore a reward line's pre-discount subtotal to its original catalog price so the order
	 * transparently shows the saving (original → discounted) instead of subtotal == total.
	 *
	 * @param mixed $item          WC_Order_Item_Product.
	 * @param mixed $cart_item_key Cart item key.
	 * @param mixed $values        Cart item.
	 */
	public static function order_line_subtotal( $item, $cart_item_key, $values ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}
		foreach ( self::$price_display as $records ) {
			if ( isset( $records[ (string) $cart_item_key ] ) ) {
				$saving = (float) ( $records[ (string) $cart_item_key ]['total'] ?? 0 );
				if ( $saving > 0.0 ) {
					$item->set_subtotal( (float) $item->get_subtotal() + $saving );
				}
				return;
			}
		}
	}

	/**
	 * @param mixed $order_id
	 */
	public static function on_order_processed( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			self::persist( $order );
		}
	}

	/**
	 * @param mixed $order
	 */
	public static function on_block_order( $order ): void {
		if ( $order instanceof \WC_Order ) {
			self::persist( $order );
		}
	}

	private static function persist( \WC_Order $order ): void {
		if ( array() === self::$price_display ) {
			return;
		}
		$order->update_meta_data( self::ORDER_META, array_values( self::$price_display ) );

		foreach ( $order->get_items( 'coupon' ) as $line ) {
			if ( ! $line instanceof \WC_Order_Item_Coupon ) {
				continue;
			}
			$code = $line->get_code();
			if ( isset( self::$price_display[ $code ] ) ) {
				$total = array_sum( array_column( self::$price_display[ $code ], 'total' ) );
				$line->update_meta_data( self::COUPON_LINE_META, wc_format_decimal( (string) $total ) );
				$line->save();
			}
		}
		$order->save();
		self::reset();
	}
}
