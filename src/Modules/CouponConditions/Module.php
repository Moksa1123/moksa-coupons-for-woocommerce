<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponConditions;

use Moksafocou\Modules\AbstractModule;
use Moksafocou\Admin\CouponSections;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon conditions module: schedule window, role restrictions and cart minimums.
 * Lazy-loaded — only boots when moksafocou_conditions_enabled is 'yes', so an
 * unchecked feature registers no hooks. Enforcement (Validator) and the admin UI
 * (Fields) are gated together, so a merchant can never set a condition that is
 * then silently ignored.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'conditions';
	}

	public function label(): string {
		return __( 'Coupon conditions (schedule / role / cart)', 'moksafocou' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Schedule start and end, role restrictions, minimum subtotal / quantity, customer history, products / categories, days / time slots', 'moksafocou' );
	}

	public function boot(): void {
		// Enforcement runs on the front-end / checkout for both classic and Block.
		Validator::boot();

		if ( is_admin() ) {
			$fields = new Fields();
			CouponSections::register( 20, array( $fields, 'render_nonce' ), array( $fields, 'sections' ) );
			add_action( 'woocommerce_coupon_options_save', array( $fields, 'save' ), 10, 2 );
		}
	}
}
