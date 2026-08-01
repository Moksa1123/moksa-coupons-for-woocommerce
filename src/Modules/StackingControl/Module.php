<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\StackingControl;

use Moksafocou\Modules\AbstractModule;
use Moksafocou\Admin\CouponSections;

defined( 'ABSPATH' ) || exit;

/**
 * Stacking-control module — lazy-loaded, boots only when
 * moksafocou_stacking_enabled is 'yes'. Governs whether a coupon may be combined
 * with OTHER coupons (exclude-others + allow / disallow code lists). Enforcement
 * (Validator) and the admin UI (Fields) are gated together so a merchant can never
 * set a stacking rule that is then silently ignored.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'stacking';
	}

	public function label(): string {
		return __( 'Stacking control', 'moksafocou' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Mutually exclusive coupons, allowed / disallowed combinable coupon code lists', 'moksafocou' );
	}

	public function boot(): void {
		Validator::boot();

		if ( is_admin() ) {
			$fields = new Fields();
			CouponSections::register( 24, array( $fields, 'render_nonce' ), array( $fields, 'sections' ) );
			add_action( 'woocommerce_coupon_options_save', array( $fields, 'save' ), 10, 2 );
		}
	}
}
