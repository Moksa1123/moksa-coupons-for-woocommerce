<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\ShippingOverride;

use Moksafocou\Modules\AbstractModule;
use Moksafocou\Admin\CouponSections;

defined( 'ABSPATH' ) || exit;

/**
 * Shipping-override module — lazy-loaded, boots only when
 * moksafocou_shipping_enabled is 'yes'. Rewrites shipping-rate costs (free / percent
 * off / fixed off) for any applied coupon carrying an override. Enforcement
 * (RateModifier) and the admin UI (Fields) are gated together.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'shipping';
	}

	public function label(): string {
		return __( 'Shipping override', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Free or discounted shipping when the coupon is applied (percentage or fixed amount)', 'moksa-coupons-for-woocommerce' );
	}

	public function boot(): void {
		RateModifier::boot();

		if ( is_admin() ) {
			$fields = new Fields();
			CouponSections::register( 25, array( $fields, 'render_nonce' ), array( $fields, 'sections' ) );
			add_action( 'woocommerce_coupon_options_save', array( $fields, 'save' ), 10, 2 );
		}
	}
}
