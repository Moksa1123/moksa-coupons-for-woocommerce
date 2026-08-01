<?php

declare( strict_types=1 );

namespace Moksafocou\Admin;

use Moksafocou\Coupon\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an 'Coupon applied' section to the WooCommerce order-list quick-preview (the eye
 * icon), which by default shows items + total but never the coupons used. Hooks WC's
 * `woocommerce_admin_order_preview_get_order_details` filter and splices coupon rows
 * into the preview's existing items table (item_html) using WC's own column classes, so
 * they align with the product rows. The markup is rendered unescaped by WC's modal
 * template, so everything here is fully escaped.
 *
 * The saving shown per coupon falls back through the channels WooCommerce itself doesn't
 * count: WC's own discount → BOGO per-line saving → shipping-override saving (the latter
 * recorded on the order's shipping line by the ShippingOverride module).
 */
final class OrderCouponPreview {

	/** Coupon-item meta where the BOGO module stores the real saving (WC discount = 0). */
	private const BOGO_LINE_META = '_moksafocou_bogo_coupon_discount';
	/** Shipping-line meta the ShippingOverride RateModifier records (mirror of RateModifier::SAVED_META). */
	private const SHIP_SAVED_META = '_moksafocou_ship_saved';

	public static function register(): void {
		add_filter( 'woocommerce_admin_order_preview_get_order_details', array( self::class, 'add_coupons' ), 10, 2 );
	}

	/**
	 * @param mixed $data  Preview details array WC passes to the modal template.
	 * @param mixed $order The order being previewed.
	 * @return mixed
	 */
	public static function add_coupons( $data, $order ) {
		if ( ! is_array( $data ) || ! $order instanceof \WC_Order || ! isset( $data['item_html'] ) ) {
			return $data;
		}

		$ship_saving = self::shipping_saving( $order );
		$ship_shown  = false;

		$items = array();
		foreach ( $order->get_items( 'coupon' ) as $line ) {
			if ( ! $line instanceof \WC_Order_Item_Coupon ) {
				continue;
			}
			$code   = (string) $line->get_code();
			$amount = self::effective_discount( (float) $line->get_discount(), $line->get_meta( self::BOGO_LINE_META ) );
			$note   = '';
			// A shipping-override coupon's WC discount is 0; show the shipping saved instead
			// (once — attribute the order's shipping cut to the first shipping coupon).
			if ( $amount <= 0.0 && ! $ship_shown && $ship_saving > 0.0 && self::is_shipping_coupon( $code ) ) {
				$amount     = $ship_saving;
				$note       = __( 'Shipping', 'moksafocou' );
				$ship_shown = true;
			}
			$items[] = array(
				'code'   => $code,
				'amount' => $amount,
				'note'   => $note,
			);
		}

		$rows = self::coupon_rows( $items );
		if ( '' === $rows ) {
			return $data;
		}

		// Inject the rows INTO WooCommerce's existing items table (right before its closing
		// </tbody>) so they inherit the same column widths/styling and never break layout.
		$item_html         = (string) $data['item_html'];
		$pos               = strripos( $item_html, '</tbody>' );
		$data['item_html'] = ( false !== $pos )
			? substr_replace( $item_html, $rows, $pos, 0 )
			: $item_html . '<table class="wc-order-preview-table"><tbody>' . $rows . '</tbody></table>';
		return $data;
	}

	/**
	 * Pure: the saving to display for a coupon line — WC's own discount when it has one,
	 * otherwise the numeric BOGO per-line saving this plugin recorded (else 0). Shipping
	 * savings are resolved separately (they live on the shipping line, not the coupon).
	 *
	 * @param float $wc_discount WC_Order_Item_Coupon::get_discount().
	 * @param mixed $bogo_meta   Raw BOGO_LINE_META value (string|null).
	 */
	public static function effective_discount( float $wc_discount, $bogo_meta ): float {
		if ( $wc_discount > 0 ) {
			return $wc_discount;
		}
		return is_numeric( $bogo_meta ) ? (float) $bogo_meta : 0.0;
	}

	/**
	 * Pure: sum the numeric values (e.g. per-shipping-line savings), ignoring non-numerics.
	 *
	 * @param array<int,mixed> $values
	 */
	public static function sum_saving( array $values ): float {
		$sum = 0.0;
		foreach ( $values as $value ) {
			if ( is_numeric( $value ) ) {
				$sum += (float) $value;
			}
		}
		return $sum;
	}

	/** Total shipping saving the override recorded on this order's shipping lines. */
	private static function shipping_saving( \WC_Order $order ): float {
		$values = array();
		foreach ( $order->get_items( 'shipping' ) as $ship ) {
			$values[] = $ship->get_meta( self::SHIP_SAVED_META );
		}
		return self::sum_saving( $values );
	}

	/** True when the coupon carries a shipping override (its WC discount is then 0). */
	private static function is_shipping_coupon( string $code ): bool {
		$coupon = new \WC_Coupon( $code );
		if ( 0 === $coupon->get_id() ) {
			return false;
		}
		$mode = (string) $coupon->get_meta( Keys::SHIP_MODE );
		return '' !== $mode && 'none' !== $mode;
	}

	/**
	 * Build the coupon table rows to splice into WooCommerce's preview items table: a
	 * full-width labelled heading row, then one aligned row per coupon (code + optional
	 * tag in the product column, saving in the total column) reusing WC's own column
	 * classes. View-only; the saving maths live in the unit-tested helpers.
	 *
	 * @param array<int,array{code:string,amount:float,note:string}> $items
	 */
	private static function coupon_rows( array $items ): string {
		$rows = '';
		foreach ( $items as $item ) {
			$code = strtoupper( trim( (string) ( $item['code'] ?? '' ) ) );
			if ( '' === $code ) {
				continue;
			}
			$amount      = (float) ( $item['amount'] ?? 0 );
			$note        = (string) ( $item['note'] ?? '' );
			$tag         = '' !== $note ? ' <span style="color:#646970;font-size:.9em">(' . esc_html( $note ) . ')</span>' : '';
			$amount_html = $amount > 0 ? '&minus;' . wp_kses_post( wc_price( $amount ) ) : '&mdash;';
			$rows       .= '<tr class="wc-order-preview-table__item moksafocou-preview-coupon">'
				. '<td class="wc-order-preview-table__column--product"><code>' . esc_html( $code ) . '</code>' . $tag . '</td>'
				. '<td class="wc-order-preview-table__column--quantity"></td>'
				. '<td class="wc-order-preview-table__column--total">' . $amount_html . '</td></tr>';
		}
		if ( '' === $rows ) {
			return '';
		}
		$heading = '<tr class="moksafocou-preview-coupon-heading"><td colspan="3" style="padding-top:1em;border-top:1px solid #e0e0e0;font-weight:600;color:#646970">'
			. esc_html__( 'Coupon applied', 'moksafocou' ) . '</td></tr>';
		return $heading . $rows;
	}
}
