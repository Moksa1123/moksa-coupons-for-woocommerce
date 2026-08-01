<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\Frontend\Catalog;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the frontend catalog: advertisability + percent display + type key.
 */
final class CatalogTest extends TestCase {

	public function test_advertisable_when_published_optedin_unexpired(): void {
		$this->assertTrue( Catalog::is_advertisable( 'publish', 'yes', null, 1000 ) );
		$this->assertTrue( Catalog::is_advertisable( 'publish', 'yes', 2000, 1000 ) );
	}

	public function test_not_advertisable_when_draft_or_optout(): void {
		$this->assertFalse( Catalog::is_advertisable( 'draft', 'yes', null, 1000 ) );
		$this->assertFalse( Catalog::is_advertisable( 'publish', '', null, 1000 ) );
	}

	public function test_not_advertisable_when_expired(): void {
		$this->assertFalse( Catalog::is_advertisable( 'publish', 'yes', 1000, 1000 ) ); // now == valid_until (exclusive).
		$this->assertFalse( Catalog::is_advertisable( 'publish', 'yes', 1000, 1500 ) );
	}

	public function test_percent_display_trims(): void {
		$this->assertSame( '15', Catalog::percent_display( 15.0 ) );
		$this->assertSame( '12.5', Catalog::percent_display( 12.5 ) );
		$this->assertSame( '12.55', Catalog::percent_display( 12.55 ) );
		$this->assertSame( '0', Catalog::percent_display( 0.0 ) );
	}

	public function test_type_key_maps_known_else_other(): void {
		$this->assertSame( 'percent', Catalog::type_key( 'percent' ) );
		$this->assertSame( 'moksafocou_bogo', Catalog::type_key( 'moksafocou_bogo' ) );
		$this->assertSame( 'other', Catalog::type_key( 'something_else' ) );
	}
}
