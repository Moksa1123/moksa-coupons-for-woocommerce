<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Savings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the aggregate savings row on the cart + checkout totals. The base figure is the
 * cart's coupon discount total; modules whose savings do not surface as a coupon line (e.g.
 * BOGO, which lowers item prices via set_price) add their amount via the
 * moksafocou_cart_savings_total filter. Renders nothing when nothing was saved.
 */
final class Frontend {

	public static function boot(): void {
		// cart-totals.php and checkout/review-order.php fire different hooks; cover both.
		add_action( 'woocommerce_cart_totals_after_order_total', array( self::class, 'render' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return;
		}
		$cart  = WC()->cart;
		$total = (float) $cart->get_discount_total();
		/**
		 * Filter the displayed total savings. Lets feature modules contribute savings that do
		 * not appear as a WooCommerce coupon discount line (e.g. BOGO set_price reductions).
		 *
		 * @param float    $total Running savings total.
		 * @param \WC_Cart $cart  The cart being rendered.
		 */
		$total = (float) apply_filters( 'moksafocou_cart_savings_total', $total, $cart );
		if ( $total <= 0.0 ) {
			return;
		}
		$label = (string) apply_filters( 'moksafocou_cart_savings_label', __( 'You saved a total of', 'moksa-coupons-for-woocommerce' ) );
		printf(
			'<tr class="moksafocou-savings-total"><th>%1$s</th><td data-title="%1$s">%2$s</td></tr>',
			esc_html( $label ),
			wp_kses_post( wc_price( $total ) )
		);
	}
}
