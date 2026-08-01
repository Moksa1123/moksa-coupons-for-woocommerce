<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\CouponConditions\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the product / category EXCLUSION verdict (cart must NOT contain).
 */
final class ExcludeConditionTest extends TestCase {

	public function test_no_exclusions_passes(): void {
		$this->assertNull( Validator::exclude_verdict( array(), array(), array( 10 ), array( 5 ) ) );
	}

	public function test_excluded_product_present_blocks(): void {
		$this->assertSame( 'excl_products', Validator::exclude_verdict( array( 10 ), array(), array( 10, 20 ), array() ) );
	}

	public function test_excluded_product_absent_passes(): void {
		$this->assertNull( Validator::exclude_verdict( array( 99 ), array(), array( 10, 20 ), array() ) );
	}

	public function test_excluded_category_present_blocks(): void {
		$this->assertSame( 'excl_categories', Validator::exclude_verdict( array(), array( 5 ), array(), array( 5, 7 ) ) );
	}

	public function test_excluded_category_absent_passes(): void {
		$this->assertNull( Validator::exclude_verdict( array(), array( 5 ), array(), array( 7, 9 ) ) );
	}

	public function test_products_checked_before_categories(): void {
		$this->assertSame( 'excl_products', Validator::exclude_verdict( array( 10 ), array( 5 ), array( 10 ), array( 5 ) ) );
	}
}
