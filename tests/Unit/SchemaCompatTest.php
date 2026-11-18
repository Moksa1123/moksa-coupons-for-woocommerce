<?php
/**
 * Shared moksa-ai SchemaCompat (1.3.10): list-valued "type" is collapsed only in the input_schema of
 * Moksa's own abilities. Other plugins' abilities and every output_schema are left exactly as
 * declared (a multi-type output collapsed to "string" broke Novamira's execute-ability).
 *
 * @package Moksafocou\Tests
 */

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksa\AI\SchemaCompat;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/McpStubs.php';
require_once dirname( __DIR__, 2 ) . '/lib/moksa-ai/src/Registry.php';
require_once dirname( __DIR__, 2 ) . '/lib/moksa-ai/src/SchemaCompat.php';

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

final class SchemaCompatTest extends TestCase {

	private const ANY = array( 'object', 'array', 'string', 'number', 'integer', 'boolean', 'null' );

	protected function setUp(): void {
		mcp_stub_reset();
		foreach ( array( 'pending', 'reported' ) as $prop ) {
			( new \ReflectionProperty( SchemaCompat::class, $prop ) )->setValue( null, array() );
		}
		$GLOBALS['__schema_notices'] = array();
		$GLOBALS['__mcp']['filters']['moksa_ai_abilities'] = array( fn( $a ) => array( 'moksafocou/ours' ) );
	}

	private static function args(): array {
		return array(
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(
					'a' => array( 'type' => array( 'number', 'null' ) ),
					'b' => array( 'type' => array( 'string', 'array', 'object' ) ),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'data' => array( 'type' => self::ANY ),
				),
			),
		);
	}

	public function test_third_party_ability_is_left_untouched(): void {
		foreach ( array( 'novamira-mcp-adapter/execute-ability', 'woocommerce/theirs', 'fluentcrm/x' ) as $name ) {
			$this->assertSame( self::args(), SchemaCompat::compat_args( self::args(), $name ), $name );
		}
	}

	public function test_own_input_is_collapsed_but_output_is_not(): void {
		foreach ( array( 'moksafocou/ours', 'moksa-for-woocommerce/x', 'moksafoboo/x', 'points/x', 'member/x', 'affiliate/x', 'groupbuy/x' ) as $name ) {
			$out = SchemaCompat::compat_args( self::args(), $name );
			$this->assertSame( 'number', $out['input_schema']['properties']['a']['type'], $name );
			$this->assertSame( 'string', $out['input_schema']['properties']['b']['type'], $name );
			$this->assertSame( self::args()['output_schema'], $out['output_schema'], $name );
		}
	}

	public function test_multi_type_output_like_novamira_keeps_its_types(): void {
		$out = SchemaCompat::compat_args( self::args(), 'moksafocou/ours' );
		$this->assertSame( self::ANY, $out['output_schema']['properties']['data']['type'] );
	}

	public function test_filters_can_add_a_namespace_or_exclude_an_ability(): void {
		$GLOBALS['__mcp']['filters']['moksa_ai_schema_compat_namespaces'] = array( fn( $l ) => array_merge( $l, array( 'thirdparty' ) ) );
		$this->assertSame( 'number', SchemaCompat::compat_args( self::args(), 'thirdparty/x' )['input_schema']['properties']['a']['type'] );

		$GLOBALS['__mcp']['filters']['moksa_ai_schema_compat_applies'] = array( fn( $on, $name ) => 'moksafocou/skip' === $name ? false : $on );
		$this->assertSame( self::args(), SchemaCompat::compat_args( self::args(), 'moksafocou/skip' ) );
	}

	public function test_names_without_a_matching_namespace_are_untouched(): void {
		foreach ( array( 'points', 'pointsx/a', 'moksafocou-extra/a', '/a', '' ) as $name ) {
			$this->assertSame( self::args(), SchemaCompat::compat_args( self::args(), $name ), $name );
		}
	}

	public function test_namespace_filter_accepts_a_trailing_slash(): void {
		$GLOBALS['__mcp']['filters']['moksa_ai_schema_compat_namespaces'] = array( fn( $l ) => array_merge( $l, array( 'thirdparty/' ) ) );
		$this->assertSame( 'number', SchemaCompat::compat_args( self::args(), 'thirdparty/x' )['input_schema']['properties']['a']['type'] );
	}

	public function test_a_property_named_type_is_not_mistaken_for_a_type_list(): void {
		$args = array(
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'type' => array(
						'type' => 'string',
						'enum' => array( 'a', 'b' ),
					),
				),
			),
		);
		$this->assertSame( $args, SchemaCompat::compat_args( $args, 'moksafoboo/x' ) );
	}

	public function test_notice_only_for_abilities_handed_to_moksa_ai(): void {
		SchemaCompat::compat_args( self::args(), 'woocommerce/theirs' );
		SchemaCompat::compat_args( self::args(), 'moksafocou/ours' );
		SchemaCompat::compat_args( self::args(), 'moksafocou/not-a-tool' );
		SchemaCompat::flush_reports();
		$this->assertCount( 1, $GLOBALS['__schema_notices'] );
		$this->assertStringContainsString( 'moksafocou/ours', $GLOBALS['__schema_notices'][0] );
	}
}
