<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Support\PriceMath;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shared set_price math extracted from BogoCalc / NthItemCalc / MixMatchCalc.
 */
final class PriceMathTest extends TestCase {

	public function test_free_mode_discounts_the_whole_unit_price(): void {
		$this->assertSame( 100.0, PriceMath::unit_discount( 'free', 0.0, 100.0 ) );
	}

	public function test_fixed_per_item_is_clamped_to_price(): void {
		$this->assertSame( 30.0, PriceMath::unit_discount( 'fixed_per_item', 30.0, 100.0 ) );
		// A fixed discount larger than the price can never drive the line negative.
		$this->assertSame( 100.0, PriceMath::unit_discount( 'fixed_per_item', 150.0, 100.0 ) );
	}

	public function test_percent_mode_and_bounds(): void {
		$this->assertSame( 40.0, PriceMath::unit_discount( 'percent', 40.0, 100.0 ) );
		$this->assertSame( 100.0, PriceMath::unit_discount( 'percent', 150.0, 100.0 ) ); // >100% clamps to price.
		$this->assertSame( 0.0, PriceMath::unit_discount( 'percent', -10.0, 100.0 ) );   // negative clamps to 0.
	}

	public function test_unknown_mode_falls_back_to_percent(): void {
		$this->assertSame( 25.0, PriceMath::unit_discount( 'mystery', 25.0, 100.0 ) );
	}

	public function test_blended_price_spreads_partial_discount_across_the_line(): void {
		// 1 of 2 units gets 100 off → 100 total discount spread over 2 units = 50/unit → 150/unit price.
		$this->assertSame( 150.0, PriceMath::blended_price( 200.0, 100.0, 1, 2 ) );
		// All units discounted → the full per-unit discount applies (200 - 50 = 150).
		$this->assertSame( 150.0, PriceMath::blended_price( 200.0, 50.0, 2, 2 ) );
		// 1 of 2 units free on a 100 line → 50/unit.
		$this->assertSame( 50.0, PriceMath::blended_price( 100.0, 100.0, 1, 2 ) );
	}

	public function test_blended_price_never_negative_and_guards_zero_qty(): void {
		$this->assertSame( 0.0, PriceMath::blended_price( 100.0, 100.0, 5, 1 ) );
		$this->assertSame( 100.0, PriceMath::blended_price( 100.0, 50.0, 1, 0 ) );
	}
}
