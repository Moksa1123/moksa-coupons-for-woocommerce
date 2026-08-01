<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\FreeGift\GiftConfig;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the gift cart price (free / percent / fixed, clamped ≥ 0).
 */
final class GiftPriceTest extends TestCase {

	public function test_free_is_zero(): void {
		$this->assertSame( 0.0, GiftConfig::gift_price( 100.0, 'free', 0 ) );
		$this->assertSame( 0.0, GiftConfig::gift_price( 100.0, 'free', 999 ) ); // value ignored.
	}

	public function test_percent(): void {
		$this->assertSame( 75.0, GiftConfig::gift_price( 100.0, 'percent', 25 ) );
		$this->assertSame( 0.0, GiftConfig::gift_price( 100.0, 'percent', 100 ) );
		$this->assertSame( 0.0, GiftConfig::gift_price( 100.0, 'percent', 150 ) ); // clamped to 100%.
	}

	public function test_fixed(): void {
		$this->assertSame( 70.0, GiftConfig::gift_price( 100.0, 'fixed', 30 ) );
		$this->assertSame( 0.0, GiftConfig::gift_price( 100.0, 'fixed', 200 ) ); // floored at 0, never negative.
	}

	public function test_unknown_mode_falls_back_to_percent(): void {
		// mode() normalizes unknown → 'free'; gift_price default branch is percent.
		$this->assertSame( 'free', GiftConfig::mode( 'bogus' ) );
		$this->assertSame( 80.0, GiftConfig::gift_price( 100.0, 'percent', 20 ) );
	}
}
