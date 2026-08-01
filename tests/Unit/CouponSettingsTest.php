<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Coupon\Meta\CouponSettings;
use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Coupon\Meta\RestMeta;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the grouped coupon-settings map + JSON schema. The read/write
 * round-trip touches get/update_post_meta and is verified live, not here.
 */
final class CouponSettingsTest extends TestCase {

	/** @return array<int,string> Every meta key referenced by the groups. */
	private static function group_keys(): array {
		$keys = array();
		foreach ( CouponSettings::groups() as $fields ) {
			foreach ( $fields as $key ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	public function test_groups_cover_every_meta_key_exactly_once(): void {
		$keys = self::group_keys();
		$this->assertSame( $keys, array_values( array_unique( $keys ) ), 'a meta key is grouped twice' );
		sort( $keys );
		$all = Keys::all();
		sort( $all );
		$this->assertSame( $all, $keys, 'grouped keys must equal Keys::all()' );
	}

	public function test_every_grouped_key_has_a_known_kind(): void {
		$kinds = RestMeta::definitions();
		foreach ( self::group_keys() as $key ) {
			$this->assertArrayHasKey( $key, $kinds, "no RestMeta kind for {$key}" );
		}
	}

	public function test_schema_describes_every_group_and_field(): void {
		$schema = CouponSettings::schema();
		$this->assertSame( 'object', $schema['type'] );
		foreach ( CouponSettings::groups() as $group => $fields ) {
			$this->assertArrayHasKey( $group, $schema['properties'], "schema missing group {$group}" );
			$this->assertSame( 'object', $schema['properties'][ $group ]['type'] );
			foreach ( $fields as $name => $key ) {
				$prop = $schema['properties'][ $group ]['properties'][ $name ] ?? null;
				$this->assertIsArray( $prop, "schema missing {$group}.{$name}" );
				$this->assertArrayHasKey( 'type', $prop );
			}
		}
	}

	public function test_field_name_for_a_zero_valid_weekday_is_an_integer_array(): void {
		// daytime.days carries weekday 0 (Sunday); its schema must be an integer array.
		$schema = CouponSettings::schema();
		$days   = $schema['properties']['daytime']['properties']['days'];
		$this->assertSame( 'array', $days['type'] );
		$this->assertSame( 'integer', $days['items']['type'] );
	}
}
