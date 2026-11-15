<?php

declare( strict_types=1 );

namespace Moksafocou\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Moksa Coupons with the shared Moksa MCP endpoint (lib/moksa-mcp).
 *
 * The shared layer owns the transport, the permission re-checks, the two-step propose / apply, the
 * call limits and the audit log; this class only says which abilities are ours, which destructive
 * ones the shop owner ticked, and where our resources and prompts come from. The old endpoint
 * /moksafocou/v1/mcp stays as a legacy route that shows only coupon tools.
 */
final class Provider {

	/** Option: destructive ability names the shop owner ticked for MCP (none by default). */
	public const TOOLS_OPTION = 'moksafocou_mcp_destructive_tools';

	/** Tool-name prefix in the shared audit log. */
	private const TOOL_PREFIX = 'moksafocou-';

	/**
	 * Filter callback for moksa_mcp_providers.
	 *
	 * @param mixed $providers Providers so far.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register( $providers ): array {
		$providers               = is_array( $providers ) ? $providers : array();
		$providers['moksafocou'] = array(
			'label'                => 'Moksa Coupons',
			'version'              => MOKSAFOCOU_VERSION,
			'ability_prefix'       => 'moksafocou/',
			'enabled'              => Server::enabled(),
			'destructive_allow'    => self::allowed(),
			'capability'           => 'manage_woocommerce',
			'legacy_route'         => array( Server::NS, Server::ROUTE ),
			'destructive_handlers' => array( \Moksafocou\Modules\AiAssistant\Config::class, 'destructive_handlers' ),
			'resources'            => array( Catalog::class, 'resources' ),
			'read_resource'        => array( Catalog::class, 'read' ),
			'prompts'              => array( Catalog::class, 'prompts' ),
			'get_prompt'           => array( Catalog::class, 'get_prompt' ),
		);
		return $providers;
	}

	/**
	 * Ticked destructive ability names.
	 *
	 * @return array<int,string>
	 */
	public static function allowed(): array {
		$picked = get_option( self::TOOLS_OPTION, array() );
		return is_array( $picked ) ? array_values( array_map( 'strval', $picked ) ) : array();
	}

	/**
	 * Destructive coupon abilities that can be offered over MCP: they have a prepare / apply pair and
	 * name their own capability. Ability name => { label, capability }.
	 *
	 * @return array<string,array{label:string,capability:string}>
	 */
	public static function destructive_choices(): array {
		$out = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $out;
		}
		$handlers = \Moksafocou\Modules\AiAssistant\Config::destructive_handlers();
		foreach ( wp_get_abilities( array( 'namespace' => 'moksafocou' ) ) as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$name = (string) $ability->get_name();
			if ( 0 !== strpos( $name, 'moksafocou/' ) || ! isset( $handlers[ $name ] ) ) {
				continue;
			}
			$meta = (array) $ability->get_meta();
			$cap  = (string) ( $meta['mcp']['capability'] ?? '' );
			if ( empty( $meta['annotations']['destructive'] ) || '' === $cap ) {
				continue;
			}
			$out[ $name ] = array(
				'label'      => (string) $ability->get_label(),
				'capability' => $cap,
			);
		}
		ksort( $out );
		return $out;
	}

	/** The shared endpoint URL (the legacy one keeps working). */
	public static function endpoint_url(): string {
		return class_exists( '\Moksa\MCP\Server' ) ? \Moksa\MCP\Server::endpoint_url() : Server::endpoint_url();
	}

	/**
	 * Recent destructive MCP activity on coupon tools, ready to display.
	 *
	 * @param int $limit Rows.
	 * @return array<int,array{time:string,user:string,tool:string,result:string}>
	 */
	public static function recent_changes( int $limit ): array {
		if ( ! class_exists( '\Moksa\MCP\Audit' ) ) {
			return array();
		}
		$labels = array(
			'applied'      => __( 'Carried out', 'moksa-coupons-for-woocommerce' ),
			'error'        => __( 'Failed', 'moksa-coupons-for-woocommerce' ),
			'exception'    => __( 'Failed', 'moksa-coupons-for-woocommerce' ),
			'denied'       => __( 'Refused: no permission', 'moksa-coupons-for-woocommerce' ),
			'rate_limited' => __( 'Refused: hourly limit reached', 'moksa-coupons-for-woocommerce' ),
			'stale'        => __( 'Refused: token expired or already used', 'moksa-coupons-for-woocommerce' ),
			'not_allowed'  => __( 'Refused: tool not ticked', 'moksa-coupons-for-woocommerce' ),
		);
		$rows   = array();
		foreach ( \Moksa\MCP\Audit::recent( self::TOOL_PREFIX, array_keys( $labels ) ) as $entry ) {
			if ( empty( $entry['destr'] ) ) {
				continue;
			}
			$user   = get_userdata( (int) ( $entry['user'] ?? 0 ) );
			$rows[] = array(
				'time'   => get_date_from_gmt( (string) ( $entry['t'] ?? '' ), 'Y-m-d H:i' ),
				'user'   => $user ? (string) $user->user_login : '#' . (int) ( $entry['user'] ?? 0 ),
				'tool'   => (string) ( $entry['tool'] ?? '' ),
				'result' => $labels[ (string) ( $entry['outcome'] ?? '' ) ] ?? (string) ( $entry['outcome'] ?? '' ),
			);
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}
		return $rows;
	}
}
