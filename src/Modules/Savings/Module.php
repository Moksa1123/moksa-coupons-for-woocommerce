<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Savings;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Savings-summary module — lazy-loaded, boots only when moksafocou_savings_enabled is
 * 'yes'. Adds a friendly "您總共省了 NT$X" row to the cart and checkout totals, summing
 * every coupon discount; other modules (e.g. BOGO, whose savings come from set_price not
 * a coupon line) add their amount through the moksafocou_cart_savings_total filter.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'savings';
	}

	public function label(): string {
		return __( 'Checkout savings hint', 'moksafocou' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Show a "You saved a total of NT$X" summary in the cart and checkout to reinforce the sense of savings', 'moksafocou' );
	}

	public function boot(): void {
		Frontend::boot();
	}
}
