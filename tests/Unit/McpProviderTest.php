<?php
/**
 * Coupon's registration with the shared Moksa MCP endpoint.
 *
 * @package Moksafocou\Tests
 */

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Mcp\Provider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/McpStubs.php';

if ( ! defined( 'MOKSAFOCOU_VERSION' ) ) {
	define( 'MOKSAFOCOU_VERSION', '0.0.0-test' );
}

final class McpProviderTest extends TestCase {

	protected function setUp(): void {
		mcp_stub_reset();
	}

	public function test_off_and_nothing_ticked_by_default(): void {
		$p = Provider::register( array() )['moksafocou'];
		$this->assertFalse( $p['enabled'] );
		$this->assertSame( array(), $p['destructive_allow'] );
		$this->assertSame( 'moksafocou/', $p['ability_prefix'] );
		$this->assertSame( array( 'moksafocou/v1', '/mcp' ), $p['legacy_route'], 'old endpoint kept as an alias' );
		$this->assertTrue( is_callable( $p['destructive_handlers'] ) );
	}

	public function test_follows_the_options(): void {
		update_option( 'moksafocou_mcp_server_enabled', 'yes' );
		update_option( Provider::TOOLS_OPTION, array( 'moksafocou/delete-coupon' ) );
		$p = Provider::register( array( 'other' => array( 'x' => 1 ) ) );
		$this->assertArrayHasKey( 'other', $p, 'keeps other providers' );
		$this->assertTrue( $p['moksafocou']['enabled'] );
		$this->assertSame( array( 'moksafocou/delete-coupon' ), $p['moksafocou']['destructive_allow'] );
	}

	public function test_garbage_option_means_nothing_ticked(): void {
		update_option( Provider::TOOLS_OPTION, 'moksafocou/delete-coupon' );
		$this->assertSame( array(), Provider::allowed() );
	}
}
