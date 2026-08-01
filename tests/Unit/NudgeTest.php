<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\Nudge\Nudge;
use PHPUnit\Framework\TestCase;

/**
 * Pins Nudge::remaining() — how much more a shopper must spend to reach the free-shipping threshold.
 */
final class NudgeTest extends TestCase {

	public function test_remaining_to_threshold(): void {
		$this->assertSame( 200.0, Nudge::remaining( 800.0, 1000.0 ) );
	}

	public function test_met_threshold_is_zero(): void {
		$this->assertSame( 0.0, Nudge::remaining( 1000.0, 1000.0 ) );
		$this->assertSame( 0.0, Nudge::remaining( 1200.0, 1000.0 ) );
	}

	public function test_rounds_to_cents(): void {
		$this->assertSame( 0.5, Nudge::remaining( 999.5, 1000.0 ) );
	}
}
