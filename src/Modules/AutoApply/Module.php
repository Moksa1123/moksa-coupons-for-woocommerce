<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\AutoApply;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-apply module. Lazy-loaded — boots only when moksafocou_autoapply_enabled is
 * 'yes'. Coupons flagged for auto-apply are added to the cart automatically when
 * their conditions are met, with no code entry. Works for classic + Block carts.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'autoapply';
	}

	public function label(): string {
		return __( 'Auto-apply coupon', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Automatically add the coupon when the cart meets the conditions, no code needed', 'moksa-coupons-for-woocommerce' );
	}

	public function boot(): void {
		Frontend::boot();

		// The auto-apply list comes from Support\CouponIndex, which follows the meta itself.

		if ( is_admin() ) {
			$fields = new Fields();
			add_action( 'woocommerce_coupon_options', array( $fields, 'render' ), 10, 2 );
			add_action( 'woocommerce_coupon_options_save', array( $fields, 'save' ), 10, 2 );
			add_action( 'admin_notices', array( Fields::class, 'notice' ) );
		}
	}
}
