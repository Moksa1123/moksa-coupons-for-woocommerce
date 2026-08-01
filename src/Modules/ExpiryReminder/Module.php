<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\ExpiryReminder;

use Moksafocou\Modules\AbstractModule;
use Moksafocou\Support\Cron;

defined( 'ABSPATH' ) || exit;

/**
 * 'Coupon expiry reminder' module — lazy-loaded, boots only when moksafocou_expiry_enabled is 'yes'.
 * Once a day (via the shared cron heartbeat) it emails customers whose personal coupons are about
 * to expire, driving urgency on the coupons that the MyAccount / remarketing flows handed them.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'expiry';
	}

	public function label(): string {
		return __( 'Coupon expiry reminder', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Automatically email customers each day to remind them their "exclusive coupon is about to expire", encouraging use before the deadline', 'moksa-coupons-for-woocommerce' );
	}

	public function boot(): void {
		add_action( Cron::HOOK, array( Runtime::class, 'run' ) );
	}
}
