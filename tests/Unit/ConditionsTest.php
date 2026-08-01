<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\CouponConditions\Validator;
use Moksafocou\Support\SiteTime;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the condition verdict helpers (no WooCommerce needed).
 */
final class ConditionsTest extends TestCase {

	public function test_schedule_verdict(): void {
		$now = 1000;
		$this->assertNull( Validator::schedule_verdict( null, null, $now ) );
		$this->assertNull( Validator::schedule_verdict( 500, 1500, $now ) );      // within window
		$this->assertSame( 'start', Validator::schedule_verdict( 1500, null, $now ) ); // not yet started
		$this->assertSame( 'expire', Validator::schedule_verdict( null, 500, $now ) ); // already ended
		$this->assertNull( Validator::schedule_verdict( 1000, 1000, $now ) );    // exactly at boundary = valid
	}

	public function test_role_is_blocked_allowed(): void {
		// allowed: blocked when user has none of the roles.
		$this->assertTrue( Validator::role_is_blocked( 'allowed', array( 'wholesale' ), array( 'customer' ) ) );
		$this->assertFalse( Validator::role_is_blocked( 'allowed', array( 'wholesale' ), array( 'wholesale', 'customer' ) ) );
	}

	public function test_role_is_blocked_disallowed(): void {
		// disallowed: blocked when user has any of the roles.
		$this->assertTrue( Validator::role_is_blocked( 'disallowed', array( 'guest' ), array( 'guest' ) ) );
		$this->assertFalse( Validator::role_is_blocked( 'disallowed', array( 'guest' ), array( 'customer' ) ) );
	}

	public function test_role_no_config_never_blocks(): void {
		$this->assertFalse( Validator::role_is_blocked( 'allowed', array(), array( 'customer' ) ) );
		$this->assertFalse( Validator::role_is_blocked( 'disallowed', array(), array( 'customer' ) ) );
	}

	public function test_region_is_blocked(): void {
		// allow: blocked when the destination is NOT listed.
		$this->assertFalse( Validator::region_is_blocked( 'allow', array( 'TW', 'JP' ), 'TW' ) );
		$this->assertTrue( Validator::region_is_blocked( 'allow', array( 'TW', 'JP' ), 'US' ) );
		// disallow: blocked when it IS listed.
		$this->assertTrue( Validator::region_is_blocked( 'disallow', array( 'US' ), 'US' ) );
		$this->assertFalse( Validator::region_is_blocked( 'disallow', array( 'US' ), 'TW' ) );
	}

	public function test_payment_is_blocked(): void {
		// allow: blocked when the gateway is NOT listed.
		$this->assertFalse( Validator::payment_is_blocked( 'allow', array( 'bacs', 'cod' ), 'bacs' ) );
		$this->assertTrue( Validator::payment_is_blocked( 'allow', array( 'bacs' ), 'cod' ) );
		// disallow: blocked when it IS listed.
		$this->assertTrue( Validator::payment_is_blocked( 'disallow', array( 'cod' ), 'cod' ) );
		$this->assertFalse( Validator::payment_is_blocked( 'disallow', array( 'cod' ), 'bacs' ) );
	}

	public function test_sitetime_to_timestamp(): void {
		$this->assertNull( SiteTime::to_timestamp( '' ) );
		$this->assertNull( SiteTime::to_timestamp( 'not-a-date' ) );
		// In the test harness wp_timezone() is absent → UTC.
		$this->assertSame( gmmktime( 0, 0, 0, 12, 1, 2026 ), SiteTime::to_timestamp( '2026-12-01 00:00:00' ) );
		$this->assertSame( gmmktime( 0, 0, 0, 12, 1, 2026 ), SiteTime::to_timestamp( '2026-12-01' ) );
	}

	public function test_customer_first_only(): void {
		// First-order-only: a guest / brand-new customer (0 orders) passes; any history fails.
		$this->assertNull( Validator::customer_verdict( true, null, null, null, null, 0, 0.0 ) );
		$this->assertSame( 'first_only', Validator::customer_verdict( true, null, null, null, null, 1, 50.0 ) );
	}

	public function test_customer_order_count_bounds(): void {
		$this->assertSame( 'min_orders', Validator::customer_verdict( false, 3, null, null, null, 2, 0.0 ) );
		$this->assertNull( Validator::customer_verdict( false, 3, null, null, null, 3, 0.0 ) );
		$this->assertSame( 'max_orders', Validator::customer_verdict( false, null, 5, null, null, 6, 0.0 ) );
		$this->assertNull( Validator::customer_verdict( false, null, 5, null, null, 5, 0.0 ) );
	}

	public function test_customer_total_spent_bounds(): void {
		$this->assertSame( 'min_spent', Validator::customer_verdict( false, null, null, 1000.0, null, 0, 500.0 ) );
		$this->assertNull( Validator::customer_verdict( false, null, null, 1000.0, null, 0, 1500.0 ) );
		$this->assertSame( 'max_spent', Validator::customer_verdict( false, null, null, null, 1000.0, 0, 1500.0 ) );
	}

	public function test_customer_all_conditions_pass(): void {
		$this->assertNull( Validator::customer_verdict( false, 1, 10, 100.0, 9999.0, 5, 2500.0 ) );
	}
}
