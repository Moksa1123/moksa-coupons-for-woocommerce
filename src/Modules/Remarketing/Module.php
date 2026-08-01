<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Remarketing;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 'Auto-issue a coupon after order completion (remarketing)' module — lazy-loaded, boots only when
 * moksafocou_remarketing_enabled is 'yes'. Completes the marketing loop: a completed order issues
 * the customer a personalised clone of a chosen template coupon, which then appears in their
 * 'My coupons' account page (see the MyAccount module) and can optionally be emailed.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'remarketing';
	}

	public function label(): string {
		return __( 'Auto-issue a coupon after order completion (remarketing)', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'After an order is completed, copy the specified template coupon into a customer-specific coupon based on conditions, automatically added to My Account and optionally emailed', 'moksa-coupons-for-woocommerce' );
	}

	/** Issued personal coupons surface on the 'My coupons' account page — enable it or customers can't find them. */
	public function requires(): array {
		return array( 'myaccount' );
	}

	public function boot(): void {
		Runtime::boot();
		add_action( 'wp_abilities_api_init', array( Ability::class, 'register' ) );
	}
}
