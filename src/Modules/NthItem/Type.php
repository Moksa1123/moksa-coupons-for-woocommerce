<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\NthItem;

use Moksafocou\Support\DiscountTypeRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the 'moksafocou_nth_item' coupon discount type. This single filter IS the whole type
 * registration — the coupon stays an ordinary shop_coupon whose native discount_type meta is
 * 'moksafocou_nth_item', and WC_Coupon::is_type() keys off it. The discount is applied by
 * Frontend via set_price (never by WC's coupon-amount engine), so we register NO
 * get_discount_amount filter.
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
			$types[ NthItemMeta::TYPE ] = DiscountTypeRegistry::admin_label( NthItemMeta::TYPE );
		}
		return $types;
	}
}
