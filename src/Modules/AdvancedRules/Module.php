<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\AdvancedRules;

use Moksafocou\Modules\AbstractModule;
use Moksafocou\Admin\CouponSections;

defined( 'ABSPATH' ) || exit;

/**
 * Advanced rule-builder module — lazy-loaded, boots only when moksafocou_advrules_enabled
 * is 'yes'. Adds a free-form AND/OR condition tree (Advanced-Coupons-style cart conditions)
 * enforced on top of the simple per-dimension conditions. Enforcement (Engine) + admin UI
 * (Fields) are gated together.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'advrules';
	}

	public function label(): string {
		return __( 'Advanced rules (AND/OR)', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Freely combine conditions with groups and AND/OR (subtotal / item count / products / categories / regions / payment / roles / weekday / time…)', 'moksa-coupons-for-woocommerce' );
	}

	public function boot(): void {
		Engine::boot();

		if ( is_admin() ) {
			$fields = new Fields();
			CouponSections::register( 22, array( $fields, 'render_nonce' ), array( $fields, 'sections' ) );
			add_action( 'woocommerce_coupon_options_save', array( $fields, 'save' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $fields, 'enqueue' ) );
		}
	}
}
