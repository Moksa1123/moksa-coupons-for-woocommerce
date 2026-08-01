<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Support\Tiers;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the tiered-discount maths: parse / validate, the percent + fixed + mixed tier
 * selection (best actual discount wins), the progress nudge, and backward compatibility with the
 * pre-redesign { min_subtotal, min_qty, percent } shape.
 */
final class TiersTest extends TestCase {

	public function test_parse_validates_percent_and_fixed(): void {
		$rows = Tiers::parse(
			array(
				array(
					'threshold' => 1000,
					'kind'      => 'percent',
					'value'     => 20,
				),
				array(
					'threshold' => 2000,
					'kind'      => 'fixed',
					'value'     => 300,
				),
				array(
					'threshold' => 5,
					'kind'      => 'percent',
					'value'     => 150,
				),   // percent > 100 → dropped.
				array(
					'threshold' => 5,
					'kind'      => 'fixed',
					'value'     => 0,
				),       // fixed <= 0 → dropped.
				'garbage',
			)
		);
		$this->assertCount( 2, $rows );
		$this->assertSame( 'percent', $rows[0]['kind'] );
		$this->assertSame( 20.0, $rows[0]['value'] );
		$this->assertSame( 'fixed', $rows[1]['kind'] );
		$this->assertSame( 300.0, $rows[1]['value'] );
	}

	public function test_parse_reads_legacy_subtotal_percent_rows(): void {
		// Pre-redesign coupons stored { min_subtotal, min_qty, percent } with no kind.
		$rows = Tiers::parse(
			array(
				array(
					'min_subtotal' => 1000,
					'min_qty'      => 0,
					'percent'      => 10,
				),
				array(
					'min_subtotal' => 2000,
					'min_qty'      => 0,
					'percent'      => 20,
				),
			)
		);
		$this->assertCount( 2, $rows );
		$this->assertSame( 'percent', $rows[0]['kind'] );
		$this->assertSame( 1000.0, $rows[0]['threshold'] );
		$this->assertSame( 10.0, $rows[0]['value'] );
		$this->assertSame( 2000.0, $rows[1]['threshold'] );
	}

	public function test_canonical_json_round_trips_and_empties(): void {
		$json = Tiers::canonical_json(
			array(
				array(
					'threshold' => 1000,
					'kind'      => 'fixed',
					'value'     => 250,
				),
			)
		);
		$rows = Tiers::parse( $json );
		$this->assertSame( 'fixed', $rows[0]['kind'] );
		$this->assertSame( 250.0, $rows[0]['value'] );
		$this->assertSame( '', Tiers::canonical_json( array() ) );
		$this->assertSame( '', Tiers::canonical_json( 'garbage' ) );
	}

	public function test_basis_normalises(): void {
		$this->assertSame( 'subtotal', Tiers::basis( '' ) );
		$this->assertSame( 'subtotal', Tiers::basis( 'nonsense' ) );
		$this->assertSame( 'quantity', Tiers::basis( 'quantity' ) );
		$this->assertSame( 'weight', Tiers::basis( 'weight' ) );
	}

	public function test_resolve_percent_only_picks_highest(): void {
		$tiers = Tiers::parse(
			array(
				array(
					'threshold' => 0,
					'kind'      => 'percent',
					'value'     => 10,
				),
				array(
					'threshold' => 1000,
					'kind'      => 'percent',
					'value'     => 20,
				),
			)
		);
		// the user's exact scenario: <1000 → 10%, >=1000 → 20%. base == measure for subtotal basis.
		$this->assertSame( 10.0, Tiers::resolve( $tiers, 999.99, 999.99 )['value'] );
		$this->assertSame( 20.0, Tiers::resolve( $tiers, 1000.0, 1000.0 )['value'] );
		$this->assertSame( 200.0, Tiers::resolve( $tiers, 1000.0, 1000.0 )['amount'] );
	}

