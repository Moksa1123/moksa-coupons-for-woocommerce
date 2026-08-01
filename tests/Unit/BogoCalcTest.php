<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\Bogo\BogoCalc;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the BOGO match + price-blend math (no WooCommerce).
 */
final class BogoCalcTest extends TestCase {

	/** @param array<int,array{key:string,qty:int,price:float,role:string}> $lines */
	private function cfg( array $over = array() ): array {
		return array_merge(
			array(
				'trigger_qty'  => 2,
				'reward_qty'   => 1,
				'reward_mode'  => 'free',
				'reward_value' => 0.0,
				'deal_mode'    => 'once',
				'repeat_limit' => 0,
			),
			$over
		);
	}

	public function test_unit_discount_modes(): void {
		$this->assertSame( 100.0, BogoCalc::unit_discount( 'free', 0, 100 ) );
		$this->assertSame( 25.0, BogoCalc::unit_discount( 'percent', 25, 100 ) );
		$this->assertSame( 100.0, BogoCalc::unit_discount( 'percent', 150, 100 ) ); // clamped to 100%.
		$this->assertSame( 30.0, BogoCalc::unit_discount( 'fixed_per_item', 30, 100 ) );
		$this->assertSame( 100.0, BogoCalc::unit_discount( 'fixed_per_item', 200, 100 ) ); // clamped to price.
	}

	public function test_buy2get1_once_free(): void {
		$lines = array(
			array(
				'key'   => 't',
				'qty'   => 2,
				'price' => 50.0,
				'role'  => 'trigger',
			),
			array(
				'key'   => 'r',
				'qty'   => 1,
				'price' => 30.0,
				'role'  => 'reward',
			),
		);
		$out   = BogoCalc::compute( $this->cfg(), $lines );
		$this->assertTrue( $out['trigger_met'] );
		$this->assertSame( 1, $out['bundles'] );
		$this->assertSame( 30.0, $out['total_discount'] );
		$this->assertSame( 1, $out['rewards']['r']['disc_qty'] );
		$this->assertSame( 0.0, $out['rewards']['r']['blended_price'] );
	}

	public function test_repeat_blended_partial_line(): void {
		$lines = array(
			array(
				'key'   => 't',
				'qty'   => 4,
				'price' => 50.0,
				'role'  => 'trigger',
			),
			array(
				'key'   => 'r',
				'qty'   => 3,
				'price' => 30.0,
				'role'  => 'reward',
			),
		);
		$out   = BogoCalc::compute( $this->cfg( array( 'deal_mode' => 'repeat' ) ), $lines );
		$this->assertSame( 2, $out['bundles'] );          // min(floor(4/2), floor(3/1)) = 2.
		$this->assertSame( 2, $out['rewards']['r']['disc_qty'] );
		$this->assertSame( 60.0, $out['total_discount'] ); // 2 free × 30.
		// blended over the 3-qty line: 30 - (30*2/3) = 10.
		$this->assertEqualsWithDelta( 10.0, $out['rewards']['r']['blended_price'], 0.0001 );
	}

	public function test_repeat_limit_caps_bundles(): void {
		$lines = array(
			array(
				'key'   => 't',
				'qty'   => 4,
				'price' => 50.0,
				'role'  => 'trigger',
			),
			array(
				'key'   => 'r',
				'qty'   => 3,
				'price' => 30.0,
				'role'  => 'reward',
			),
		);
		$out   = BogoCalc::compute(
			$this->cfg(
				array(
					'deal_mode'    => 'repeat',
					'repeat_limit' => 1,
				)
			),
			$lines
		);
		$this->assertSame( 1, $out['bundles'] );
		$this->assertSame( 1, $out['rewards']['r']['disc_qty'] );
	}

	public function test_trigger_not_met(): void {
		$lines = array(
			array(
				'key'   => 't',
				'qty'   => 1,
				'price' => 50.0,
				'role'  => 'trigger',
			),
			array(
				'key'   => 'r',
				'qty'   => 1,
				'price' => 30.0,
				'role'  => 'reward',
			),
		);
		$out   = BogoCalc::compute( $this->cfg(), $lines );
		$this->assertFalse( $out['trigger_met'] );
		$this->assertSame( 0, $out['bundles'] );
		$this->assertSame( array(), $out['rewards'] );
	}

