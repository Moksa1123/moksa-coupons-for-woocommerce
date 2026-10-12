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
		return __( 'Tiered discount', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'The same coupon gives different discounts by cart threshold (e.g. under 1000 get 10% off, over 1000 get 20% off)', 'moksa-coupons-for-woocommerce' );
	}

	public function boot(): void {
		Engine::boot();

		/**
		 * Two independent switches, each covering the classic AND the block cart / checkout. They
		 * were one in 1.2.0, so a shop with its own "spend more" bar (em925) could not hide our
		 * nudge without also losing the tier on the coupon line.
		 *
		 * Filter moksafocou_tiers_show_nudge:    the "add NT$X more to upgrade" row and bar.
		 * Filter moksafocou_tiers_label_current: the current tier on the coupon line ("（82 折）").
		 */
		$nudge = (bool) apply_filters( 'moksafocou_tiers_show_nudge', true );
		$label = (bool) apply_filters( 'moksafocou_tiers_label_current', true );
		if ( $nudge || $label ) {
			Nudge::boot( $nudge, $label );     // Classic cart / checkout.
			StoreApi::boot( $nudge, $label );  // Block cart / checkout — WooCommerce's default, which Nudge never reached.
		}

		if ( is_admin() ) {
			$fields = new Fields();
			CouponSections::register( 18, array( $fields, 'render_nonce' ), array( $fields, 'sections' ) );
			add_action( 'woocommerce_coupon_options_save', array( $fields, 'save' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $fields, 'enqueue' ) );
			add_action( 'admin_notices', array( Fields::class, 'notice' ) );
		}
	}
}
