<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\NthItem\NthItemCalc;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the Nth-item discount maths: set counting, once/repeat, cheapest-first selection,
 * cart vs product grouping, blended pricing, and the clamp that keeps a line non-negative.
 */
final class NthItemCalcTest extends TestCase {

	/** @param array<string,mixed> $cfg @param array<int,array<string,mixed>> $lines @return array<string,mixed> */
	private static function calc( array $cfg, array $lines ): array {
		return NthItemCalc::compute( $cfg + array( 'group_by' => 'cart' ), $lines );
	}

	public function test_second_item_half_price(): void {
		// 4 units @100, second-item half price (n=2, percent=50, repeat) → 2 of 4 discounted by 50.
		$plan = self::calc(
			array(
				'n'            => 2,
				'reward_mode'  => 'percent',
				'reward_value' => 50,
				'deal_mode'    => 'repeat',
				'repeat_limit' => 0,
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 4,
					'price' => 100.0,
				),
			)
		);
		$this->assertSame( 2, $plan['discount_units'] );
		$this->assertEqualsWithDelta( 100.0, $plan['total_discount'], 0.0001 );
		// 2 of the line's 4 units discounted 50 each → blended 100 - (50*2)/4 = 75.
		$this->assertEqualsWithDelta( 75.0, $plan['rewards']['a']['blended_price'], 0.0001 );
		$this->assertSame( 2, $plan['rewards']['a']['disc_qty'] );
	}

	public function test_once_vs_repeat(): void {
		$lines = array(
			array(
				'key'   => 'a',
				'qty'   => 6,
				'price' => 100.0,
			),
		);
		$base  = array(
			'n'            => 2,
			'reward_mode'  => 'free',
			'reward_value' => 0,
		);

		$once = self::calc(
			$base + array(
				'deal_mode'    => 'once',
				'repeat_limit' => 0,
			),
			$lines
		);
		$this->assertSame( 1, $once['discount_units'] );

		$repeat = self::calc(
			$base + array(
				'deal_mode'    => 'repeat',
				'repeat_limit' => 0,
			),
			$lines
		);
		$this->assertSame( 3, $repeat['discount_units'] );

		$capped = self::calc(
			$base + array(
				'deal_mode'    => 'repeat',
				'repeat_limit' => 2,
			),
			$lines
		);
		$this->assertSame( 2, $capped['discount_units'] );
	}

	public function test_not_enough_for_a_set(): void {
		$plan = self::calc(
			array(
				'n'            => 2,
				'reward_mode'  => 'percent',
				'reward_value' => 50,
				'deal_mode'    => 'repeat',
				'repeat_limit' => 0,
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 1,
					'price' => 100.0,
				),
			)
		);
		$this->assertSame( 0, $plan['discount_units'] );
		$this->assertSame( array(), $plan['rewards'] );
		$this->assertTrue( $plan['short'] );
		$this->assertEqualsWithDelta( 0.0, $plan['total_discount'], 0.0001 );
	}

	public function test_group_by_cart_pools_across_products_and_discounts_cheapest(): void {
		// A×1@200 + B×1@100, n=2, free → one set → discount the cheaper unit (B).
		$plan = NthItemCalc::compute(
			array(
				'n'            => 2,
				'reward_mode'  => 'free',
				'reward_value' => 0,
				'deal_mode'    => 'repeat',
				'repeat_limit' => 0,
				'group_by'     => 'cart',
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
					'price' => 100.0,
				),
			)
		);
		$this->assertSame( 1, $plan['discount_units'] );
		$this->assertArrayHasKey( 'b', $plan['rewards'] );
		$this->assertArrayNotHasKey( 'a', $plan['rewards'] );
		$this->assertEqualsWithDelta( 0.0, $plan['rewards']['b']['blended_price'], 0.0001 );
	}

	public function test_group_by_product_pools_each_line_separately(): void {
		// A×3 + B×1, n=2, free, group_by product → only A's pool forms a set (3/2=1); B (1/2=0) none.
		$plan = NthItemCalc::compute(
			array(
				'n'            => 2,
				'reward_mode'  => 'free',
				'reward_value' => 0,
				'deal_mode'    => 'repeat',
				'repeat_limit' => 0,
				'group_by'     => 'product',
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 3,
					'price' => 100.0,
				),
				array(
					'key'   => 'b',
					'qty'   => 1,
					'price' => 100.0,
				),
			)
		);
		$this->assertSame( 1, $plan['discount_units'] );
		$this->assertArrayHasKey( 'a', $plan['rewards'] );
		$this->assertArrayNotHasKey( 'b', $plan['rewards'] );
		$this->assertSame( 1, $plan['rewards']['a']['disc_qty'] );
	}

	public function test_fixed_per_item_clamps_to_price(): void {
		// fixed discount 150 on a 100 unit → clamped to 100, blended >= 0.
		$plan = self::calc(
			array(
				'n'            => 2,
				'reward_mode'  => 'fixed_per_item',
				'reward_value' => 150,
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
		$this->assertEqualsWithDelta( 100.0, $plan['rewards']['a']['unit_discount'], 0.0001 );
		$this->assertGreaterThanOrEqual( 0.0, $plan['rewards']['a']['blended_price'] );
		$this->assertEqualsWithDelta( 50.0, $plan['rewards']['a']['blended_price'], 0.0001 );
	}

	public function test_free_zeroes_the_discounted_units(): void {
		$plan = self::calc(
			array(
				'n'            => 3,
				'reward_mode'  => 'free',
				'reward_value' => 0,
				'deal_mode'    => 'repeat',
				'repeat_limit' => 0,
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 3,
					'price' => 90.0,
				),
			)
		);
		// 3 units, 1 set → 1 free; blended 90 - 90/3 = 60.
		$this->assertEqualsWithDelta( 90.0, $plan['total_discount'], 0.0001 );
		$this->assertEqualsWithDelta( 60.0, $plan['rewards']['a']['blended_price'], 0.0001 );
	}

	public function test_unit_discount_percent_is_clamped_0_100(): void {
		$this->assertEqualsWithDelta( 0.0, NthItemCalc::unit_discount( 'percent', -10, 100.0 ), 0.0001 );
		$this->assertEqualsWithDelta( 100.0, NthItemCalc::unit_discount( 'percent', 150, 100.0 ), 0.0001 );
		$this->assertEqualsWithDelta( 40.0, NthItemCalc::unit_discount( 'percent', 40, 100.0 ), 0.0001 );
	}

	public function test_n_below_two_is_clamped(): void {
		// n=1 is meaningless; clamps to 2, so 2 units → 1 discounted.
		$plan = self::calc(
			array(
				'n'            => 1,
				'reward_mode'  => 'free',
				'reward_value' => 0,
				'deal_mode'    => 'repeat',
				'repeat_limit' => 0,
			),
			array(
				array(
					'key'   => 'a',
					'qty'   => 2,
					'price' => 50.0,
				),
			)
		);
		$this->assertSame( 1, $plan['discount_units'] );
	}
}
