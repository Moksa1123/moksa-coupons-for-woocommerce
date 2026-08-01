<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\FreeGift;

use Moksafocou\Modules\AbstractModule;
use Moksafocou\Admin\CouponSections;

defined( 'ABSPATH' ) || exit;

/**
 * Free-gift / add-product module. Lazy-loaded — boots only when
 * moksafocou_freegift_enabled is 'yes'. Auto-adds a coupon's gift product to the
 * cart on apply (classic + Block), priced free / percent / fixed.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'freegift';
	}

	public function label(): string {
		return __( 'Add-on / free gift', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Automatically add a gift (free or discounted) when the coupon is applied; quantity is locked and withdrawn when the coupon is removed', 'moksa-coupons-for-woocommerce' );
	}

	public function boot(): void {
		GiftHandler::boot();

		if ( is_admin() ) {
			$fields = new Fields();
			CouponSections::register( 23, array( $fields, 'render_nonce' ), array( $fields, 'sections' ) );
			add_action( 'woocommerce_coupon_options_save', array( $fields, 'save' ), 10, 2 );
		}
	}
}
