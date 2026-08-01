<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\CouponConditions\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the day-of-week / time-of-day verdict and HH:MM parsing.
 */
final class DayTimeTest extends TestCase {

	public function test_no_constraints_passes(): void {
		$this->assertNull( Validator::daytime_verdict( array(), null, null, 3, 600 ) );
	}

	public function test_day_allowed(): void {
		$this->assertNull( Validator::daytime_verdict( array( 0, 6 ), null, null, 6, 600 ) ); // Saturday ok.
	}

	public function test_day_blocked(): void {
		$this->assertSame( 'day', Validator::daytime_verdict( array( 0, 6 ), null, null, 3, 600 ) ); // Wed not in {Sun,Sat}.
	}

	public function test_time_window_inside(): void {
		// 09:00–17:00 = 540–1020; 600 (10:00) inside.
		$this->assertNull( Validator::daytime_verdict( array(), 540, 1020, 3, 600 ) );
	}

	public function test_time_window_before_and_after(): void {
		$this->assertSame( 'time', Validator::daytime_verdict( array(), 540, 1020, 3, 500 ) ); // 08:20 before.
		$this->assertSame( 'time', Validator::daytime_verdict( array(), 540, 1020, 3, 1100 ) ); // 18:20 after.
	}

	public function test_overnight_window(): void {
		// 22:00–02:00 = 1320–120 (start > end). 23:00 (1380) and 01:00 (60) inside; 12:00 (720) outside.
		$this->assertNull( Validator::daytime_verdict( array(), 1320, 120, 3, 1380 ) );
		$this->assertNull( Validator::daytime_verdict( array(), 1320, 120, 3, 60 ) );
		$this->assertSame( 'time', Validator::daytime_verdict( array(), 1320, 120, 3, 720 ) );
	}

	public function test_open_ended_bounds(): void {
		$this->assertSame( 'time', Validator::daytime_verdict( array(), 540, null, 3, 500 ) ); // before start, no end.
		$this->assertNull( Validator::daytime_verdict( array(), 540, null, 3, 600 ) );
		$this->assertSame( 'time', Validator::daytime_verdict( array(), null, 1020, 3, 1100 ) ); // after end, no start.
	}

	public function test_day_checked_before_time(): void {
		$this->assertSame( 'day', Validator::daytime_verdict( array( 1 ), 540, 1020, 3, 500 ) );
	}

	public function test_parse_weekdays_keeps_sunday_zero(): void {
		// Regression: weekday 0 (Sunday) must survive (id_list() would drop it).
		$this->assertSame( array( 0, 3 ), Validator::parse_weekdays( array( 0, 3, 7, 'x', 3 ) ) );
		$this->assertSame( array(), Validator::parse_weekdays( '' ) );
	}

	public function test_hhmm_parsing(): void {
		$this->assertSame( 0, Validator::hhmm_to_min( '00:00' ) );
		$this->assertSame( 540, Validator::hhmm_to_min( '09:00' ) );
		$this->assertSame( 1439, Validator::hhmm_to_min( '23:59' ) );
		$this->assertNull( Validator::hhmm_to_min( '' ) );
		$this->assertNull( Validator::hhmm_to_min( '24:00' ) );
		$this->assertNull( Validator::hhmm_to_min( '9am' ) );
	}
}
