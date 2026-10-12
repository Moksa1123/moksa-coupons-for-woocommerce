<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\DiscountTiers;

defined( 'ABSPATH' ) || exit;

/**
 * The classic (shortcode) cart and checkout: the "spend NT$X more for a bigger discount" row, and
 * the current tier named on the coupon's own line.
 *
 * The block cart and checkout — WooCommerce's default — never fire these hooks; they get the same
 * two things through {@see StoreApi} and the blocks script. Both read {@see State}, so a shop that
 * switches between the two sees identical wording.
 */
final class Nudge {

	/**
	 * @param bool $nudge Show the "add NT$X more" row.
	 * @param bool $label Name the current tier on the coupon's line ("全站優惠（82 折）").
	 */
	public static function boot( bool $nudge = true, bool $label = true ): void {
		if ( $nudge ) {
			add_action( 'woocommerce_cart_totals_after_order_total', array( self::class, 'render' ) );
			add_action( 'woocommerce_review_order_after_order_total', array( self::class, 'render' ) );
		}
		if ( $label ) {
			add_filter( 'woocommerce_cart_totals_coupon_label', array( self::class, 'label' ), 20, 2 );
		}
	}

	public static function render(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return;
		}

		foreach ( State::for_cart( WC()->cart ) as $state ) {
			if ( null === $state['next'] ) {
				continue;
			}
			printf(
				'<tr class="moksafocou-tier-nudge"><th>%1$s</th><td data-title="%1$s">%2$s</td></tr>',
				esc_html__( 'Tiered discount', 'moksa-coupons-for-woocommerce' ),
				esc_html( $state['next']['message'] )
			);
			return; // One nudge is enough.
		}
	}

	/**
	 * Append the current tier to a tiered coupon's label.
	 *
	 * @param mixed $label  Label WooCommerce built.
	 * @param mixed $coupon WC_Coupon.
	 * @return mixed
	 */
	public static function label( $label, $coupon ) {
		if ( ! is_string( $label ) || ! $coupon instanceof \WC_Coupon ) {
			return $label;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return $label;
		}
		$state = State::for_cart( WC()->cart )[ $coupon->get_code() ] ?? null;
		if ( null === $state || null === $state['current'] ) {
			return $label;
		}
		return $label . esc_html(
			sprintf(
				/* translators: %s: the current tier, e.g. "82 折" or "18% off". */
				__( ' (%s)', 'moksa-coupons-for-woocommerce' ),
				$state['current']['label']
			)
		);
	}
}
