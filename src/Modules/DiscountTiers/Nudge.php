<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\DiscountTiers;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\Tiers;

defined( 'ABSPATH' ) || exit;

/**
 * Cart / checkout progress nudge for an applied tiered coupon: "再加 NT$X 即可升級折扣".
 * Turns the otherwise-invisible tier ladder into an AOV driver. Renders nothing unless a tiered
 * coupon is applied and a better tier is still reachable. Phrasing follows the coupon's basis
 * (subtotal / quantity / weight) and the next tier's kind (percent or fixed).
 */
final class Nudge {

	public static function boot(): void {
		add_action( 'woocommerce_cart_totals_after_order_total', array( self::class, 'render' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return;
		}
		$cart                            = WC()->cart;
		list( $subtotal, $qty, $weight ) = Engine::measure( $cart );

		foreach ( $cart->get_coupons() as $coupon ) {
			if ( ! $coupon instanceof \WC_Coupon || 'percent' !== $coupon->get_discount_type() ) {
				continue;
			}
			if ( 'yes' !== $coupon->get_meta( Keys::TIERS_ENABLED, true ) ) {
				continue;
			}
			$tiers = Tiers::parse( (string) $coupon->get_meta( Keys::TIERS, true ) );
			if ( array() === $tiers ) {
				continue;
			}
			$basis   = Tiers::basis( $coupon->get_meta( Keys::TIERS_BASIS, true ) );
			$measure = 'quantity' === $basis ? (float) $qty : ( 'weight' === $basis ? $weight : $subtotal );
			$mode    = (string) $coupon->get_meta( Keys::TIERS_TARGET_MODE, true );
			$mode    = in_array( $mode, array( 'products', 'categories' ), true ) ? $mode : 'all';
			$base    = Engine::targeted_subtotal(
				$cart,
				$mode,
				self::ids( $coupon->get_meta( Keys::TIERS_TARGET_PRODUCTS, true ) ),
				self::ids( $coupon->get_meta( Keys::TIERS_TARGET_CATEGORIES, true ) )
			);

			$next = Tiers::next_tier( $tiers, $measure, $base );
			if ( null === $next ) {
				continue;
			}
			$deal = 'fixed' === $next['kind']
				? sprintf(
					/* translators: %s: fixed discount amount. */
					__( 'Get %s off', 'moksa-coupons-for-woocommerce' ),
					wp_kses_post( wc_price( (float) $next['value'] ) )
				)
				: sprintf(
					/* translators: %s: discount percent. */
					__( 'Discount upgraded to %s%%', 'moksa-coupons-for-woocommerce' ),
					esc_html( self::trim( (float) $next['value'] ) )
				);

			$msg = sprintf(
				/* translators: 1: amount/qty/weight to add, 2: the upgraded deal. */
				__( '%1$s,%2$s!', 'moksa-coupons-for-woocommerce' ),
				esc_html( self::gap_text( $basis, (float) $next['gap'] ) ),
				$deal
			);
			printf(
				'<tr class="moksafocou-tier-nudge"><th>%1$s</th><td data-title="%1$s">%2$s</td></tr>',
				esc_html__( 'Tiered discount', 'moksa-coupons-for-woocommerce' ),
				wp_kses_post( $msg )
			);
			return; // one nudge is enough.
		}
	}

	/** "購物車再加 …" phrased for the basis. */
	private static function gap_text( string $basis, float $gap ): string {
		if ( 'quantity' === $basis ) {
			/* translators: %s: number of additional items. */
			return sprintf( __( 'Add %s more item(s) to the cart', 'moksa-coupons-for-woocommerce' ), self::trim( ceil( $gap ) ) );
		}
		if ( 'weight' === $basis ) {
			/* translators: %s: additional weight in kg. */
			return sprintf( __( 'Add %s more kg to the cart', 'moksa-coupons-for-woocommerce' ), self::trim( $gap ) );
		}
		/* translators: %s: additional spend (price HTML). */
		return sprintf( __( 'Add %s more to the cart', 'moksa-coupons-for-woocommerce' ), html_entity_decode( wp_strip_all_tags( wc_price( $gap ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	private static function trim( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * @param mixed $value
	 * @return array<int,int>
	 */
	private static function ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', $value ), static fn( int $id ): bool => $id > 0 ) );
	}
}
