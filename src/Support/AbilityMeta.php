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

	/** Meta for a write/destructive ability. @return array<string,mixed> */
	public static function write(): array {
		return array(
			'show_in_rest' => false,
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
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
