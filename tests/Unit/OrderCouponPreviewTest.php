<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Admin\OrderCouponPreview;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the order-preview coupon saving resolver. The filter callback + HTML
 * render touch WC_Order and are verified live, not here.
 */
final class OrderCouponPreviewTest extends TestCase {

	public function test_uses_wc_discount_when_positive(): void {
		$this->assertSame( 160.0, OrderCouponPreview::effective_discount( 160.0, null ) );
		// A BOGO meta is ignored when WC already has a real discount.
		$this->assertSame( 100.0, OrderCouponPreview::effective_discount( 100.0, '500' ) );
	}

	public function test_falls_back_to_bogo_meta_when_wc_discount_is_zero(): void {
		$this->assertSame( 500.0, OrderCouponPreview::effective_discount( 0.0, '500' ) );
		$this->assertSame( 250.5, OrderCouponPreview::effective_discount( 0.0, '250.5' ) );
	}

	public function test_zero_when_no_discount_and_no_numeric_meta(): void {
		$this->assertSame( 0.0, OrderCouponPreview::effective_discount( 0.0, '' ) );
		$this->assertSame( 0.0, OrderCouponPreview::effective_discount( 0.0, null ) );
		$this->assertSame( 0.0, OrderCouponPreview::effective_discount( 0.0, 'free' ) );
	}

	public function test_sum_saving_adds_numeric_ignores_rest(): void {
		$this->assertSame( 50.0, OrderCouponPreview::sum_saving( array( '50' ) ) );
		$this->assertSame( 80.0, OrderCouponPreview::sum_saving( array( '50', 30, '0' ) ) );
		$this->assertSame( 0.0, OrderCouponPreview::sum_saving( array() ) );
		$this->assertSame( 25.5, OrderCouponPreview::sum_saving( array( '25.5', '', null, 'x' ) ) );
	}
}
