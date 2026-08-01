<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\MyAccount\CouponQuery;
use PHPUnit\Framework\TestCase;

/**
 * Pins CouponQuery::should_display() — the rule deciding which of a customer's coupons appear on
 * the 'My coupons' account page (published, not expired, not used up).
 */
final class MyAccountCouponQueryTest extends TestCase {

	private const NOW = 1_000_000;

	public function test_published_with_no_limits_shows(): void {
		$this->assertTrue( CouponQuery::should_display( 'publish', null, 0, 0, self::NOW ) );
	}

	public function test_future_expiry_shows(): void {
		$this->assertTrue( CouponQuery::should_display( 'publish', self::NOW + 100, 0, 0, self::NOW ) );
	}

	public function test_past_expiry_hidden(): void {
		$this->assertFalse( CouponQuery::should_display( 'publish', self::NOW - 1, 0, 0, self::NOW ) );
	}

	public function test_non_published_hidden(): void {
		$this->assertFalse( CouponQuery::should_display( 'draft', null, 0, 0, self::NOW ) );
		$this->assertFalse( CouponQuery::should_display( 'pending', null, 0, 0, self::NOW ) );
	}

	public function test_used_up_hidden(): void {
		$this->assertFalse( CouponQuery::should_display( 'publish', null, 3, 3, self::NOW ) );
	}

	public function test_under_usage_limit_shows(): void {
		$this->assertTrue( CouponQuery::should_display( 'publish', null, 2, 3, self::NOW ) );
	}

	public function test_zero_limit_means_unlimited(): void {
		$this->assertTrue( CouponQuery::should_display( 'publish', null, 999, 0, self::NOW ) );
	}
}
