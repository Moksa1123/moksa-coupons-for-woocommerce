<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the meta blocks attached to coupon abilities. The read-only and
 * write (destructive) annotation sets, and the shared "{summary:string}" output schema, were
 * duplicated verbatim across CouponCore\Ability and CouponCore\ToolsAbility; both now delegate
 * here so an annotation change happens in exactly one place.
 */
final class AbilityMeta {

	/** Meta for a read-only ability (safe, idempotent, public over MCP). @return array<string,mixed> */
	public static function read(): array {
		return array(
			'show_in_rest' => true,
			'public'       => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
		);
	}

	/**
	 * Meta for a write/destructive ability. `show_in_rest` keeps these off the REST ability endpoint,
	 * and `mcp.public` is always false, so the official MCP Adapter never offers a destructive tool
	 * (SOP §2.2). Over MCP they are reachable only through the shared Moksa MCP endpoint, and only the
	 * ones the shop owner ticked one by one, as a two-step propose / apply.
	 *
	 * `mcp.capability` is the tool's own capability: the shared endpoint re-checks it when the change
	 * is proposed and again when it is applied, on top of the ability's permission_callback. A write
	 * without one is never offered over MCP.
	 *
	 * @param string $mcp_capability The capability this one tool needs over MCP ('' = not offered).
	 * @return array<string,mixed>
	 */
	public static function write( string $mcp_capability = '' ): array {
		$mcp = array(
			'public' => false,
			'type'   => 'tool',
		);
		if ( '' !== $mcp_capability ) {
			$mcp['capability'] = $mcp_capability;
		}
		return array(
			'show_in_rest' => false,
			'public'       => false,
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			'mcp'          => $mcp,
		);
	}

	/** Shared output schema for abilities that return a single human-readable summary. @return array<string,mixed> */
	public static function summary_output(): array {
		return array(
			'type'       => 'object',
			'properties' => array( 'summary' => array( 'type' => 'string' ) ),
		);
	}

	/**
	 * Input schema for an ability that takes no arguments. The (object) cast keeps `properties` a
	 * JSON object — LLM providers reject `"properties":[]` as "not of type object" and 400 the
	 * whole request — and WP_Ability stores the schema verbatim, so the cast survives registration.
	 *
	 * @return array<string,mixed>
	 */
	public static function empty_input(): array {
		return array(
			'type'                 => 'object',
			'properties'           => (object) array(),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}
}
