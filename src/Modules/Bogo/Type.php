<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Bogo;

use Moksafocou\Support\DiscountTypeRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the 'moksafocou_bogo' coupon discount type. This single filter IS the
 * whole type registration — the coupon stays an ordinary shop_coupon whose native
 * discount_type meta is 'moksafocou_bogo', and WC_Coupon::is_type() keys off it.
 * The actual discount is applied later by Frontend via set_price (never by WC's
 * coupon-amount engine), so we deliberately register NO get_discount_amount filter.
 */
final class Type {

	public static function register(): void {
		add_filter( 'woocommerce_coupon_discount_types', array( self::class, 'add_type' ) );
	}

	/**
	 * @param array<string,string> $types
	 * @return array<string,string>
	 */
	public static function add_type( $types ) {
		if ( is_array( $types ) ) {
			$types[ BogoMeta::TYPE ] = DiscountTypeRegistry::admin_label( BogoMeta::TYPE );
		}
		return $types;
	}
}
