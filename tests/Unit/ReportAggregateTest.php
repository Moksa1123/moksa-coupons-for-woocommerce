<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\Reports\ReportService;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the coupon-performance accumulator.
 */
final class ReportAggregateTest extends TestCase {

	public function test_empty(): void {
		$this->assertSame( array(), ReportService::aggregate( array() ) );
	}

	public function test_sums_per_code(): void {
		$out = ReportService::aggregate(
			array(
				array(
					'code'     => 'save10',
					'discount' => 100.0,
				),
				array(
					'code'     => 'save10',
					'discount' => 50.0,
				),
				array(
					'code'     => 'vip',
					'discount' => 30.0,
				),
			)
		);
		$this->assertSame( 2, $out['save10']['orders'] );
		$this->assertSame( 150.0, $out['save10']['discount'] );
		$this->assertSame( 1, $out['vip']['orders'] );
		$this->assertSame( 30.0, $out['vip']['discount'] );
	}

	public function test_code_is_normalized_and_blank_skipped(): void {
		$out = ReportService::aggregate(
			array(
				array(
					'code'     => 'SAVE10',
					'discount' => 10.0,
				),
				array(
					'code'     => ' save10 ',
					'discount' => 5.0,
				),
				array(
					'code'     => '',
					'discount' => 999.0,
				),
			)
		);
		$this->assertArrayHasKey( 'save10', $out );
		$this->assertSame( 2, $out['save10']['orders'] );
		$this->assertSame( 15.0, $out['save10']['discount'] );
		$this->assertCount( 1, $out );
	}
}
