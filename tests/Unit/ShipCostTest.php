<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\ShippingOverride\ShipConfig;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the shipping-override cost (free / percent / fixed, clamped ≥ 0).
 */
final class ShipCostTest extends TestCase {

	public function test_none_returns_base(): void {
		$this->assertSame( 150.0, ShipConfig::ship_cost( 150.0, 'none', 0 ) );
		$this->assertSame( 150.0, ShipConfig::ship_cost( 150.0, 'bogus', 50 ) );
	}

	public function test_free_is_zero(): void {
		$this->assertSame( 0.0, ShipConfig::ship_cost( 150.0, 'free', 0 ) );
	}

	public function test_percent(): void {
		$this->assertSame( 120.0, ShipConfig::ship_cost( 150.0, 'percent', 20 ) );
		$this->assertSame( 0.0, ShipConfig::ship_cost( 150.0, 'percent', 100 ) );
		$this->assertSame( 0.0, ShipConfig::ship_cost( 150.0, 'percent', 150 ) ); // clamped to 100%.
	}

	public function test_fixed(): void {
		$this->assertSame( 100.0, ShipConfig::ship_cost( 150.0, 'fixed', 50 ) );
		$this->assertSame( 0.0, ShipConfig::ship_cost( 150.0, 'fixed', 200 ) ); // never negative.
	}

	public function test_mode_normalizes(): void {
		$this->assertSame( 'free', ShipConfig::mode( 'free' ) );
		$this->assertSame( 'none', ShipConfig::mode( 'bogus' ) );
	}
}
