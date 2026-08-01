<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\DiscountCap;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Discount-cap module. Lazy-loaded — boots only when moksafocou_discountcap_enabled
 * is 'yes'. Lets a percent coupon's total discount be capped at a maximum amount.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'discountcap';
	}

	public function label(): string {
		return __( 'Maximum discount', 'moksafocou' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Set a maximum discount amount for a percentage discount (e.g. 20% off, at most 500)', 'moksafocou' );
	}

	public function boot(): void {
		Cap::boot();

		if ( is_admin() ) {
			$fields = new Fields();
			add_action( 'woocommerce_coupon_options', array( $fields, 'render' ), 10, 2 );
			add_action( 'woocommerce_coupon_options_save', array( $fields, 'save' ), 10, 2 );
		}
	}
}
