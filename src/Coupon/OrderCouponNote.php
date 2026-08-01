<?php

declare( strict_types=1 );

namespace Moksafocou\Coupon;

use Moksafocou\Plugin;
use Moksafocou\Coupon\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Writes ONE private order note summarising the plugin's special-effect coupons (BOGO,
 * shipping override, free gift) whenever an order is placed. These effects are invisible
 * in WooCommerce's own order data (their discount is 0 — the saving is a lowered line
 * price / shipping / an added gift), so the note gives the merchant an audit trail.
 *
 * Plain percent/fixed coupons are deliberately skipped — WooCommerce already records
 * those in the order's coupon lines. Runs at priority 20, after the BOGO module persists
 * its per-line saving (priority 10), so that figure is available here.
 */
final class OrderCouponNote {

	/** Coupon-line meta the BOGO module records (its real saving; WC discount is 0). */
	private const BOGO_LINE_META = '_moksafocou_bogo_coupon_discount';
	/** Guard so the note is written once per order. */
	private const DONE_META = '_moksafocou_effect_note_added';

	public static function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_classic' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_block' ), 20, 1 );
	}

	/**
	 * @param mixed $order_id
	 */
	public static function on_classic( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			self::maybe_note( $order );
		}
	}

	/**
	 * @param mixed $order
	 */
	public static function on_block( $order ): void {
		if ( $order instanceof \WC_Order ) {
			self::maybe_note( $order );
		}
	}

	private static function maybe_note( \WC_Order $order ): void {
		if ( 'yes' === $order->get_meta( self::DONE_META ) ) {
			return;
		}
		$lines = self::summary_lines( $order );
		if ( empty( $lines ) ) {
			return;
		}
		// add_order_note() defaults to a private note (not customer-facing).
		$order->add_order_note( __( 'Moksa coupon effect', 'moksafocou' ) . ":\n• " . implode( "\n• ", $lines ) );
		$order->update_meta_data( self::DONE_META, 'yes' );
		$order->save();
	}

	/**
	 * One human line per special-effect coupon on the order, based on what actually
	 * happened (BOGO per-line saving present / gift product in the order / shipping
	 * module active), so a coupon whose effect did not fire is not reported.
	 *
	 * @return array<int,string>
	 */
	private static function summary_lines( \WC_Order $order ): array {
		$ship_on = Plugin::instance()->modules()->is_enabled( 'shipping' );
		$gift_on = Plugin::instance()->modules()->is_enabled( 'freegift' );
		$symbol  = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';

		$lines      = array();
		$ship_shown = false; // The shipping total is order-wide; report it once, not per coupon.
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}
			$code   = strtoupper( (string) $item->get_code() );
			$coupon = new \WC_Coupon( (string) $item->get_code() );

			// BOGO — evidence: the per-line saving the BOGO runtime recorded.
			$bogo_saving = $item->get_meta( self::BOGO_LINE_META );
			if ( is_numeric( $bogo_saving ) && (float) $bogo_saving > 0 ) {
				$lines[] = sprintf(
					/* translators: 1: coupon code, 2: formatted saving. */
					__( 'Buy X Get Y "%1$s": gift offer, save %2$s', 'moksafocou' ),
					$code,
					self::money( (float) $bogo_saving )
				);
				continue;
			}

			if ( 0 === $coupon->get_id() ) {
				continue;
			}

			// Shipping override — only when its module is live (else no rewrite happened), and
			// only once: the order's final shipping total is the same regardless of how many
			// shipping coupons stacked, so a second line would duplicate the same figure.
			$ship_mode = (string) $coupon->get_meta( Keys::SHIP_MODE );
			if ( $ship_on && ! $ship_shown && '' !== $ship_mode && 'none' !== $ship_mode ) {
				$lines[] = sprintf(
					/* translators: 1: coupon code, 2: effect description, 3: this order's shipping. */
					__( 'Shipping override "%1$s": %2$s (order shipping %3$s)', 'moksafocou' ),
					$code,
					self::ship_label( $ship_mode, (string) $coupon->get_meta( Keys::SHIP_VALUE ), $symbol ),
					self::money( (float) $order->get_shipping_total() )
				);
				$ship_shown = true;
			}

			// Free gift — evidence: the configured gift product is in the order.
			if ( $gift_on && 'yes' === $coupon->get_meta( Keys::GIFT_ENABLED ) ) {
				$gift_id = (int) $coupon->get_meta( Keys::GIFT_PRODUCT_ID );
				if ( $gift_id > 0 && self::order_has_product( $order, $gift_id ) ) {
					$product = wc_get_product( $gift_id );
					$lines[] = sprintf(
						/* translators: 1: coupon code, 2: gift product name. */
						__( 'Free gift "%1$s": gift %2$s added', 'moksafocou' ),
						$code,
						$product ? $product->get_name() : ( '#' . $gift_id )
					);
				}
			}
		}
		return $lines;
	}

	/**
	 * Pure: human description of a shipping-override mode. Currency symbol is passed in so
	 * the function stays testable without WooCommerce.
	 *
	 * @param string $mode   free | percent | fixed.
	 * @param string $value  Percent (e.g. "50") or fixed amount, depending on mode.
	 * @param string $symbol Currency symbol (e.g. "NT$").
	 */
	public static function ship_label( string $mode, string $value, string $symbol ): string {
		switch ( $mode ) {
			case 'free':
				return __( 'Free shipping', 'moksafocou' );
			case 'percent':
				/* translators: %s: percent off shipping. */
				return sprintf( __( 'Shipping discount %s%%', 'moksafocou' ), $value );
			case 'fixed':
				/* translators: 1: currency symbol, 2: fixed amount off shipping. */
				return sprintf( __( 'Shipping discount %1$s%2$s', 'moksafocou' ), $symbol, $value );
			default:
				return __( 'Shipping adjustment', 'moksafocou' );
		}
	}

	/** wc_price() but as plain text — tags stripped AND HTML entities (e.g. the currency symbol) decoded, so the note reads cleanly in admin, REST and plain-text contexts. */
	private static function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	private static function order_has_product( \WC_Order $order, int $product_id ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && (int) $item->get_product_id() === $product_id ) {
				return true;
			}
		}
		return false;
	}
}
