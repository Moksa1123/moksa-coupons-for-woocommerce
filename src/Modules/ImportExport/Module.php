<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\ImportExport;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * CSV import / export module — lazy-loaded, boots only when moksafocou_importexport_enabled
 * is 'yes'. Adds an 'Import / Export' admin page under the coupon menu for backing up, auditing or
 * bulk-editing coupons as CSV.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'importexport';
	}

	public function label(): string {
		return __( 'CSV import / export', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Export coupons to CSV for backup / auditing, or bulk create and update with a CSV', 'moksa-coupons-for-woocommerce' );
	}

	public function boot(): void {
		if ( is_admin() ) {
			ImportExport::boot();
		}
	}
}
