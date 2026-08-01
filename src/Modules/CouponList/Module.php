<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponList;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon-list enhancements — lazy-loaded, boots only when moksafocou_couponlist_enabled
 * is 'yes'. Adds an enabled/disabled status column, bulk 啟用/停用 actions, and a one-click
 * 複製 (duplicate) row action to the WooCommerce coupon list — things WooCommerce's own list
 * table does not provide.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'couponlist';
	}

	public function label(): string {
		return __( 'Coupon list enhancements', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Add enable / disable status column, bulk enable/disable, and one-click coupon copy to the coupon list', 'moksa-coupons-for-woocommerce' );
	}

	public function boot(): void {
		if ( is_admin() ) {
			ListTable::boot();
		}
	}
}