	public function test_reward_short_when_trigger_met_no_reward(): void {
		$lines = array(
			array(
				'key'   => 't',
				'qty'   => 2,
				'price' => 50.0,
				'role'  => 'trigger',
			),
		);
		$out   = BogoCalc::compute( $this->cfg(), $lines );
		$this->assertTrue( $out['trigger_met'] );
		$this->assertTrue( $out['reward_short'] );
		$this->assertSame( 0, $out['bundles'] );
	}

	public function test_percent_mode(): void {
		$lines = array(
			array(
				'key'   => 't',
				'qty'   => 2,
				'price' => 50.0,
				'role'  => 'trigger',
			),
			array(
				'key'   => 'r',
				'qty'   => 1,
				'price' => 100.0,
				'role'  => 'reward',
			),
		);
		$out   = BogoCalc::compute(
			$this->cfg(
				array(
					'reward_mode'  => 'percent',
					'reward_value' => 50.0,
				)
			),
			$lines
		);
		$this->assertSame( 50.0, $out['total_discount'] );
		$this->assertSame( 50.0, $out['rewards']['r']['blended_price'] );
	}

	public function test_cheapest_reward_discounted_first(): void {
		$lines = array(
			array(
				'key'   => 't',
				'qty'   => 2,
				'price' => 50.0,
				'role'  => 'trigger',
			),
			array(
				'key'   => 'expensive',
				'qty'   => 1,
				'price' => 80.0,
				'role'  => 'reward',
			),
			array(
				'key'   => 'cheap',
				'qty'   => 1,
				'price' => 20.0,
				'role'  => 'reward',
			),
		);
		$out   = BogoCalc::compute( $this->cfg(), $lines ); // free, once, 1 reward unit.
		$this->assertArrayHasKey( 'cheap', $out['rewards'] );
		$this->assertArrayNotHasKey( 'expensive', $out['rewards'] );
		$this->assertSame( 20.0, $out['total_discount'] );
	}

	public function test_blended_price_distributes_partial_line_discount_precisely(): void {
		// Reward line has 3 units but only 2 are free → the per-unit set_price must blend the
		// discount across all 3 units so the line total still reflects exactly 2 free units.
		$lines = array(
			array(
				'key'   => 't',
				'qty'   => 2,
				'price' => 50.0,
				'role'  => 'trigger',
			),
			array(
				'key'   => 'r',
				'qty'   => 3,
				'price' => 33.33,
				'role'  => 'reward',
			),
		);
		$out   = BogoCalc::compute( $this->cfg( array( 'reward_qty' => 2 ) ), $lines );
		$this->assertArrayHasKey( 'r', $out['rewards'] );
		$reward = $out['rewards']['r'];
		$this->assertSame( 2, $reward['disc_qty'] );
		$this->assertEqualsWithDelta( 33.33, $reward['unit_discount'], 0.0001 );
		// blended = price − unit_discount × take / qty = 33.33 − 33.33×2/3 ≈ 11.11.
		$this->assertEqualsWithDelta( 11.11, $reward['blended_price'], 0.01 );
		$this->assertEqualsWithDelta( 66.66, $out['total_discount'], 0.01 );
		// Invariant: discounted line total + discount given == original line total.
		$this->assertEqualsWithDelta( 33.33 * 3, ( $reward['blended_price'] * 3 ) + $out['total_discount'], 0.01 );
	}

	public function test_blended_price_never_negative_on_full_free_take(): void {
		$lines = array(
			array(
				'key'   => 't',
				'qty'   => 2,
				'price' => 50.0,
				'role'  => 'trigger',
			),
			array(
				'key'   => 'r',
				'qty'   => 2,
				'price' => 50.0,
				'role'  => 'reward',
			),
		);
		$out   = BogoCalc::compute( $this->cfg( array( 'reward_qty' => 2 ) ), $lines );
		$this->assertArrayHasKey( 'r', $out['rewards'] );
		// 50 − 50×2/2 = 0; the max( 0, … ) guard keeps it non-negative.
		$this->assertSame( 0.0, $out['rewards']['r']['blended_price'] );
		$this->assertGreaterThanOrEqual( 0.0, $out['rewards']['r']['blended_price'] );
	}
}
