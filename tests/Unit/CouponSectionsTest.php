<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\CouponConditions\Fields as ConditionsFields;
use Moksafocou\Modules\DiscountTiers\Fields as TiersFields;
use Moksafocou\Modules\AdvancedRules\Fields as AdvRulesFields;
use Moksafocou\Modules\Bogo\Fields as BogoFields;
use Moksafocou\Modules\FreeGift\Fields as GiftFields;
use Moksafocou\Modules\ShippingOverride\Fields as ShippingFields;
use Moksafocou\Modules\StackingControl\Fields as StackingFields;
use Moksafocou\Modules\UrlCoupons\Fields as UrlFields;
use Moksafocou\Modules\Frontend\Fields as FrontendFields;
use Moksafocou\Modules\TabIcons\Icons;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the coupon-settings sections that each feature module hands to
 * Admin\CouponSections. Building a module's sections() is pure (it only assembles
 * descriptors + closures; the render closures are never invoked here), so the data
 * side is unit-testable even though the rendering itself needs WordPress.
 */
final class CouponSectionsTest extends TestCase {

	/** @return array<string,array{0:object,1:int}> module key => [Fields, expected section count]. */
	public static function moduleProvider(): array {
		return array(
			'conditions' => array( new ConditionsFields(), 8 ),
			'tiers'      => array( new TiersFields(), 1 ),
			'advrules'   => array( new AdvRulesFields(), 1 ),
			'bogo'       => array( new BogoFields(), 1 ),
			'gift'       => array( new GiftFields(), 1 ),
			'shipping'   => array( new ShippingFields(), 1 ),
			'stacking'   => array( new StackingFields(), 1 ),
			'url'        => array( new UrlFields(), 1 ),
			'frontend'   => array( new FrontendFields(), 1 ),
		);
	}

	/**
	 * @dataProvider moduleProvider
	 * @param object $fields
	 * @param int    $expected
	 */
	public function test_sections_are_well_formed( $fields, int $expected ): void {
		$sections = $fields->sections();
		$this->assertCount( $expected, $sections );
		foreach ( $sections as $section ) {
			$this->assertIsArray( $section );
			$this->assertArrayHasKey( 'id', $section );
			$this->assertArrayHasKey( 'title', $section );
			$this->assertArrayHasKey( 'render', $section );
			$this->assertIsString( $section['id'] );
			$this->assertStringStartsWith( 'moksafocou_', $section['id'] );
			$this->assertIsString( $section['title'] );
			$this->assertNotSame( '', $section['title'] );
			$this->assertIsCallable( $section['render'] );
		}
	}

	/**
	 * Every section must have an icon and every icon a section: the union of section
	 * ids across all modules has to equal the TabIcons map keys exactly. This keeps the
	 * tab / metabox presentations and the icon set from drifting apart.
	 */
	public function test_section_ids_match_icon_map_exactly(): void {
		$ids = array();
		foreach ( self::moduleProvider() as $entry ) {
			foreach ( $entry[0]->sections() as $section ) {
				$ids[] = $section['id'];
			}
		}
		sort( $ids );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ), 'section ids must be unique' );

		$icon_keys = array_keys( Icons::map() );
		sort( $icon_keys );
		$this->assertSame( $icon_keys, $ids );
	}

	public function test_bogo_section_keeps_its_js_hook_class(): void {
		$sections = ( new BogoFields() )->sections();
		$this->assertArrayHasKey( 'class', $sections[0] );
		$this->assertContains( 'moksafocou_bogo_tab', (array) $sections[0]['class'] );
	}
}
