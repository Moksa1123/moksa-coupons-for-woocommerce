<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\MixMatch\MixMatchCalc;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the mix & match bundle maths: bundle counting (once/repeat), priciest-first
 * selection, fixed_total per-line allocation (sum conserved), percent, the short flag, and the
 * clamp that never raises a price.
 */
final class MixMatchCalcTest extends TestCase {

	public function test_bundles_once_vs_repeat(): void {
		// 7 members, N=3 → 2 bundles. once → 1; repeat limit 1 → 1; limit 0 → 2.
		$lines = array(
			array(
				'key'   => 'a',
				'qty'   => 7,
				'price' => 100.0,
			),
		);

		$once = MixMatchCalc::compute(
			array(
				'qty'          => 3,
				'price_mode'   => 'percent',
				'price_value'  => 10,
				'deal_mode'    => 'once',
				'repeat_limit' => 0,
			),
			$lines
		);
		$this->assertSame( 1, $once['bundles'] );
		$this->assertSame( 3, $once['priced_units'] );

		$capped = MixMatchCalc::compute(
			array(
				'qty'          => 3,
				'price_mode'   => 'percent',
				'price_value'  => 10,
				'deal_mode'    => 'repeat',
				'repeat_limit' => 1,
			),
			$lines
		);
		$this->assertSame( 1, $capped['bundles'] );

		$full = MixMatchCalc::compute(
			array(
				'qty'          => 3,
				'price_mode'   => 'percent',
				'price_value'  => 10,
				'deal_mode'    => 'repeat',
				'repeat_limit' => 0,
			),
			$lines
		);
		$this->assertSame( 2, $full['bundles'] );
		$this->assertSame( 6, $full['priced_units'] );
	}

