<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\AdminMenu;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-menu module — lazy-loaded, boots only when moksafocou_adminmenu_enabled is
 * 'yes'. Promotes coupon management into its own top-level 'Moksa coupon' menu and
 * reparents the shop_coupon CPT (全部優惠券 / 新增) under it, alongside the report
 * page and a settings link.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'adminmenu';
	}

	public function label(): string {
		return __( 'Coupon management menu', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Move coupon management into its own top-level menu (bringing together all coupons / add / reports / settings)', 'moksa-coupons-for-woocommerce' );
	}

	public function boot(): void {
		// Hide shop_coupon from core's automatic menu placement on every registration
		// (init), admin and front, so the result is consistent. Only the admin menu
		// render is actually affected.
		add_filter( 'register_post_type_args', array( Menu::class, 'reparent_cpt' ), 20, 2 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( Menu::class, 'register' ), 9 );
			add_action( 'admin_enqueue_scripts', array( Dashboard::class, 'enqueue_admin' ) );
			// Late pass: remove WooCommerce's leftover "Coupons" pointer entry.
			add_action( 'admin_menu', array( Menu::class, 'hide_legacy_coupon_menu' ), 999 );
			// Pin coupon CPT screens to our top-level menu for correct highlighting.
			add_filter( 'parent_file', array( Menu::class, 'highlight_parent' ) );
			add_filter( 'submenu_file', array( Menu::class, 'highlight_submenu' ) );
		}
	}
}
