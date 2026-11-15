<?php

declare( strict_types=1 );

namespace Moksafocou\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * The coupon MCP endpoint, now served by the shared Moksa MCP layer (lib/moksa-mcp).
 *
 * Until 1.2.x this class was a full MCP server of its own. The transport, permission checks,
 * propose / apply and audit now live in the shared layer, which serves one endpoint for every Moksa
 * plugin at /moksa/v1/mcp. This plugin joins it through Provider; the old route below stays as a
 * legacy alias (registered by the shared layer, scoped to coupon tools only) so clients that were
 * set up against it keep working.
 */
final class Server {

	/** Legacy REST namespace and route (kept as an alias). */
	const NS    = 'moksafocou/v1';
	const ROUTE = '/mcp';

	public static function enabled(): bool {
		return 'yes' === get_option( 'moksafocou_mcp_server_enabled', 'no' );
	}

	/** The legacy endpoint URL. */
	public static function endpoint_url(): string {
		return rest_url( self::NS . self::ROUTE );
	}
}
