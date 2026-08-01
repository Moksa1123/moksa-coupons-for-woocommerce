<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\ShippingOverride;

use Moksafocou\Coupon\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a coupon's shipping-override rule and computes — purely — the overridden
 * shipping cost. The cost math (ship_cost) is WooCommerce-free and unit-tested.
 */
final class ShipConfig {

	/** @var array<int,string> Valid modes. 'none' = inactive. */
	public const MODES = array( 'none', 'free', 'percent', 'fixed' );

	/**
	 * @param \WC_Coupon $coupon Coupon to read from.
	 * @return array{mode:string,value:float}
	 */
	public static function read( \WC_Coupon $coupon ): array {
		$mode = (string) $coupon->get_meta( Keys::SHIP_MODE, true );
		return array(
			'mode'  => in_array( $mode, self::MODES, true ) ? $mode : 'none',
			'value' => (float) $coupon->get_meta( Keys::SHIP_VALUE, true ),
		);
	}

	/**
	 * @param array{mode:string,value:float} $cfg Rule.
	 */
	public static function is_active( array $cfg ): bool {
		return 'none' !== $cfg['mode'] && '' !== $cfg['mode'];
	}

	/**
	 * Normalize a raw mode to one of MODES (default 'none').
	 *
	 * @param string $mode Raw mode.
	 */
	public static function mode( string $mode ): string {
		return in_array( $mode, self::MODES, true ) ? $mode : 'none';
	}

	/**
	 * The overridden shipping cost. free → 0; fixed → base − value; percent → base less
	 * value% (clamped 0–100). Never negative; 'none'/unknown returns the base unchanged.
	 *
	 * @param float  $base  Original rate cost.
	 * @param string $mode  Override mode.
	 * @param float  $value Override value.
	 */
	public static function ship_cost( float $base, string $mode, float $value ): float {
		switch ( $mode ) {
			case 'free':
				return 0.0;
			case 'fixed':
				return max( 0.0, $base - $value );
			case 'percent':
				return max( 0.0, $base - ( $base * ( max( 0.0, min( 100.0, $value ) ) / 100 ) ) );
			default:
				return $base;
		}
	}
}
