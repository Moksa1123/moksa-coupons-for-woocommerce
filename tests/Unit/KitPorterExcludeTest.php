<?php
/**
 * moksa-kit SettingsPorter (1.0.3): excluded keys (secrets) never leave in an export and are never
 * written by an import.
 *
 * @package Moksafocou\Tests
 */

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksa\Kit\SettingsPorter;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/McpStubs.php';
require_once dirname( __DIR__, 2 ) . '/lib/moksa-kit/src/SettingsPorter.php';

final class KitPorterExcludeTest extends TestCase {

	protected function setUp(): void {
		mcp_stub_reset();
		$GLOBALS['__mcp']['options'] = array(
			'demo_mode'    => 'yes',
			'demo_api_key' => 'sk-live-plaintext',
		);
	}

	private function ctx( array $extra = array() ): array {
		return array_merge(
			array(
				'format'   => 'demo-settings',
				'plugin'   => 'demo',
				'version'  => '1.0',
				'keys'     => array( 'demo_mode', 'demo_api_key' ),
				'sanitize' => static fn( $key, $raw ) => array(
					'ok'    => true,
					'value' => $raw,
				),
			),
			$extra
		);
	}

	public function test_without_a_list_everything_is_exported_as_before(): void {
		$out = SettingsPorter::export_payload( $this->ctx() );
		$this->assertSame( 'sk-live-plaintext', $out['settings']['demo_api_key'] );
	}

	public function test_excluded_key_is_not_exported(): void {
		$out = SettingsPorter::export_payload( $this->ctx( array( 'exclude' => array( 'demo_api_key' ) ) ) );
		$this->assertArrayNotHasKey( 'demo_api_key', $out['settings'] );
		$this->assertSame( 'yes', $out['settings']['demo_mode'] );
	}

	public function test_filter_excludes_too_and_import_never_writes_it(): void {
		add_filter( 'moksa_kit_porter_excluded_keys', static fn( $list, $format ) => 'demo-settings' === $format ? array_merge( $list, array( 'demo_api_key' ) ) : $list );

		$this->assertArrayNotHasKey( 'demo_api_key', SettingsPorter::export_payload( $this->ctx() )['settings'] );

		$res = SettingsPorter::import_payload(
			array(
				'format'   => 'demo-settings',
				'settings' => array(
					'demo_mode'    => 'no',
					'demo_api_key' => 'attacker-key',
				),
			),
			$this->ctx()
		);
		$this->assertSame( 1, $res['applied'] );
		$this->assertSame( 1, $res['skipped'] );
		$this->assertSame( 'sk-live-plaintext', $GLOBALS['__mcp']['options']['demo_api_key'] );
		$this->assertSame( 'no', $GLOBALS['__mcp']['options']['demo_mode'] );
	}
}
