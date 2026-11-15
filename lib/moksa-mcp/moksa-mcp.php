<?php
/**
 * Moksa MCP — bundled loader (single-instance version election).
 *
 * One MCP endpoint for the whole site (/wp-json/moksa/v1/mcp) that lists the tools of every
 * Moksa plugin that is installed. Every Moksa plugin bundles a copy of this library and requires
 * THIS file early. Each copy registers its (version, dir) into a shared global pool; the
 * highest-version copy wins and boots the one server — the same pattern as moksa-ai.
 *
 * IMPORTANT: this file defines NO class and NO constant (those would collide across the
 * bundled copies). It only appends to a global array and defines one guarded boot function.
 * The pool entry shape, moksa_mcp_boot behaviour and Server::init() signature are FROZEN —
 * only add, never break — or a newer copy electing against an older one will mismatch.
 *
 * @package Moksa\MCP
 */

defined( 'ABSPATH' ) || exit;

// Bump this string when shipping a newer bundled copy. Highest version wins the election.
$GLOBALS['moksa_mcp_pool'][] = array(
	// 1.0.0:全站單一 MCP 入口 moksa/v1/mcp、破壞性工具兩段式、呼叫守門與稽核紀錄.
	'version' => '1.0.0',
	'dir'     => __DIR__,
);

if ( ! function_exists( 'moksa_mcp_boot' ) ) {
	/**
	 * Elect the highest-version bundled copy and boot its single server.
	 * Defined once (first-loaded copy wins the definition); every copy still registered above.
	 */
	function moksa_mcp_boot(): void {
		$pool = isset( $GLOBALS['moksa_mcp_pool'] ) && is_array( $GLOBALS['moksa_mcp_pool'] ) ? $GLOBALS['moksa_mcp_pool'] : array();
		if ( empty( $pool ) ) {
			return;
		}
		usort(
			$pool,
			static function ( array $a, array $b ): int {
				return version_compare( (string) ( $a['version'] ?? '0' ), (string) ( $b['version'] ?? '0' ) );
			}
		);
		$latest = end( $pool );
		$dir    = (string) ( $latest['dir'] ?? '' );
		if ( '' === $dir || ! is_readable( $dir . '/src/Server.php' ) ) {
			return;
		}
		foreach ( array( 'Strings', 'Audit', 'CallGuard', 'Catalog', 'Confirm', 'Server' ) as $moksa_mcp_class ) {
			require_once $dir . '/src/' . $moksa_mcp_class . '.php';
		}
		if ( class_exists( '\Moksa\MCP\Server' ) ) {
			\Moksa\MCP\Server::init( $dir, (string) ( $latest['version'] ?? '' ) );
		}
	}
	// Late on plugins_loaded so every bundled copy has registered first.
	add_action( 'plugins_loaded', 'moksa_mcp_boot', 50 );
}
