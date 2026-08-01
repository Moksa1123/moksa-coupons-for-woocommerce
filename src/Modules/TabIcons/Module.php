<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\TabIcons;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Tab-icons module — lazy-loaded, boots only when moksafocou_tabicons_enabled is
 * 'yes'. Adds a uniform monochrome line icon to each Moksa coupon-settings tab on the
 * coupon edit screen (matching the Advanced Coupons coupon-settings look).
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'tabicons';
	}

	public function label(): string {
		return __( 'Coupon settings icon', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Add a consistently styled monochrome icon to each coupon settings tab', 'moksa-coupons-for-woocommerce' );
	}

	public function boot(): void {
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		}
	}

	/** Inline the tab-icon CSS only on the coupon edit screen. */
	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'shop_coupon' !== $screen->id ) {
			return;
		}
		wp_register_style( 'moksafocou-tab-icons', false, array(), \MOKSAFOCOU_VERSION );
		wp_enqueue_style( 'moksafocou-tab-icons' );
		wp_add_inline_style( 'moksafocou-tab-icons', Icons::css() );
	}
}
