<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\Frontend\CardsCache;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the front-end card-wall cache TTL. The transient I/O + flush hooks
 * touch WordPress and are verified live, not here.
 */
final class CardsCacheTest extends TestCase {

	private const NOW = 1_000_000;
	private const MAX = 6 * 3600; // MAX_TTL
	private const MIN = 5 * 60;   // MIN_TTL

	public function test_no_expiries_uses_the_ceiling(): void {
		$this->assertSame( self::MAX, CardsCache::ttl_for( array(), self::NOW ) );
		$this->assertSame( self::MAX, CardsCache::ttl_for( array( null, null ), self::NOW ) );
	}

	public function test_only_past_expiries_use_the_ceiling(): void {
		$this->assertSame(
			self::MAX,
			CardsCache::ttl_for( array( self::NOW - 10, self::NOW - 5000, self::NOW ), self::NOW )
		);
	}

	public function test_picks_the_soonest_future_expiry(): void {
		$this->assertSame(
			3600,
			CardsCache::ttl_for( array( self::NOW + 9000, self::NOW + 3600, null ), self::NOW )
		);
	}

	public function test_clamps_to_floor_for_imminent_expiry(): void {
		$this->assertSame(
			self::MIN,
			CardsCache::ttl_for( array( self::NOW + 30 ), self::NOW )
		);
	}

	public function test_clamps_to_ceiling_for_distant_expiry(): void {
		$this->assertSame(
			self::MAX,
			CardsCache::ttl_for( array( self::NOW + 999_999 ), self::NOW )
		);
	}

	public function test_mixes_past_future_and_null(): void {
		// Soonest future is NOW+200 → below the floor → clamped up to MIN.
		$this->assertSame(
			self::MIN,
			CardsCache::ttl_for( array( self::NOW - 1, self::NOW + 10_000, null, self::NOW + 200 ), self::NOW )
		);
	}
}
