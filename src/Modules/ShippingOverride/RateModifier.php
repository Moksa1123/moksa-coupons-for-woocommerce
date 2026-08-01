<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\ShippingOverride;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites shipping-rate costs at calculation time (woocommerce_package_rates, shared
 * by classic + Block/Store API) for any applied coupon carrying a shipping override.
 * When several override coupons apply, each rate takes the single best (lowest) result
 * computed from the original cost — no compounding. Taxes are scaled proportionally.
 */
final class RateModifier {

	/** Shipping-line meta carrying the saving this override gave (original cost − new cost). */
	public const SAVED_META = '_moksafocou_ship_saved';

	public static function boot(): void {
		add_filter( 'woocommerce_package_rates', array( self::class, 'apply' ), 100, 2 );
	}

	/**
	 * @param mixed $rates   array<string,WC_Shipping_Rate>.
	 * @param mixed $package Shipping package (unused; rates already scoped to it).
	 * @return mixed
	 */
	public static function apply( $rates, $package = array() ) {
		if ( ! is_array( $rates ) || ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return $rates;
		}
		$overrides = self::overrides( WC()->cart->get_applied_coupons() );
		if ( array() === $overrides ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate ) {
				continue;
			}
			$base = (float) $rate->get_cost();
			$best = $base;
			foreach ( $overrides as $ov ) {
				$cost = ShipConfig::ship_cost( $base, $ov['mode'], $ov['value'] );
				if ( $cost < $best ) {
					$best = $cost;
				}
			}
			if ( $best < $base ) {
				$ratio = $base > 0 ? $best / $base : 0.0;
				$rate->set_cost( $best );
				$taxes = $rate->get_taxes();
				if ( is_array( $taxes ) ) {
					foreach ( $taxes as $key => $value ) {
						$taxes[ $key ] = (float) $value * $ratio;
					}
					$rate->set_taxes( $taxes );
				}
				// Stash the saving on the rate; WC copies shipping-rate meta onto the order's
				// shipping line (set_shipping_rate), so the order preview can surface it. The
				// rate object is freshly built each calculation, so this never accumulates.
				$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
				$rate->add_meta_data( self::SAVED_META, (string) round( $base - $best, $decimals ) );
			}
		}
		return $rates;
	}

	/**
	 * Active shipping-override rules for the applied coupons.
	 *
	 * @param array<int,string> $codes Applied coupon codes.
	 * @return array<int,array{mode:string,value:float}>
	 */
	private static function overrides( array $codes ): array {
		$out = array();
		foreach ( $codes as $code ) {
			$coupon = new \WC_Coupon( (string) $code );
			if ( ! $coupon->get_id() ) {
				continue;
			}
			$cfg = ShipConfig::read( $coupon );
			if ( ShipConfig::is_active( $cfg ) ) {
				$out[] = $cfg;
			}
		}
		return $out;
	}
}
