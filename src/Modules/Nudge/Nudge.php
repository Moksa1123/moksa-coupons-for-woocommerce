<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Nudge;

defined( 'ABSPATH' ) || exit;

/**
 * "再買 NT$X 免運" progress nudge on the cart / checkout — a proven average-order-value driver.
 * Finds the lowest free-shipping threshold configured across shipping zones and tells the shopper
 * how much more to spend. The arithmetic (remaining) is pure and unit-tested.
 */
final class Nudge {

	/** How much more is needed to reach the threshold (never negative). Pure. */
	public static function remaining( float $subtotal, float $threshold ): float {
		return max( 0.0, round( $threshold - $subtotal, 2 ) );
	}

	public static function render(): void {
		if ( is_admin() || ! function_exists( 'WC' ) || null === WC()->cart || ! function_exists( 'wc_print_notice' ) ) {
			return;
		}
		$threshold = self::free_shipping_threshold();
		if ( $threshold <= 0.0 ) {
			return;
		}
		$subtotal = (float) WC()->cart->get_subtotal();
		if ( $subtotal <= 0.0 || $subtotal >= $threshold ) {
			return;
		}
		wc_print_notice(
			sprintf(
				/* translators: %s: remaining amount. */
				__( 'Spend %s more to get free shipping!', 'moksa-coupons-for-woocommerce' ),
				wp_strip_all_tags( wc_price( self::remaining( $subtotal, $threshold ) ) )
			),
			'notice'
		);
	}

	/** The lowest min-amount free-shipping threshold configured across all shipping zones, or 0. */
	private static function free_shipping_threshold(): float {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return 0.0;
		}
		$zones = array();
		foreach ( \WC_Shipping_Zones::get_zones() as $zone_data ) {
			$zones[] = \WC_Shipping_Zones::get_zone( (int) $zone_data['id'] );
		}
		$zones[] = \WC_Shipping_Zones::get_zone( 0 ); // "Rest of the World".

		$candidates = array();
		foreach ( $zones as $zone ) {
			if ( ! $zone instanceof \WC_Shipping_Zone ) {
				continue;
			}
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( 'free_shipping' !== $method->id || 'yes' !== $method->enabled ) {
					continue;
				}
				$requires = (string) $method->get_option( 'requires' );
				$min      = (float) $method->get_option( 'min_amount' );
				if ( $min > 0.0 && in_array( $requires, array( 'min_amount', 'either' ), true ) ) {
					$candidates[] = $min;
				}
			}
		}
		return array() === $candidates ? 0.0 : (float) min( $candidates );
	}
}
