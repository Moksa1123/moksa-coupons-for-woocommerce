<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Frontend;

use Moksafocou\Modules\AbstractModule;
use Moksafocou\Admin\CouponSections;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend-display module — lazy-loaded, boots only when moksafocou_frontend_enabled
 * is 'yes'. Provides the [moksafocou_coupons] card-list shortcode plus a per-coupon
 * 'Front-end display' opt-in tab.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'frontend';
	}

	public function label(): string {
		return __( 'Front-end coupon display', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Use the [moksafocou_coupons] shortcode to display available coupon cards on the front end', 'moksa-coupons-for-woocommerce' );
	}

	public function boot(): void {
		Shortcode::register();
		CardsCache::register();
		add_action( 'init', array( Block::class, 'register' ) );

		if ( is_admin() ) {
			$fields = new Fields();
			CouponSections::register( 26, array( $fields, 'render_nonce' ), array( $fields, 'sections' ) );
			add_action( 'woocommerce_coupon_options_save', array( $fields, 'save' ), 10, 2 );
		}
	}
}
