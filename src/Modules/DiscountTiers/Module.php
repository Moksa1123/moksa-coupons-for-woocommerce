<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\DiscountTiers;

use Moksafocou\Modules\AbstractModule;
use Moksafocou\Admin\CouponSections;

defined( 'ABSPATH' ) || exit;

/**
 * Tiered-discount module — lazy-loaded, boots only when moksafocou_discounttiers_enabled
 * is 'yes'. Lets ONE percent coupon give a different percent-off per cart tier (subtotal /
 * quantity), optionally scoped to chosen products / categories. Enforcement (Engine) and the
 * admin UI (Fields) are gated together.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'discounttiers';
	}

	public function label(): string {
		return __( 'Tiered discount', 'moksafocou' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'The same coupon gives different discounts by cart threshold (e.g. under 1000 get 10% off, over 1000 get 20% off)', 'moksafocou' );
	}

	public function boot(): void {
		Engine::boot();

		/** Filter: show the cart/checkout "spend NT$X more for a bigger discount" nudge. */
		if ( apply_filters( 'moksafocou_tiers_show_nudge', true ) ) {
			Nudge::boot();
		}

		if ( is_admin() ) {
			$fields = new Fields();
			CouponSections::register( 18, array( $fields, 'render_nonce' ), array( $fields, 'sections' ) );
			add_action( 'woocommerce_coupon_options_save', array( $fields, 'save' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $fields, 'enqueue' ) );
		}
	}
}
