<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Coupon\Meta\RestMeta;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the coupon-meta REST registrar: every meta key must have a
 * definition, and the sanitizers must be safe AND idempotent (they run on EVERY
 * write path, including the admin form which already sanitized — re-sanitizing
 * clean data must not change it).
 */
final class RestMetaTest extends TestCase {

	public function test_every_meta_key_has_exactly_one_definition(): void {
		$def = array_keys( RestMeta::definitions() );
		$all = Keys::all();
		sort( $def );
		sort( $all );
		// No key is left unregistered and none is registered that is not a real key.
		$this->assertSame( $all, $def );
	}

	public function test_all_kinds_are_recognised(): void {
		$valid = array( 'bool', 'datetime', 'tiers', 'rules', 'text', 'key', 'textarea', 'decimal', 'int', 'slug', 'int_list', 'day_list', 'key_list', 'code_list' );
		foreach ( RestMeta::definitions() as $key => $kind ) {
			$this->assertContains( $kind, $valid, "unknown kind for {$key}" );
		}
	}

	public function test_tiers_meta_is_canonicalised_on_write(): void {
		$this->assertSame( 'tiers', RestMeta::definitions()[ Keys::TIERS ] );
		// Legacy { min_subtotal, percent } input is canonicalised into the new { threshold, kind,
		// value } shape; the invalid (percent 0) row is dropped.
		$json = RestMeta::sanitize_tiers(
			array(
				array(
					'min_subtotal' => 1000,
					'min_qty'      => 0,
					'percent'      => 20,
				),
				array( 'percent' => 0 ),
			)
		);
		$this->assertSame(
			array(
				array(
					'threshold' => 1000.0,
					'kind'      => 'percent',
					'value'     => 20.0,
				),
			),
			\Moksafocou\Support\Tiers::parse( $json )
		);
		$this->assertSame( '', RestMeta::sanitize_tiers( 'garbage' ) );
	}

	public function test_code_list_uppercases_two_letter_country_codes(): void {
		$this->assertSame( 'code_list', RestMeta::definitions()[ Keys::SHIPREGION_COUNTRIES ] );
		// lowercases → uppercased; non-2-letter / junk dropped; deduped.
		$this->assertSame( array( 'TW', 'JP', 'US' ), RestMeta::sanitize_code_list( array( 'tw', 'JP', 'us', 'TW', 'usa', '1', '' ) ) );
		$this->assertSame( array(), RestMeta::sanitize_code_list( 'nope' ) );
	}

	public function test_day_list_keeps_sunday_and_drops_out_of_range(): void {
		// 0 (Sunday) MUST survive; 7 and -1 are out of range; result deduped + sorted.
		$this->assertSame( array( 0, 3, 6 ), RestMeta::sanitize_day_list( array( 3, 0, 6, 7, -1, 3 ) ) );
		$this->assertSame( array( 0 ), RestMeta::sanitize_day_list( array( 0 ) ) );
		$this->assertSame( array(), RestMeta::sanitize_day_list( 'nope' ) );
	}

	public function test_daytime_days_uses_the_weekday_sanitizer(): void {
		// Guard against the regression where weekday 0 (Sunday) was dropped.
		$this->assertSame( 'day_list', RestMeta::definitions()[ Keys::DAYTIME_DAYS ] );
	}

	public function test_schedule_datetime_is_normalised_on_write(): void {
		// Schedule fields must canonicalise the wall-clock so the TIME is never dropped,
		// whichever shape a REST/AI caller sends.
		$this->assertSame( 'datetime', RestMeta::definitions()[ Keys::SCHEDULE_START ] );
		$this->assertSame( 'datetime', RestMeta::definitions()[ Keys::SCHEDULE_END ] );
		$this->assertSame( '2026-07-01 14:30:00', RestMeta::sanitize_datetime( '2026-07-01T14:30' ) );
		$this->assertSame( '', RestMeta::sanitize_datetime( 'garbage' ) );
	}

	public function test_bool_sanitizer_only_yields_yes_or_empty(): void {
		$this->assertSame( 'yes', RestMeta::sanitize_bool( 'yes' ) );
		$this->assertSame( '', RestMeta::sanitize_bool( '' ) );
		$this->assertSame( '', RestMeta::sanitize_bool( '1' ) );
		$this->assertSame( '', RestMeta::sanitize_bool( 'no' ) );
		$this->assertSame( '', RestMeta::sanitize_bool( true ) );
	}

	public function test_int_sanitizer_clamps_to_non_negative_int(): void {
		$this->assertSame( 5, RestMeta::sanitize_int( '5' ) );
		$this->assertSame( 0, RestMeta::sanitize_int( -3 ) );
		$this->assertSame( 0, RestMeta::sanitize_int( 'abc' ) );
		$this->assertSame( 7, RestMeta::sanitize_int( 7.9 ) );
	}

	public function test_int_list_sanitizer_keeps_positive_ints_only(): void {
		$this->assertSame( array( 1, 2 ), RestMeta::sanitize_int_list( array( 1, '2', 0, -3, 'x' ) ) );
		$this->assertSame( array(), RestMeta::sanitize_int_list( 'not-array' ) );
		$this->assertSame( array(), RestMeta::sanitize_int_list( array() ) );
	}

	public function test_key_list_sanitizer_normalises_and_drops_empties(): void {
		$this->assertSame( array( 'editor', 'admin' ), RestMeta::sanitize_key_list( array( 'Editor', 'admin!', '' ) ) );
		$this->assertSame( array(), RestMeta::sanitize_key_list( 'nope' ) );
	}

	public function test_slug_sanitizer_produces_a_slug(): void {
		$this->assertSame( 'hello-world', RestMeta::sanitize_slug( 'Hello World' ) );
	}

	/** Re-sanitizing already-clean data must be a no-op (admin already sanitized). */
	public function test_sanitizers_are_idempotent(): void {
		$cases = array(
			array( 'sanitize_bool', 'yes' ),
			array( 'sanitize_bool', '' ),
			array( 'sanitize_int', 42 ),
			array( 'sanitize_int', 0 ),
			array( 'sanitize_text', 'Spring Sale' ),
			array( 'sanitize_key_value', 'percent' ),
			array( 'sanitize_textarea', 'code1, code2' ),
			array( 'sanitize_slug', 'summer-2026' ),
			array( 'sanitize_int_list', array( 10, 20, 30 ) ),
			array( 'sanitize_day_list', array( 0, 3, 6 ) ),
			array( 'sanitize_key_list', array( 'editor', 'shop_manager' ) ),
		);
		foreach ( $cases as [ $fn, $input ] ) {
			$once  = RestMeta::$fn( $input );
			$twice = RestMeta::$fn( $once );
			$this->assertSame( $once, $twice, "{$fn} is not idempotent" );
		}
	}
}