	public function test_resolve_fixed_caps_at_base(): void {
		$tiers  = Tiers::parse(
			array(
				array(
					'threshold' => 1000,
					'kind'      => 'fixed',
					'value'     => 300,
				),
			)
		);
		$active = Tiers::resolve( $tiers, 1500.0, 1500.0 );
		$this->assertSame( 'fixed', $active['kind'] );
		$this->assertSame( 300.0, $active['amount'] );
		// fixed cannot discount more than the (targeted) base.
		$this->assertSame( 200.0, Tiers::resolve( $tiers, 1000.0, 200.0 )['amount'] );
	}

	public function test_resolve_mixed_picks_best_actual_discount(): void {
		$tiers = Tiers::parse(
			array(
				array(
					'threshold' => 1000,
					'kind'      => 'percent',
					'value'     => 10,
				),
				array(
					'threshold' => 2000,
					'kind'      => 'fixed',
					'value'     => 300,
				),
			)
		);
		// At 2500: percent gives 250, fixed gives 300 → fixed wins (better deal).
		$best = Tiers::resolve( $tiers, 2500.0, 2500.0 );
		$this->assertSame( 'fixed', $best['kind'] );
		$this->assertSame( 300.0, $best['amount'] );
		// At 1500: only the percent tier qualifies → 150.
		$this->assertSame( 'percent', Tiers::resolve( $tiers, 1500.0, 1500.0 )['kind'] );
		$this->assertSame( 150.0, Tiers::resolve( $tiers, 1500.0, 1500.0 )['amount'] );
	}

	public function test_resolve_threshold_is_float_tolerant(): void {
		$tiers = Tiers::parse(
			array(
				array(
					'threshold' => 1000,
					'kind'      => 'percent',
					'value'     => 20,
				),
			)
		);
		$this->assertSame( 20.0, Tiers::resolve( $tiers, 999.9999999, 999.9999999 )['value'], 'a hair below still qualifies' );
		$this->assertSame( 0.0, Tiers::resolve( $tiers, 999.99, 999.99 )['amount'], 'genuinely below does not' );
	}

	public function test_next_tier_reports_nearest_better_reachable(): void {
		$tiers = Tiers::parse(
			array(
				array(
					'threshold' => 1000,
					'kind'      => 'percent',
					'value'     => 10,
				),
				array(
					'threshold' => 2000,
					'kind'      => 'percent',
					'value'     => 15,
				),
				array(
					'threshold' => 3000,
					'kind'      => 'fixed',
					'value'     => 800,
				),
			)
		);
		$next  = Tiers::next_tier( $tiers, 1500.0, 1500.0 );
		$this->assertNotNull( $next );
		$this->assertSame( 2000.0, $next['threshold'] );
		$this->assertSame( 500.0, $next['gap'] );
		$this->assertNull( Tiers::next_tier( $tiers, 5000.0, 5000.0 ), 'top tier reached' );
	}

	public function test_line_discount_and_fixed_share(): void {
		$this->assertSame( 30.0, Tiers::line_discount( 300.0, 10.0 ) );
		// distribute a NT$300 fixed tier over a 1000 base: a 250 line gets 300 × 250/1000 = 75.
		$this->assertSame( 75.0, Tiers::fixed_line_share( 250.0, 1000.0, 300.0 ) );
		// fixed value >= base → the cap makes it min(9999,1000)=1000, so a 600 line gets its full 600.
		$this->assertSame( 600.0, Tiers::fixed_line_share( 600.0, 1000.0, 9999.0 ) );
		$this->assertSame( 0.0, Tiers::fixed_line_share( 100.0, 0.0, 300.0 ) );
	}

	public function test_is_targeted_all_products_categories(): void {
		$this->assertTrue( Tiers::is_targeted( 'all', array(), array(), 5, 0, array() ) );
		$this->assertTrue( Tiers::is_targeted( 'products', array( 5 ), array(), 5, 0, array() ) );
		$this->assertTrue( Tiers::is_targeted( 'products', array( 9 ), array(), 5, 9, array() ), 'variation id matches' );
		$this->assertFalse( Tiers::is_targeted( 'products', array( 7 ), array(), 5, 0, array() ) );
		$this->assertTrue( Tiers::is_targeted( 'categories', array(), array( 12 ), 5, 0, array( 12, 3 ) ) );
		$this->assertFalse( Tiers::is_targeted( 'categories', array(), array( 99 ), 5, 0, array( 12, 3 ) ) );
	}
}
