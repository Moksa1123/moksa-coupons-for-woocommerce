<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Nudge;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 'Free-shipping threshold hint' module — lazy-loaded, boots only when moksafocou_nudge_enabled is 'yes'. Shows a
 * "再買 NT$X 免運" message on the cart and checkout to nudge shoppers over the free-shipping line.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'nudge';
	}

	public function label(): string {
		return __( 'Free-shipping threshold hint', 'moksafocou' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Show "Spend NT$X more for free shipping" in the cart / checkout to boost order value', 'moksafocou' );
	}

	public function boot(): void {
		add_action( 'woocommerce_before_cart', array( Nudge::class, 'render' ) );
		add_action( 'woocommerce_review_order_before_payment', array( Nudge::class, 'render' ) );
	}
}
