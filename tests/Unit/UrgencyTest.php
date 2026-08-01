<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Support\Urgency;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the coupon-card urgency helpers: source normalisation, deadline pick,
 * remaining-redemptions maths, the stock-badge gate, and the live check.
 */
final class UrgencyTest extends TestCase {

	public function test_source_normalises_to_known_value(): void {
		$this->assertSame( 'expires', Urgency::source( 'expires' ) );
		$this->assertSame( 'schedule', Urgency::source( 'schedule' ) );
		$this->assertSame( 'expires', Urgency::source( 'garbage' ) );
		$this->assertSame( 'expires', Urgency::source( '' ) );
		$this->assertSame( 'expires', Urgency::source( null ) );
	}

	public function test_deadline_ts_picks_by_source(): void {
		$this->assertSame( 100, Urgency::deadline_ts( 'expires', 100, 200 ) );
		$this->assertSame( 200, Urgency::deadline_ts( 'schedule', 100, 200 ) );
		// Chosen source missing → null (even if the other is present).
		$this->assertNull( Urgency::deadline_ts( 'expires', null, 200 ) );
		$this->assertNull( Urgency::deadline_ts( 'schedule', 100, null ) );
		// Non-positive epoch is treated as absent.
		$this->assertNull( Urgency::deadline_ts( 'expires', 0, null ) );
	}

	public function test_remaining_clamps_and_treats_unlimited_as_null(): void {
		$this->assertSame( 10, Urgency::remaining( 10, 0 ) );
		$this->assertSame( 3, Urgency::remaining( 10, 7 ) );
		$this->assertSame( 0, Urgency::remaining( 10, 10 ) );
		// count > limit must not go negative.
		$this->assertSame( 0, Urgency::remaining( 10, 15 ) );
		// Unlimited (null / 0 / negative limit) → null.
		$this->assertNull( Urgency::remaining( null, 5 ) );
		$this->assertNull( Urgency::remaining( 0, 5 ) );
		$this->assertNull( Urgency::remaining( -1, 5 ) );
	}

	public function test_should_show_stock(): void {
		// No finite remaining → never show.
		$this->assertFalse( Urgency::should_show_stock( null, 0 ) );
		// Threshold <= 0 → always show when finite.
		$this->assertTrue( Urgency::should_show_stock( 50, 0 ) );
		$this->assertTrue( Urgency::should_show_stock( 0, 0 ) );
		// Threshold gate: show only at or below.
		$this->assertTrue( Urgency::should_show_stock( 5, 5 ) );
		$this->assertTrue( Urgency::should_show_stock( 3, 5 ) );
		$this->assertFalse( Urgency::should_show_stock( 6, 5 ) );
	}

	public function test_is_live(): void {
		$this->assertTrue( Urgency::is_live( 200, 100 ) );
		$this->assertFalse( Urgency::is_live( 100, 100 ) );
		$this->assertFalse( Urgency::is_live( 50, 100 ) );
		$this->assertFalse( Urgency::is_live( null, 100 ) );
	}
}
