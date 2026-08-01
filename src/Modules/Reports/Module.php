<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Reports;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon-reports module — lazy-loaded, boots only when moksafocou_reports_enabled is
 * 'yes'. Adds a read-only 'Coupon report' admin page (submenu under WooCommerce) and
 * invalidates its cache whenever an order is paid/refunded so the figures stay fresh.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'reports';
	}

	public function label(): string {
		return __( 'Coupon report', 'moksafocou' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Orders used and total discount per coupon (separate admin page)', 'moksafocou' );
	}

	public function boot(): void {
		// When the AdminMenu module is on, it owns the report page (reparented under
		// the standalone top-level menu), so skip the legacy WooCommerce submenu to
		// avoid a duplicate entry.
		if ( is_admin() && ! \Moksafocou\Plugin::instance()->modules()->is_enabled( 'adminmenu' ) ) {
			add_action( 'admin_menu', array( ReportsPage::class, 'register' ) );
		}
		// Keep the cached figures fresh when sales change.
		add_action( 'woocommerce_order_status_changed', array( ReportService::class, 'flush' ) );
		add_action( 'woocommerce_order_refunded', array( ReportService::class, 'flush' ) );
	}
}
