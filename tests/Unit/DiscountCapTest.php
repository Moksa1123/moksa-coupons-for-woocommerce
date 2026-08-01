<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\DiscountCap\Cap;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the per-item discount-cap budget step (unit-agnostic integers).
 */
final class DiscountCapTest extends TestCase {

	public function test_within_budget_grants_full_discount(): void {
		$step = Cap::cap_step( 3000, 10000 );
		$this->assertSame( 3000, $step['granted'] );
		$this->assertSame( 7000, $step['remaining'] );
	}

	public function test_exceeding_budget_is_capped(): void {
		$step = Cap::cap_step( 8000, 5000 );
		$this->assertSame( 5000, $step['granted'] );
		$this->assertSame( 0, $step['remaining'] );
	}

	public function test_exhausted_budget_grants_nothing(): void {
		$step = Cap::cap_step( 2000, 0 );
		$this->assertSame( 0, $step['granted'] );
		$this->assertSame( 0, $step['remaining'] );
	}

	public function test_negative_budget_clamped(): void {
		$step = Cap::cap_step( 1000, -50 );
		$this->assertSame( 0, $step['granted'] );
		$this->assertSame( 0, $step['remaining'] );
	}

	public function test_cumulative_across_items_equals_cap(): void {
		// Cap 100.00 (10000 cents) over three items of 60, 50, 40 -> total granted = 100.
		$remaining = 10000;
		$granted   = 0;
		foreach ( array( 6000, 5000, 4000 ) as $item ) {
			$step      = Cap::cap_step( $item, $remaining );
			$granted  += $step['granted'];
			$remaining = $step['remaining'];
		}
		$this->assertSame( 10000, $granted );
		$this->assertSame( 0, $remaining );
	}
}
