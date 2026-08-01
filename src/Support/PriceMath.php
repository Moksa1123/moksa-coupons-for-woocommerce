<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pure per-unit discount math shared by the set_price mechanics (BOGO / Nth-item / Mix & Match).
 * The per-unit reward discount and the blended line price were copy-pasted byte-for-byte into each
 * module's Calc; they live here once so a clamp or rounding fix lands everywhere. No WooCommerce,
 * fully unit-testable.
 */
final class PriceMath {

	/**
	 * Per-unit discount for a rewarded unit, always clamped to [0, price] so a line can never be
	 * driven negative. 'percent' value is the DISCOUNT percent (六折 = 40 → 40% off).
	 */
	public static function unit_discount( string $mode, float $value, float $price ): float {
		switch ( $mode ) {
			case 'free':
				$discount = $price;
				break;
			case 'fixed_per_item':
				$discount = $value;
				break;
			case 'percent':
			default:
				$discount = $price * ( max( 0.0, min( 100.0, $value ) ) / 100 );
				break;
		}
		return max( 0.0, min( $price, $discount ) );
	}

	/**
	 * Blended per-unit price to feed set_price() when only $take of a line's $qty units are
	 * discounted: spread the total discount across the whole line so a single mixed line (some
	 * full-price, some discounted units) totals correctly and never goes negative.
	 */
	public static function blended_price( float $price, float $unit_discount, int $take, int $qty ): float {
		if ( $qty <= 0 ) {
			return max( 0.0, $price );
		}
		return max( 0.0, $price - ( ( $unit_discount * $take ) / $qty ) );
	}
}
