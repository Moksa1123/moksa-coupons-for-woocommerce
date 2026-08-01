<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\Remarketing\Rules;
use PHPUnit\Framework\TestCase;

/**
 * Pins the post-purchase remarketing trigger conditions (every order / first order / min total).
 */
final class RemarketingRulesTest extends TestCase {

	public function test_all_always_qualifies(): void {
		$this->assertTrue( Rules::qualifies( 'all', 0.0, 9999.0, 50 ) );
		$this->assertTrue( Rules::qualifies( 'all', 100.0, 0.0, 1 ) );
	}

	public function test_first_order_matches_only_first_completed(): void {
		$this->assertTrue( Rules::qualifies( 'first_order', 100.0, 0.0, 1 ) );
		$this->assertFalse( Rules::qualifies( 'first_order', 100.0, 0.0, 2 ) );
		// Guests are passed a large count so they never count as a first order.
		$this->assertFalse( Rules::qualifies( 'first_order', 100.0, 0.0, PHP_INT_MAX ) );
	}

	public function test_min_total_threshold(): void {
		$this->assertTrue( Rules::qualifies( 'min_total', 1000.0, 1000.0, 5 ) );
		$this->assertTrue( Rules::qualifies( 'min_total', 1500.0, 1000.0, 5 ) );
		$this->assertFalse( Rules::qualifies( 'min_total', 999.99, 1000.0, 5 ) );
	}

	public function test_unknown_condition_falls_back_to_all(): void {
		$this->assertSame( 'all', Rules::normalize_condition( 'bogus' ) );
		$this->assertTrue( Rules::qualifies( 'bogus', 0.0, 9999.0, 99 ) );
	}

	public function test_known_conditions_pass_through(): void {
		$this->assertSame( 'first_order', Rules::normalize_condition( 'first_order' ) );
		$this->assertSame( 'min_total', Rules::normalize_condition( 'min_total' ) );
	}
}
