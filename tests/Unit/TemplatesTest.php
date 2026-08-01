<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\Templates\Catalog;
use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\Rules;
use Moksafocou\Support\Tiers;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the coupon-template catalog: structural integrity + meta-key safety.
 * These guard against a typo'd meta key silently producing an inert coupon.
 */
final class TemplatesTest extends TestCase {

	private const NATIVE_TYPES = array( 'percent', 'fixed_cart', 'fixed_product', 'moksafocou_bogo', 'moksafocou_nth_item', 'moksafocou_mixmatch', 'moksafocou_cashback' );

	public function test_every_template_has_required_shape(): void {
		$ids  = array();
		$cats = array_keys( Catalog::categories() );
		foreach ( Catalog::all() as $tpl ) {
			foreach ( array( 'id', 'category', 'label', 'desc', 'type_key', 'prefix', 'native', 'meta' ) as $key ) {
				$this->assertArrayHasKey( $key, $tpl, "template missing key {$key}" );
			}
			$this->assertNotSame( '', (string) $tpl['id'] );
			$ids[] = $tpl['id'];

			$this->assertContains(
				(string) $tpl['category'],
				$cats,
				"template {$tpl['id']} has unknown category {$tpl['category']}"
			);

			$type = (string) ( $tpl['native']['discount_type'] ?? '' );
			$this->assertContains( $type, self::NATIVE_TYPES, "template {$tpl['id']} has invalid discount_type {$type}" );
		}
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'template ids must be unique' );
	}

	public function test_required_modules_normalizes_string_and_array(): void {
		// Absent / empty string → no requirement.
		$this->assertSame( array(), Catalog::required_modules( array() ) );
		$this->assertSame( array(), Catalog::required_modules( array( 'requires' => '' ) ) );
		// Single string → one-element list.
		$this->assertSame( array( 'conditions' ), Catalog::required_modules( array( 'requires' => 'conditions' ) ) );
		// Array → list, de-duplicated, empties dropped.
		$this->assertSame(
			array( 'shipping', 'conditions' ),
			Catalog::required_modules( array( 'requires' => array( 'shipping', 'conditions', '', 'shipping' ) ) )
		);
	}

	public function test_every_required_module_has_a_label(): void {
		foreach ( Catalog::all() as $tpl ) {
			foreach ( Catalog::required_modules( $tpl ) as $slug ) {
				// A labelled module never falls through to the raw-slug fallback.
				$this->assertNotSame(
					$slug,
					Catalog::module_label( $slug ),
					"required module {$slug} (template {$tpl['id']}) has no human label"
				);
			}
		}
	}

	public function test_every_category_has_at_least_one_template(): void {
		$used = array();
		foreach ( Catalog::all() as $tpl ) {
			$used[ (string) $tpl['category'] ] = true;
		}
		foreach ( array_keys( Catalog::categories() ) as $cat ) {
			$this->assertArrayHasKey( $cat, $used, "category {$cat} has no templates" );
		}
	}

	public function test_template_meta_keys_are_all_real_plugin_keys(): void {
		$allowed = Keys::all();
		foreach ( Catalog::all() as $tpl ) {
			foreach ( array_keys( $tpl['meta'] ) as $meta_key ) {
				$this->assertContains( $meta_key, $allowed, "template {$tpl['id']} meta key {$meta_key} is not a known plugin key" );
			}
		}
	}

	public function test_get_returns_template_or_null(): void {
		$this->assertNotNull( Catalog::get( 'new_customer' ) );
		$this->assertNull( Catalog::get( 'does_not_exist' ) );
	}

	public function test_set_price_mechanics_have_starter_templates(): void {
		// BOGO + Nth-item shipped quantity mechanics; pin that each still ships a ready-made
		// template so a fresh install can use them without hand-building one. (Mix & Match ships
		// the mechanic but no starter template yet.)
		$type_keys = array();
		foreach ( Catalog::all() as $tpl ) {
			$type_keys[ (string) $tpl['type_key'] ] = true;
		}
		foreach ( array( 'moksafocou_bogo', 'moksafocou_nth_item' ) as $type ) {
			$this->assertArrayHasKey( $type, $type_keys, "no template offers discount type {$type}" );
		}
	}

	public function test_nth_item_templates_present(): void {
		foreach ( array( 'nth_third_free', 'nth_third_30off' ) as $id ) {
			$this->assertNotNull( Catalog::get( $id ), "expected template {$id}" );
		}
	}

	public function test_new_feature_templates_present(): void {
		foreach (
			array(
				'tiered_aov',
				'advanced_combo',
				'winback',
				'tw_mainland_freeship',
				'heavy_freeship',
				'percent_capped',
				'auto_sitewide',
				'vip_exclusive',
				'payment_specific',
				'category_required',
				'bogo_category_once',
			) as $id
		) {
			$this->assertNotNull( Catalog::get( $id ), "expected feature template {$id}" );
		}
	}

	public function test_template_tiers_and_rules_json_parse_through_engines(): void {
		foreach ( Catalog::all() as $tpl ) {
			$meta = $tpl['meta'];
			if ( isset( $meta[ Keys::TIERS ] ) ) {
				$rows = Tiers::parse( $meta[ Keys::TIERS ] );
				$this->assertNotEmpty( $rows, "template {$tpl['id']} TIERS json yielded no rows" );
			}
			if ( isset( $meta[ Keys::RULES ] ) ) {
				$set = Rules::parse( $meta[ Keys::RULES ] );
				$this->assertNotEmpty( $set['groups'], "template {$tpl['id']} RULES json yielded no groups" );
			}
		}
	}

	public function test_sanitize_meta_drops_unknown_keys(): void {
		$clean = Catalog::sanitize_meta(
			array(
				Keys::MIN_SUBTOTAL    => '1000',
				'_evil_injected_key'  => 'x',
				Keys::CUST_FIRST_ONLY => 'yes',
			)
		);
		$this->assertSame(
			array(
				Keys::MIN_SUBTOTAL    => '1000',
				Keys::CUST_FIRST_ONLY => 'yes',
			),
			$clean
		);
	}
}
