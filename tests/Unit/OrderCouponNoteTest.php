<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Coupon\OrderCouponNote;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the shipping-override label used in the order note. The note-building
 * itself touches WC_Order / WC_Coupon and is verified live, not here.
 */
final class OrderCouponNoteTest extends TestCase {

	public function test_free_label(): void {
		$this->assertSame( 'Free shipping', OrderCouponNote::ship_label( 'free', '', 'NT$' ) );
	}

	public function test_percent_label_includes_value(): void {
		$this->assertSame( 'Shipping discount 50%', OrderCouponNote::ship_label( 'percent', '50', 'NT$' ) );
		$this->assertSame( 'Shipping discount 30%', OrderCouponNote::ship_label( 'percent', '30', '$' ) );
	}

	public function test_fixed_label_includes_symbol_and_value(): void {
		$this->assertSame( 'Shipping discount NT$100', OrderCouponNote::ship_label( 'fixed', '100', 'NT$' ) );
	}

	public function test_unknown_mode_falls_back(): void {
		$this->assertSame( 'Shipping adjustment', OrderCouponNote::ship_label( 'something', '5', 'NT$' ) );
	}
}
