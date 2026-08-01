<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Summary;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Live coupon-summary module — lazy-loaded, boots only when moksafocou_summary_enabled is
 * 'yes'. Adds a side metabox on the coupon editor that, as the admin edits, shows what the
 * coupon does, which advanced features are on, and any detected conflicts.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'summary';
	}

	public function label(): string {
		return __( 'Live summary on the edit page', 'moksafocou' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Show a live effect summary and conflict warnings while editing the coupon', 'moksafocou' );
	}

	public function boot(): void {
		if ( is_admin() ) {
			Panel::boot();
		}
	}
}