	public function test_fixed_total_allocation_conserves_sum(): void {
		// 任選3件$299: originals [100,150,200] → three blended unit prices sum to 299.
		$plan = MixMatchCalc::compute(
			array(
				'qty'          => 3,
				'price_mode'   => 'fixed_total',
				'price_value'  => 299,
				'deal_mode'    => 'once',
				'repeat_limit' => 0,
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 1,
					'price' => 100.0,
				),
				array(
					'key'   => 'b',
					'qty'   => 1,
					'price' => 150.0,
				),
				array(
					'key'   => 'c',
					'qty'   => 1,
					'price' => 200.0,
				),
			)
		);
		$sum  = $plan['rewards']['a']['blended_price'] + $plan['rewards']['b']['blended_price'] + $plan['rewards']['c']['blended_price'];
		$this->assertEqualsWithDelta( 299.0, $sum, 0.0001 );
		$this->assertEqualsWithDelta( 151.0, $plan['total_discount'], 0.0001 );
	}

	public function test_fixed_total_conserves_when_bundle_mixes_above_and_below_target(): void {
		// Regression: N=2, fixed_total=100, items [200, 30] straddle the 50/unit naive target.
		// Must still cost EXACTLY 100 (not 80), via the proportional factor.
		$plan = MixMatchCalc::compute(
			array(
				'qty'          => 2,
				'price_mode'   => 'fixed_total',
				'price_value'  => 100,
				'deal_mode'    => 'once',
				'repeat_limit' => 0,
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 1,
					'price' => 200.0,
				),
				array(
					'key'   => 'b',
					'qty'   => 1,
					'price' => 30.0,
				),
			)
		);
		$sum  = $plan['rewards']['a']['blended_price'] + $plan['rewards']['b']['blended_price'];
		$this->assertEqualsWithDelta( 100.0, $sum, 0.0001 );
		$this->assertEqualsWithDelta( 130.0, $plan['total_discount'], 0.0001 );
		// No unit priced above its original.
		$this->assertLessThanOrEqual( 200.0, $plan['rewards']['a']['blended_price'] );
		$this->assertLessThanOrEqual( 30.0, $plan['rewards']['b']['blended_price'] );
	}

	public function test_percent_mode(): void {
		$plan = MixMatchCalc::compute(
			array(
				'qty'          => 2,
				'price_mode'   => 'percent',
				'price_value'  => 25,
				'deal_mode'    => 'once',
				'repeat_limit' => 0,
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 2,
					'price' => 200.0,
				),
			)
		);
		// 25% off → blended 150 per unit.
		$this->assertEqualsWithDelta( 150.0, $plan['rewards']['a']['blended_price'], 0.0001 );
		$this->assertEqualsWithDelta( 100.0, $plan['total_discount'], 0.0001 );
	}

	public function test_short_when_not_enough_members(): void {
		$plan = MixMatchCalc::compute(
			array(
				'qty'          => 3,
				'price_mode'   => 'fixed_total',
				'price_value'  => 299,
				'deal_mode'    => 'once',
				'repeat_limit' => 0,
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 2,
					'price' => 100.0,
				),
			)
		);
		$this->assertSame( 0, $plan['bundles'] );
		$this->assertTrue( $plan['short'] );
		$this->assertSame( array(), $plan['rewards'] );
	}

	public function test_priciest_units_are_bundled_when_over_capacity(): void {
		// N=2, one bundle = 2 priced units; with A×1@200, B×1@150, C×1@100 the two priciest (A,B) price.
		$plan = MixMatchCalc::compute(
			array(
				'qty'          => 2,
				'price_mode'   => 'percent',
				'price_value'  => 50,
				'deal_mode'    => 'once',
				'repeat_limit' => 0,
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 1,
					'price' => 200.0,
				),
				array(
					'key'   => 'b',
					'qty'   => 1,
					'price' => 150.0,
				),
				array(
					'key'   => 'c',
					'qty'   => 1,
					'price' => 100.0,
				),
			)
		);
		$this->assertArrayHasKey( 'a', $plan['rewards'] );
		$this->assertArrayHasKey( 'b', $plan['rewards'] );
		$this->assertArrayNotHasKey( 'c', $plan['rewards'] );
	}

	public function test_fixed_total_clamps_not_above_original(): void {
		// price_value 1000 over a 3-unit bundle (target 333) but units are cheaper → no price rises.
		$plan = MixMatchCalc::compute(
			array(
				'qty'          => 3,
				'price_mode'   => 'fixed_total',
				'price_value'  => 1000,
				'deal_mode'    => 'once',
				'repeat_limit' => 0,
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 3,
					'price' => 100.0,
				),
			)
		);
		// target 333 > 100 → unit_discount clamped to 0; blended stays 100; no discount.
		$this->assertEqualsWithDelta( 100.0, $plan['rewards']['a']['blended_price'], 0.0001 );
		$this->assertEqualsWithDelta( 0.0, $plan['total_discount'], 0.0001 );
	}

	public function test_partial_line_blends_taken_units(): void {
		// One line qty 5, N=3, once → 3 of 5 priced. fixed_total 150 → target 50/unit.
		$plan = MixMatchCalc::compute(
			array(
				'qty'          => 3,
				'price_mode'   => 'fixed_total',
				'price_value'  => 150,
				'deal_mode'    => 'once',
				'repeat_limit' => 0,
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 5,
					'price' => 100.0,
				),
			)
		);
		// 3 units discounted to 50 (disc 50 each), 2 at full → blended 100 - (50*3)/5 = 70.
		$this->assertSame( 3, $plan['rewards']['a']['disc_qty'] );
		$this->assertEqualsWithDelta( 70.0, $plan['rewards']['a']['blended_price'], 0.0001 );
	}

	public function test_percent_clamped_to_100(): void {
		$plan = MixMatchCalc::compute(
			array(
				'qty'          => 1,
				'price_mode'   => 'percent',
				'price_value'  => 150,
				'deal_mode'    => 'once',
				'repeat_limit' => 0,
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 1,
					'price' => 80.0,
				),
			)
		);
		// 150% clamped to 100% → free; blended 0.
		$this->assertEqualsWithDelta( 0.0, $plan['rewards']['a']['blended_price'], 0.0001 );
	}

	public function test_member_units_and_bundles_reported(): void {
		$plan = MixMatchCalc::compute(
			array(
				'qty'          => 2,
				'price_mode'   => 'percent',
				'price_value'  => 10,
				'deal_mode'    => 'repeat',
				'repeat_limit' => 0,
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 2,
					'price' => 50.0,
				),
				array(
					'key'   => 'b',
					'qty'   => 3,
					'price' => 50.0,
				),
			)
		);
		$this->assertSame( 5, $plan['member_units'] );
		$this->assertSame( 2, $plan['bundles'] );
		$this->assertSame( 4, $plan['priced_units'] );
	}
}
