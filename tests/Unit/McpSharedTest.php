<?php
/**
 * Shared MCP layer (lib/moksa-mcp): catalog gating, permission re-checks, propose / apply,
 * call guard and audit.
 *
 * @package Moksafocou\Tests
 */

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksa\MCP\Audit;
use Moksa\MCP\CallGuard;
use Moksa\MCP\Catalog;
use Moksa\MCP\Server;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/McpStubs.php';
foreach ( array( 'Strings', 'Audit', 'CallGuard', 'Catalog', 'Confirm', 'Server' ) as $moksafocou_mcp_class ) {
	require_once dirname( __DIR__, 2 ) . '/lib/moksa-mcp/src/' . $moksafocou_mcp_class . '.php';
}

final class McpSharedTest extends TestCase {

	/** @var array<string,int> How many times each apply handler ran. */
	private array $applied = array();

	protected function setUp(): void {
		mcp_stub_reset();
		$this->applied = array();
		$GLOBALS['__mcp']['user'] = 1;
		mcp_stub_caps( array( 'manage_woocommerce', 'delete_shop_coupons' ) );
		$this->provider();
		$this->abilities();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['__mfc_test_can'] );
	}

	/** One provider ('demo') plus an optional second ('other'). */
	private function provider( array $over = array(), bool $with_other = false ): void {
		$GLOBALS['__mcp']['filters']['moksa_mcp_providers'] = array(
			function ( array $providers ) use ( $over, $with_other ): array {
				$providers['demo'] = array_merge(
					array(
						'label'                => 'Demo',
						'version'              => '9.9.9',
						'ability_prefix'       => 'demo/',
						'enabled'              => true,
						'destructive_allow'    => array( 'demo/delete-thing', 'demo/no-cap' ),
						'legacy_route'         => array( 'demo/v1', '/mcp' ),
						'destructive_handlers' => fn() => array(
							'demo/delete-thing' => array(
								'prepare' => fn( array $a ) => array(
									'summary' => 'Delete ' . ( $a['id'] ?? '?' ),
									'id'      => $a['id'] ?? 0,
								),
								'apply'   => function ( array $p ) {
									$this->applied[ (string) $p['id'] ] = ( $this->applied[ (string) $p['id'] ] ?? 0 ) + 1;
									return 'deleted ' . $p['id'];
								},
							),
							'demo/no-cap'       => array(
								'prepare' => fn( array $a ) => array( 'summary' => 'x' ),
								'apply'   => fn( array $p ) => 'x',
							),
						),
					),
					$over
				);
				if ( $with_other ) {
					$providers['other'] = array(
						'ability_prefix' => 'other/',
						'enabled'        => true,
					);
				}
				return $providers;
			},
		);
	}

	private function abilities( int $rows = 3 ): void {
		$read  = array(
			'annotations' => array( 'readonly' => true ),
			'mcp'         => array( 'public' => true ),
		);
		$write = array(
			'annotations' => array( 'destructive' => true ),
			'mcp'         => array(
				'public'     => false,
				'capability' => 'delete_shop_coupons',
			),
		);
		$GLOBALS['__mcp']['abilities'] = array(
			new \McpFakeAbility( 'demo/list-things', $read, fn( $a ) => range( 1, $rows ), null, array( 'type' => 'array' ) ),
			new \McpFakeAbility( 'demo/hidden', array( 'mcp' => array( 'public' => false ) ), fn( $a ) => 'x' ),
			new \McpFakeAbility( 'demo/picky', $read, fn( $a ) => 'ok', fn( $a ) => ( $a['id'] ?? 0 ) === 1 ),
			new \McpFakeAbility( 'demo/delete-thing', $write, fn( $a ) => 'should not run' ),
			new \McpFakeAbility( 'demo/not-ticked', $write, fn( $a ) => 'x' ),
			new \McpFakeAbility(
				'demo/no-cap',
				array(
					'annotations' => array( 'destructive' => true ),
					'mcp'         => array( 'public' => false ),
				),
				fn( $a ) => 'x'
			),
			new \McpFakeAbility( 'other/read', $read, fn( $a ) => 'other' ),
			new \McpFakeAbility( 'stranger/read', $read, fn( $a ) => 'not ours' ),
		);
	}

	/** Call the endpoint like a client. */
	private function rpc( string $method, array $params = array(), string $scope = '' ): array {
		$body = (string) json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => $method,
				'params'  => $params,
			)
		);
		return (array) Server::handle( new \WP_REST_Request( $body ), $scope )->get_data();
	}

	private function call( string $tool, array $args = array(), string $scope = '' ): array {
		return $this->rpc(
			'tools/call',
			array(
				'name'      => $tool,
				'arguments' => $args,
			),
			$scope
		)['result'];
	}

	private function tool_names( string $scope = '' ): array {
		return array_column( $this->rpc( 'tools/list', array(), $scope )['result']['tools'], 'name' );
	}

	public function test_provider_defaults_are_safe(): void {
		$GLOBALS['__mcp']['filters']['moksa_mcp_providers'] = array(
			fn( array $p ) => array( 'x' => array( 'ability_prefix' => 'x' ) ),
		);
		$p = Catalog::providers()['x'];
		$this->assertFalse( $p['enabled'] );
		$this->assertSame( array(), $p['destructive_allow'] );
		$this->assertSame( 'manage_woocommerce', $p['capability'] );
		$this->assertSame( 'x/', $p['ability_prefix'] );
	}

	public function test_tools_list_applies_every_gate(): void {
		$names = $this->tool_names();
		$this->assertContains( 'demo-list-things', $names );
		$this->assertContains( 'demo-delete-thing', $names );
		$this->assertContains( 'moksa-apply', $names );
		$this->assertNotContains( 'demo-hidden', $names, 'mcp.public = false read stays hidden' );
		$this->assertNotContains( 'demo-not-ticked', $names, 'destructive tool the owner did not tick' );
		$this->assertNotContains( 'demo-no-cap', $names, 'destructive tool without its own capability' );
		$this->assertNotContains( 'stranger-read', $names, 'another plugin prefix' );
	}

	public function test_destructive_tool_needs_its_own_capability(): void {
		mcp_stub_caps( array( 'manage_woocommerce' ) );
		$names = $this->tool_names();
		$this->assertContains( 'demo-list-things', $names );
		$this->assertNotContains( 'demo-delete-thing', $names );
		$this->assertNotContains( 'moksa-apply', $names, 'no apply tool when nothing destructive is reachable' );
	}

	public function test_nothing_ticked_means_no_destructive_tools(): void {
		$this->provider( array( 'destructive_allow' => array() ) );
		$names = $this->tool_names();
		$this->assertNotContains( 'demo-delete-thing', $names );
		$this->assertNotContains( 'moksa-apply', $names );
	}

	public function test_permission_callback_is_checked_on_every_call(): void {
		$this->assertTrue( $this->call( 'demo-picky', array( 'id' => 2 ) )['isError'] );
		$this->assertFalse( $this->call( 'demo-picky', array( 'id' => 1 ) )['isError'] );
	}

	public function test_read_results_are_capped(): void {
		$this->abilities( 150 );
		$result = $this->call( 'demo-list-things' );
		$this->assertCount( CallGuard::MAX_ROWS, $result['structuredContent']['results'] );
		$this->assertStringContainsString( '100', $result['content'][0]['text'] );
	}

	public function test_propose_changes_nothing_and_apply_runs_once(): void {
		$proposed = $this->call( 'demo-delete-thing', array( 'id' => 5 ) );
		$this->assertFalse( $proposed['isError'] );
		$data = $proposed['structuredContent'];
		$this->assertSame( 'proposed', $data['status'] );
		$this->assertFalse( $data['changed'] );
		$this->assertSame( 'Delete 5', $data['summary'] );
		$this->assertSame( array(), $this->applied, 'propose must not apply' );
		$this->assertArrayNotHasKey( 'moksa_mcp_tok_' . $data['token'], $GLOBALS['__mcp']['transients'], 'token is hashed in the key' );

		$first = $this->call( 'moksa-apply', array( 'token' => $data['token'] ) );
		$this->assertFalse( $first['isError'] );
		$this->assertSame( 'applied', $first['structuredContent']['status'] );
		$this->assertSame( array( '5' => 1 ), $this->applied );

		$second = $this->call( 'moksa-apply', array( 'token' => $data['token'] ) );
		$this->assertTrue( $second['isError'], 'a token works once' );
		$this->assertSame( array( '5' => 1 ), $this->applied );
	}

	public function test_token_belongs_to_the_user_who_proposed(): void {
		$token = $this->call( 'demo-delete-thing', array( 'id' => 5 ) )['structuredContent']['token'];
		$GLOBALS['__mcp']['user'] = 2;
		$this->assertTrue( $this->call( 'moksa-apply', array( 'token' => $token ) )['isError'] );
		$this->assertSame( array(), $this->applied );
	}

	public function test_untick_or_lost_capability_voids_a_pending_token(): void {
		$token = $this->call( 'demo-delete-thing', array( 'id' => 5 ) )['structuredContent']['token'];
		$this->provider( array( 'destructive_allow' => array() ) );
		$this->assertTrue( $this->call( 'moksa-apply', array( 'token' => $token ) )['isError'] );

		$this->provider();
		$token = $this->call( 'demo-delete-thing', array( 'id' => 6 ) )['structuredContent']['token'];
		mcp_stub_caps( array( 'manage_woocommerce' ) );
		$this->assertTrue( $this->call( 'moksa-apply', array( 'token' => $token ) )['isError'] );
		$this->assertSame( array(), $this->applied );
	}

	public function test_apply_rate_limit(): void {
		$this->provider( array( 'destructive_limit' => 2 ) );
		for ( $i = 1; $i <= 3; $i++ ) {
			$token  = $this->call( 'demo-delete-thing', array( 'id' => $i ) )['structuredContent']['token'];
			$result = $this->call( 'moksa-apply', array( 'token' => $token ) );
			$this->assertSame( $i > 2, $result['isError'], "apply #$i" );
		}
		$this->assertSame( array( '1' => 1, '2' => 1 ), $this->applied );
	}

	public function test_size_caps(): void {
		$deep = array();
		$ref  = &$deep;
		for ( $i = 0; $i < 15; $i++ ) {
			$ref['a'] = array();
			$ref      = &$ref['a'];
		}
		unset( $ref );
		$this->assertFalse( CallGuard::args_ok( $deep ) );
		$this->assertTrue( CallGuard::args_ok( array( 'id' => 1 ) ) );
		$this->assertFalse( CallGuard::args_ok( array( 'x' => str_repeat( 'a', CallGuard::MAX_ARGS_BYTES + 1 ) ) ) );
		$this->assertFalse( CallGuard::body_ok( str_repeat( 'a', CallGuard::MAX_BODY_BYTES + 1 ) ) );
	}

	public function test_audit_keeps_only_a_hash_of_arguments(): void {
		$this->call( 'demo-picky', array( 'id' => 1, 'email' => 'secret@example.test' ) );
		$entry = Audit::recent()[0];
		$this->assertSame( 'demo-picky', $entry['tool'] );
		$this->assertSame( 'ok', $entry['outcome'] );
		$this->assertSame( 64, strlen( $entry['args_hash'] ) );
		$this->assertStringNotContainsString( 'secret', (string) json_encode( Audit::recent() ) );
		$this->assertSame( Audit::args_hash( array( 'b' => 1, 'a' => 2 ) ), Audit::args_hash( array( 'a' => 2, 'b' => 1 ) ) );
	}

	public function test_apply_is_audited_with_the_proposed_hash(): void {
		$token = $this->call( 'demo-delete-thing', array( 'id' => 5 ) )['structuredContent']['token'];
		$this->call( 'moksa-apply', array( 'token' => $token ) );
		$applied = Audit::recent( 'demo-', array( 'applied' ) );
		$this->assertCount( 1, $applied );
		$this->assertSame( 'demo-delete-thing', $applied[0]['tool'] );
		$this->assertSame( Audit::args_hash( array( 'id' => 5 ) ), $applied[0]['args_hash'] );
	}

	public function test_legacy_route_only_sees_its_own_provider(): void {
		$this->provider( array(), true );
		$this->assertContains( 'other-read', $this->tool_names() );
		$scoped = $this->tool_names( 'demo' );
		$this->assertContains( 'demo-list-things', $scoped );
		$this->assertNotContains( 'other-read', $scoped );
		$this->assertSame( 'demo', $this->rpc( 'initialize', array(), 'demo' )['result']['serverInfo']['name'] );
	}

	public function test_disabled_provider_is_invisible(): void {
		$this->provider( array( 'enabled' => false ) );
		$this->assertSame( array(), $this->tool_names() );
		$this->assertFalse( Catalog::user_can_any( '' ) );
	}

	public function test_a_batch_counts_every_message_against_the_rate_limit(): void {
		$GLOBALS['__mcp']['filters']['moksa_mcp_rate_limit'] = array( fn() => 5 );
		$batch = static function ( int $n ): array {
			$msgs = array();
			for ( $i = 0; $i < $n; $i++ ) {
				$msgs[] = array( 'jsonrpc' => '2.0', 'id' => $i, 'method' => 'ping' );
			}
			return $msgs;
		};
		$this->assertSame( 429, Server::handle( new \WP_REST_Request( (string) json_encode( $batch( 6 ) ) ) )->get_status(), '6 messages > cap 5' );
		$this->assertSame( 200, Server::handle( new \WP_REST_Request( (string) json_encode( $batch( 5 ) ) ) )->get_status() );
		$this->assertSame( 429, $this->rpc_status( 'ping' ), 'the 5 batched pings used up the minute' );
	}

	private function rpc_status( string $method ): int {
		$body = (string) json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method ) );
		return Server::handle( new \WP_REST_Request( $body ) )->get_status();
	}

	public function test_read_calls_cannot_push_changes_out_of_the_log(): void {
		$token = $this->call( 'demo-delete-thing', array( 'id' => 5 ) )['structuredContent']['token'];
		$this->call( 'moksa-apply', array( 'token' => $token ) );
		for ( $i = 0; $i < 250; $i++ ) {
			Audit::log( 'demo-list-things', false, 'ok', array( 'n' => $i ) );
		}
		$this->assertCount( 1, Audit::recent( 'demo-', array( 'applied' ) ) );
	}

	public function test_proposals_and_refusals_cannot_push_changes_out_of_the_log(): void {
		$GLOBALS['__mcp']['filters']['moksa_mcp_propose_limit'] = array( fn() => 0 );
		$GLOBALS['__mcp']['filters']['moksa_mcp_rate_limit']    = array( fn() => 0 );
		$token = $this->call( 'demo-delete-thing', array( 'id' => 5 ) )['structuredContent']['token'];
		$this->call( 'moksa-apply', array( 'token' => $token ) );
		for ( $i = 0; $i < 210; $i++ ) {
			$this->call( 'demo-delete-thing', array( 'id' => 100 + $i ) ); // Real proposals, never applied.
			$this->call( 'moksa-apply', array( 'token' => 'fake-token-' . $i . '-xxxxxxxxxx' ) ); // Refusals.
		}
		$this->assertCount( 1, Audit::recent( 'demo-', array( 'applied' ) ) );
	}

	public function test_proposals_are_capped_per_hour(): void {
		$GLOBALS['__mcp']['filters']['moksa_mcp_propose_limit'] = array( fn() => 2 );
		$this->assertFalse( $this->call( 'demo-delete-thing', array( 'id' => 1 ) )['isError'] );
		$this->assertFalse( $this->call( 'demo-delete-thing', array( 'id' => 2 ) )['isError'] );
		$this->assertTrue( $this->call( 'demo-delete-thing', array( 'id' => 3 ) )['isError'] );
	}

	public function test_privacy_export_and_erase(): void {
		$GLOBALS['__mcp']['user'] = 7;
		$this->call( 'demo-list-things' );
		$this->assertCount( 1, Audit::export( 'u7@example.test' )['data'] );
		$this->assertTrue( Audit::erase( 'u7@example.test' )['items_removed'] );
		$this->assertSame( array(), Audit::recent() );
	}
}
