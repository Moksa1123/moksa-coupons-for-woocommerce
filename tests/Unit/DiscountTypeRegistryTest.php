<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\Bogo\BogoMeta;
use Moksafocou\Modules\Bogo\Type as BogoType;
use Moksafocou\Modules\MixMatch\MixMatchMeta;
use Moksafocou\Modules\MixMatch\Type as MixMatchType;
use Moksafocou\Modules\NthItem\NthItemMeta;
use Moksafocou\Modules\NthItem\Type as NthItemType;
use Moksafocou\Support\CouponType;
use Moksafocou\Support\DiscountTypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Locks the single-source invariant: discount-type slug/label/badge/special lived in five
 * hand-kept places that had drifted (the BOGO editor label read 'Buy X Get Y (BOGO)' while the
 * card badge read 'Buy X Get Y'). Each surface now reads from DiscountTypeRegistry; these tests
 * fail the moment a module hard-codes a label again.
 */
final class DiscountTypeRegistryTest extends TestCase {

	public function test_every_known_slug_resolves_to_three_non_empty_labels(): void {
		foreach ( array_keys( DiscountTypeRegistry::labels() ) as $slug ) {
			$this->assertNotSame( '', DiscountTypeRegistry::label( $slug ), "{$slug} label empty" );
			$this->assertNotSame( '', DiscountTypeRegistry::badge( $slug ), "{$slug} badge empty" );
			$this->assertNotSame( '', DiscountTypeRegistry::admin_label( $slug ), "{$slug} admin label empty" );
			$this->assertStringNotContainsString( 'moksafocou_', DiscountTypeRegistry::label( $slug ), "{$slug} label leaked raw slug" );
		}
	}

	public function test_special_slugs_are_a_subset_of_known(): void {
		$known = array_keys( DiscountTypeRegistry::labels() );
		foreach ( DiscountTypeRegistry::special_slugs() as $slug ) {
			$this->assertContains( $slug, $known, "special slug {$slug} is not a known type" );
		}
		// The three set_price mechanics, no more no less.
		$this->assertSame(
			array( 'moksafocou_bogo', 'moksafocou_nth_item', 'moksafocou_mixmatch' ),
			DiscountTypeRegistry::special_slugs()
		);
	}

	public function test_cashback_is_special_is_false(): void {
		$this->assertFalse( in_array( 'moksafocou_cashback', DiscountTypeRegistry::special_slugs(), true ) );
		$this->assertTrue( in_array( 'moksafocou_bogo', DiscountTypeRegistry::special_slugs(), true ) );
	}

	public function test_type_key_normalises_unknown_to_other(): void {
		$this->assertSame( 'percent', DiscountTypeRegistry::type_key( 'percent' ) );
		$this->assertSame( 'other', DiscountTypeRegistry::type_key( 'something_else' ) );
		$this->assertSame( 'other', DiscountTypeRegistry::type_key( '' ) );
	}

	public function test_couponType_facade_matches_registry(): void {
		$this->assertSame( DiscountTypeRegistry::labels(), CouponType::labels() );
		$this->assertSame( DiscountTypeRegistry::label( 'moksafocou_bogo' ), CouponType::label( 'moksafocou_bogo' ) );
	}

	/**
	 * The drift guard proper: each module's discount-type registration must emit exactly the
	 * registry's admin label for its slug — never its own hard-coded string.
	 *
	 * @dataProvider moduleTypeRegistrations
	 * @param array<string,string> $registered
	 */
	public function test_module_dropdown_label_comes_from_registry( array $registered, string $slug ): void {
		$this->assertArrayHasKey( $slug, $registered );
		$this->assertSame( DiscountTypeRegistry::admin_label( $slug ), $registered[ $slug ] );
	}

	/** @return array<string,array{0:array<string,string>,1:string}> */
	public static function moduleTypeRegistrations(): array {
		return array(
			'bogo'     => array( BogoType::add_type( array() ), BogoMeta::TYPE ),
			'nth_item' => array( NthItemType::add_type( array() ), NthItemMeta::TYPE ),
			'mixmatch' => array( MixMatchType::add_type( array() ), MixMatchMeta::TYPE ),
		);
	}
}
