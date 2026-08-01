<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Templates;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon-templates module — lazy-loaded, boots only when moksafocou_templates_enabled
 * is 'yes'. Adds a 'Coupon template' page of one-click presets. When the AdminMenu module is
 * on, that module reparents the page under the top-level menu; otherwise this module
 * registers the legacy submenu under WooCommerce.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'templates';
	}

	public function label(): string {
		return __( 'Coupon template', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Ready-made coupon templates, create a draft coupon in one click', 'moksa-coupons-for-woocommerce' );
	}

	public function boot(): void {
		// The apply action runs from any admin context (form post → admin-post.php).
		add_action( 'admin_post_moksafocou_apply_template', array( TemplatePage::class, 'handle' ) );

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( TemplatePage::class, 'enqueue_admin' ) );
			if ( ! \Moksafocou\Plugin::instance()->modules()->is_enabled( 'adminmenu' ) ) {
				add_action( 'admin_menu', array( TemplatePage::class, 'register' ) );
			}
		}
	}
}
