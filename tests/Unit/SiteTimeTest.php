<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Support\SiteTime;
use PHPUnit\Framework\TestCase;

/**
 * Timezone-handling tests for coupon schedules. Without WordPress loaded, SiteTime::tz()
 * falls back to UTC, so parsed wall-clock can be read back deterministically with gmdate.
 * The point of these tests: the TIME-of-day must never be silently dropped to midnight,
 * whichever shape the stored value takes.
 */
final class SiteTimeTest extends TestCase {

	public function test_all_datetime_shapes_parse_to_the_same_instant(): void {
		$canonical = SiteTime::to_timestamp( '2026-07-01 14:30:00' );
		$this->assertNotNull( $canonical );
		$this->assertSame( $canonical, SiteTime::to_timestamp( '2026-07-01 14:30' ), 'seconds-omitted must match' );
		$this->assertSame( $canonical, SiteTime::to_timestamp( '2026-07-01T14:30' ), 'datetime-local "T" must match' );
		$this->assertSame( $canonical, SiteTime::to_timestamp( '2026-07-01T14:30:00' ), 'datetime-local with seconds must match' );
	}

	public function test_time_of_day_is_preserved_not_dropped_to_midnight(): void {
		$ts = SiteTime::to_timestamp( '2026-07-01T14:30' );
		$this->assertSame( '14:30', gmdate( 'H:i', (int) $ts ), 'the wall-clock time must survive parsing' );
		// Regression guard: the timed value must NOT collapse onto the bare-date midnight.
		$this->assertNotSame( SiteTime::to_timestamp( '2026-07-01' ), $ts );
	}

	public function test_bare_date_is_site_midnight(): void {
		$ts = SiteTime::to_timestamp( '2026-07-01' );
		$this->assertSame( '2026-07-01 00:00:00', gmdate( 'Y-m-d H:i:s', (int) $ts ) );
	}

	public function test_empty_and_invalid_values_return_null(): void {
		$this->assertNull( SiteTime::to_timestamp( '' ) );
		$this->assertNull( SiteTime::to_timestamp( '   ' ) );
		$this->assertNull( SiteTime::to_timestamp( 'not-a-date' ) );
		$this->assertNull( SiteTime::to_timestamp( '2026-13-40 99:99' ), 'rolled-over invalid date must be rejected' );
		$this->assertNull( SiteTime::to_timestamp( '2026-02-30 10:00' ), 'Feb 30 must be rejected' );
	}

	public function test_normalize_canonicalises_every_shape(): void {
		$this->assertSame( '2026-07-01 14:30:00', SiteTime::normalize( '2026-07-01 14:30:00' ) );
		$this->assertSame( '2026-07-01 14:30:00', SiteTime::normalize( '2026-07-01 14:30' ), 'pad seconds' );
		$this->assertSame( '2026-07-01 14:30:00', SiteTime::normalize( '2026-07-01T14:30' ), 'T separator' );
		$this->assertSame( '2026-07-01 00:00:00', SiteTime::normalize( '2026-07-01' ), 'bare date → midnight' );
	}

	public function test_normalize_rejects_empty_and_invalid(): void {
		$this->assertSame( '', SiteTime::normalize( '' ) );
		$this->assertSame( '', SiteTime::normalize( 'garbage' ) );
		$this->assertSame( '', SiteTime::normalize( '2026-13-40 99:99' ) );
		$this->assertSame( '', SiteTime::normalize( '2026-02-30 10:00' ) );
	}

	public function test_now_parts_reads_weekday_and_minutes_in_tz(): void {
		$ts    = SiteTime::to_timestamp( '2026-07-01 14:30:00' ); // a Wednesday, 14:30 UTC in tests
		$parts = SiteTime::now_parts( (int) $ts );
		$this->assertSame( (int) gmdate( 'w', (int) $ts ), $parts['weekday'] );
		$this->assertSame( ( 14 * 60 ) + 30, $parts['minutes'] );
	}
}
