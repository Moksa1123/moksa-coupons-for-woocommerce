<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Admin\FieldsHelpers;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared FieldsHelpers::int_list() normaliser, extracted from the
 * (byte-identical) per-module copies in DiscountTiers / CouponConditions. Pins the
 * documented contract: positive ints only, no dedupe, re-indexed, non-array → [].
 */
final class FieldsHelpersTest extends TestCase {

	/**
	 * @param mixed         $input
	 * @param array<int,int> $expected
	 * @dataProvider intListProvider
	 */
	public function test_int_list( $input, array $expected ): void {
		$this->assertSame( $expected, FieldsHelpers::int_list( $input ) );
	}

	/** @return array<string,array{0:mixed,1:array<int,int>}> */
	public static function intListProvider(): array {
		return array(
			'plain ints'           => array( array( 3, 7, 12 ), array( 3, 7, 12 ) ),
			'numeric strings'      => array( array( '3', '7' ), array( 3, 7 ) ),
			'drops zero, abs neg'  => array( array( 0, -5, 4 ), array( 5, 4 ) ),
			'keeps duplicates'     => array( array( 5, 5, 5 ), array( 5, 5, 5 ) ),
			're-indexes'           => array(
				array(
					2 => 9,
					5 => 8,
				),
				array( 9, 8 ),
			),
			'non-array returns []' => array( 'nope', array() ),
			'null returns []'      => array( null, array() ),
		);
	}
}
