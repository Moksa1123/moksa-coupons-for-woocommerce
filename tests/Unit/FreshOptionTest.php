<?php
/**
 * FreshOption: a flag that a stale shared "notoptions" cache reports as missing is still read, and
 * add() does not overwrite a stored value.
 *
 * @package Moksafocou\Tests
 */

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Support\FreshOption;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/McpStubs.php';

final class FreshOptionTest extends TestCase {

	protected function setUp(): void {
		mcp_stub_reset();
		( new \ReflectionProperty( FreshOption::class, 'checked' ) )->setValue( null, array() );
	}

	public function test_stored_flag_marked_missing_is_still_read(): void {
		$GLOBALS['__mcp']['options']['moksafocou_db_version']    = '1.3.0';
		$GLOBALS['__mcp']['notoptions']['moksafocou_db_version'] = true;

		$this->assertSame( '', get_option( 'moksafocou_db_version', '' ), 'the double must reproduce the stale cache' );
		$this->assertSame( '1.3.0', FreshOption::get( 'moksafocou_db_version', '' ) );
	}

	public function test_really_missing_flag_returns_the_fallback(): void {
		$GLOBALS['__mcp']['notoptions']['moksafocou_coupon_ix_ready'] = true;

		$this->assertSame( 0, FreshOption::get( 'moksafocou_coupon_ix_ready', 0 ) );
		$this->assertNull( FreshOption::get( 'moksafocou_coupon_ix_progress', null ) );
	}

	public function test_add_does_not_overwrite_a_value_hidden_by_the_stale_cache(): void {
		$GLOBALS['__mcp']['options']['moksafocou_frontend_enabled']    = 'no';
		$GLOBALS['__mcp']['notoptions']['moksafocou_frontend_enabled'] = true;

		$this->assertFalse( FreshOption::add( 'moksafocou_frontend_enabled', 'yes', true ) );
		$this->assertSame( 'no', $GLOBALS['__mcp']['options']['moksafocou_frontend_enabled'] );
	}

	public function test_only_the_one_name_is_dropped_from_the_cache(): void {
		$GLOBALS['__mcp']['options']['moksafocou_db_version']    = '1.3.0';
		$GLOBALS['__mcp']['notoptions']['moksafocou_db_version'] = true;
		$GLOBALS['__mcp']['notoptions']['other_plugin_flag']     = true;

		FreshOption::get( 'moksafocou_db_version', '' );
		$this->assertArrayHasKey( 'other_plugin_flag', $GLOBALS['__mcp']['notoptions'] );
	}

	public function test_rechecked_once_per_request(): void {
		$GLOBALS['__mcp']['notoptions']['moksafocou_coupon_ix_ready'] = true;
		FreshOption::get( 'moksafocou_coupon_ix_ready', 0 );

		// Stale again (another request rewrote the list) while the row now exists: not re-checked.
		$GLOBALS['__mcp']['options']['moksafocou_coupon_ix_ready']    = 1;
		$GLOBALS['__mcp']['notoptions']['moksafocou_coupon_ix_ready'] = true;
		$this->assertSame( 0, FreshOption::get( 'moksafocou_coupon_ix_ready', 0 ) );
	}

	public function test_without_persistent_cache_reads_are_plain(): void {
		$GLOBALS['__mcp']['ext_cache']                           = false;
		$GLOBALS['__mcp']['options']['moksafocou_db_version']    = '1.3.0';
		$GLOBALS['__mcp']['notoptions']['moksafocou_db_version'] = true;

		$this->assertSame( '', FreshOption::get( 'moksafocou_db_version', '' ), 'no extra work when the list cannot be stale' );
		$this->assertArrayHasKey( 'moksafocou_db_version', $GLOBALS['__mcp']['notoptions'] );
	}

	public function test_add_creates_a_missing_option(): void {
		$this->assertTrue( FreshOption::add( 'moksafocou_ix_scan_lock', 123 ) );
		$this->assertSame( 123, $GLOBALS['__mcp']['options']['moksafocou_ix_scan_lock'] );
		$this->assertFalse( FreshOption::add( 'moksafocou_ix_scan_lock', 456 ), 'a held lock is not taken again' );
	}
}
