<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Support\CouponType;
use PHPUnit\Framework\TestCase;

/**
 * Guards that every coupon type a customer can see resolves to a label, never the raw slug.
 */
final class CouponTypeTest extends TestCase {

	/** @dataProvider knownTypes */
	public function test_known_types_have_labels( string $type ): void {
		$label = CouponType::label( $type );
		$this->assertNotSame( $type, $label, "type {$type} leaked its raw slug" );
		$this->assertNotSame( '', $label );
	}

	/** @return array<int,array{0:string}> */
	public static function knownTypes(): array {
		return array(
			array( 'percent' ),
			array( 'fixed_cart' ),
			array( 'fixed_product' ),
			array( 'moksafocou_bogo' ),
			array( 'moksafocou_cashback' ),
			array( 'moksafocou_nth_item' ),
			array( 'moksafocou_mixmatch' ),
		);
	}

	public function test_empty_type_is_dash(): void {
		$this->assertSame( '—', CouponType::label( '' ) );
	}

	public function test_cashback_is_not_raw_slug(): void {
		// The specific regression: cashback must not surface as "moksafocou_cashback".
		$this->assertStringNotContainsString( 'moksafocou_', CouponType::label( 'moksafocou_cashback' ) );
		$this->assertStringNotContainsString( 'moksafocou_', CouponType::label( 'moksafocou_bogo' ) );
	}
}
