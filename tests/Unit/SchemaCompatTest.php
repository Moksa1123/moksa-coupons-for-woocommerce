<?php
/**
 * Shared moksa-ai SchemaCompat: list-valued "type" is always collapsed, but the developer notice is
 * only raised for abilities actually handed to Moksa AI (not for other plugins' abilities).
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

	protected function setUp(): void {
		mcp_stub_reset();
		$GLOBALS['__schema_notices'] = array();
		$GLOBALS['__mcp']['filters']['moksa_ai_abilities'] = array( fn( $a ) => array( 'moksafocou/ours' ) );
	}

	private static function args(): array {
		return array(
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'a' => array( 'type' => array( 'number', 'null' ) ),
					'b' => array( 'type' => array( 'string', 'array', 'object' ) ),
				),
			),
		);
	}

	public function test_types_are_collapsed_for_everyone(): void {
		$wc = SchemaCompat::compat_args( self::args(), 'woocommerce/theirs' );
		$this->assertSame( 'number', $wc['input_schema']['properties']['a']['type'] );
		$this->assertSame( 'string', $wc['input_schema']['properties']['b']['type'] );
	}

	public function test_notice_only_for_abilities_handed_to_moksa_ai(): void {
		SchemaCompat::compat_args( self::args(), 'woocommerce/theirs' );
		SchemaCompat::compat_args( self::args(), 'moksafocou/ours' );
		SchemaCompat::flush_reports();
		$this->assertCount( 1, $GLOBALS['__schema_notices'] );
		$this->assertStringContainsString( 'moksafocou/ours', $GLOBALS['__schema_notices'][0] );
		$this->assertStringNotContainsString( 'woocommerce', implode( ' ', $GLOBALS['__schema_notices'] ) );
	}
}
