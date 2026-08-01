<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\CouponConditions\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the product / category cart-presence verdict.
 */
final class ProductConditionTest extends TestCase {

	public function test_no_requirements_passes(): void {
		$this->assertNull( Validator::product_verdict( array(), 'any', array(), 'any', array( 10 ), array( 5 ) ) );
	}

	public function test_products_any_matches_one(): void {
		$this->assertNull( Validator::product_verdict( array( 10, 20 ), 'any', array(), 'any', array( 20, 99 ), array() ) );
	}

	public function test_products_any_no_match_blocks(): void {
		$this->assertSame( 'products', Validator::product_verdict( array( 10, 20 ), 'any', array(), 'any', array( 99 ), array() ) );
	}

	public function test_products_all_requires_every_one(): void {
		$this->assertNull( Validator::product_verdict( array( 10, 20 ), 'all', array(), 'any', array( 10, 20, 30 ), array() ) );
		$this->assertSame( 'products', Validator::product_verdict( array( 10, 20 ), 'all', array(), 'any', array( 10 ), array() ) );
	}

	public function test_categories_any(): void {
		$this->assertNull( Validator::product_verdict( array(), 'any', array( 5 ), 'any', array(), array( 5, 7 ) ) );
		$this->assertSame( 'categories', Validator::product_verdict( array(), 'any', array( 5 ), 'any', array(), array( 7 ) ) );
	}

	public function test_categories_all(): void {
		$this->assertNull( Validator::product_verdict( array(), 'any', array( 5, 7 ), 'all', array(), array( 5, 7, 9 ) ) );
		$this->assertSame( 'categories', Validator::product_verdict( array(), 'any', array( 5, 7 ), 'all', array(), array( 5 ) ) );
	}

	public function test_products_checked_before_categories(): void {
		// Both fail → products reported first (stable precedence).
		$this->assertSame( 'products', Validator::product_verdict( array( 10 ), 'any', array( 5 ), 'any', array( 99 ), array( 88 ) ) );
	}

	public function test_both_satisfied(): void {
		$this->assertNull( Validator::product_verdict( array( 10 ), 'any', array( 5 ), 'any', array( 10 ), array( 5 ) ) );
	}
}
