<?php
/**
 * What the endpoint offers: the plugins that registered as providers, their abilities as MCP
 * tools, and their resources / prompts.
 *
 * A plugin joins by adding itself to the `moksa_mcp_providers` filter (see the design doc,
 * moforcoupon/docs/design/moksa-mcp.md §4.1). Unknown or missing keys fall back to the safe
 * value: disabled, no destructive tool open, manage_woocommerce.
 *
 * A destructive ability is offered only when all three hold:
 *   1. the shop owner ticked it (the provider's `destructive_allow` list);
 *   2. it has a prepare / apply handler pair (two-step confirm);
 *   3. it names its own capability in meta.mcp.capability.
 *
 * @package Moksa\MCP
 */

declare( strict_types=1 );

namespace Moksa\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Providers, tools, resources, prompts.
 */
final class Catalog {

	public const APPLY_TOOL = 'moksa-apply';

	/**
	 * Every registered provider, normalised (key => provider).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function providers(): array {
		$raw = apply_filters( 'moksa_mcp_providers', array() );
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $key => $p ) {
			$key = is_string( $key ) ? sanitize_key( $key ) : '';
			if ( '' === $key || ! is_array( $p ) ) {
				continue;
			}
			$prefix = isset( $p['ability_prefix'] ) ? (string) $p['ability_prefix'] : '';
			if ( '' === $prefix ) {
				continue;
			}
			$legacy = null;
			if ( isset( $p['legacy_route'] ) && is_array( $p['legacy_route'] ) && 2 === count( $p['legacy_route'] ) ) {
				$legacy = array( (string) $p['legacy_route'][0], (string) $p['legacy_route'][1] );
			}
			$out[ $key ] = array(
				'key'                  => $key,
				'label'                => isset( $p['label'] ) ? (string) $p['label'] : $key,
				'version'              => isset( $p['version'] ) ? (string) $p['version'] : '',
				'ability_prefix'       => rtrim( $prefix, '/' ) . '/',
				'enabled'              => ! empty( $p['enabled'] ),
				'destructive_allow'    => isset( $p['destructive_allow'] ) && is_array( $p['destructive_allow'] )
					? array_values( array_map( 'strval', $p['destructive_allow'] ) )
					: array(),
				'capability'           => isset( $p['capability'] ) && '' !== (string) $p['capability'] ? (string) $p['capability'] : 'manage_woocommerce',
				'legacy_route'         => $legacy,
				'destructive_limit'    => isset( $p['destructive_limit'] ) && is_numeric( $p['destructive_limit'] ) ? (int) $p['destructive_limit'] : null,
				'destructive_handlers' => isset( $p['destructive_handlers'] ) && is_callable( $p['destructive_handlers'] ) ? $p['destructive_handlers'] : null,
				'resources'            => isset( $p['resources'] ) && is_callable( $p['resources'] ) ? $p['resources'] : null,
				'read_resource'        => isset( $p['read_resource'] ) && is_callable( $p['read_resource'] ) ? $p['read_resource'] : null,
				'prompts'              => isset( $p['prompts'] ) && is_callable( $p['prompts'] ) ? $p['prompts'] : null,
				'get_prompt'           => isset( $p['get_prompt'] ) && is_callable( $p['get_prompt'] ) ? $p['get_prompt'] : null,
			);
		}
		return $out;
	}

	/**
	 * Providers the shop owner switched on.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function enabled_providers(): array {
		return array_filter(
			self::providers(),
			static function ( array $p ): bool {
				return $p['enabled'];
			}
		);
	}

	/**
	 * Providers in scope: all enabled ones, or just one (a legacy route).
	 *
	 * @param string $scope Provider key, or '' for all.
	 * @return array<string,array<string,mixed>>
	 */
	public static function scoped( string $scope ): array {
		$enabled = self::enabled_providers();
		if ( '' === $scope ) {
			return $enabled;
		}
		return isset( $enabled[ $scope ] ) ? array( $scope => $enabled[ $scope ] ) : array();
	}

	/**
	 * Can the current user reach at least one provider in scope?
	 *
	 * @param string $scope Provider key, or '' for all.
	 */
	public static function user_can_any( string $scope ): bool {
		foreach ( self::scoped( $scope ) as $p ) {
			if ( current_user_can( $p['capability'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Destructive handler table of one provider (ability name => [prepare, apply]). The provider's
	 * own callable comes first; the moksa-ai filter fills in anything it does not cover.
	 *
	 * @param array<string,mixed> $provider Normalised provider.
	 * @return array<string,array{prepare:callable,apply:callable}>
	 */
	public static function handlers( array $provider ): array {
		$own = null !== $provider['destructive_handlers'] ? call_user_func( $provider['destructive_handlers'] ) : array();
		$ai  = apply_filters( 'moksa_ai_destructive_handlers', array() );
		$all = array_merge( is_array( $ai ) ? $ai : array(), is_array( $own ) ? $own : array() );
		$out = array();
		foreach ( $all as $name => $pair ) {
			if ( is_array( $pair ) && isset( $pair['prepare'], $pair['apply'] )
				&& is_callable( $pair['prepare'] ) && is_callable( $pair['apply'] ) ) {
				$out[ (string) $name ] = $pair;
			}
		}
		return $out;
	}

	/**
	 * Abilities offered in scope, before the per-user check.
	 * Tool name => { ability, provider, destructive, capability }.
	 *
	 * @param string $scope Provider key, or '' for all.
	 * @return array<string,array<string,mixed>>
	 */
	public static function entries( string $scope ): array {
		$out = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $out;
		}
		foreach ( self::scoped( $scope ) as $key => $p ) {
			$prefix   = $p['ability_prefix'];
			$handlers = null;
			// The namespace argument is WP 7.1+; older versions ignore it and return every
			// ability, so the prefix check below is the real filter.
			foreach ( wp_get_abilities( array( 'namespace' => rtrim( $prefix, '/' ) ) ) as $ability ) {
				if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
					continue;
				}
				$name = (string) $ability->get_name();
				if ( 0 !== strpos( $name, $prefix ) ) {
					continue;
				}
				$meta        = (array) $ability->get_meta();
				$mcp         = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
				$ann         = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
				$destructive = ! empty( $ann['destructive'] );
				$capability  = $p['capability'];

				if ( $destructive ) {
					// Opt-in per tool, two-step capable, and carrying its own capability.
					if ( ! in_array( $name, $p['destructive_allow'], true ) ) {
						continue;
					}
					if ( null === $handlers ) {
						$handlers = self::handlers( $p );
					}
					if ( ! isset( $handlers[ $name ] ) ) {
						continue;
					}
					$capability = isset( $mcp['capability'] ) ? (string) $mcp['capability'] : '';
					if ( '' === $capability ) {
						continue;
					}
				} elseif ( array_key_exists( 'public', $mcp ) && ! $mcp['public'] ) {
					continue;
				}

				$out[ self::tool_name( $name ) ] = array(
					'ability'     => $ability,
					'name'        => $name,
					'provider'    => $key,
					'destructive' => $destructive,
					'capability'  => $capability,
				);
			}
		}
		return $out;
	}

	/**
	 * May the current user use this entry? Provider capability, plus the tool's own one.
	 *
	 * @param array<string,mixed> $entry Entry from entries().
	 */
	public static function user_can( array $entry ): bool {
		$providers = self::providers();
		$provider  = $providers[ $entry['provider'] ] ?? null;
		if ( null === $provider || ! current_user_can( $provider['capability'] ) ) {
			return false;
		}
		return current_user_can( (string) $entry['capability'] );
	}

	/**
	 * The tools/list answer for the current user.
	 *
	 * @param string $scope Provider key, or '' for all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function tools( string $scope ): array {
		$entries = self::entries( $scope );
		$schemas = self::schemas( $scope, $entries );
		$tools   = array();
		$any_dx  = false;
		foreach ( $entries as $tool => $entry ) {
			if ( ! isset( $schemas[ $tool ] ) || ! self::user_can( $entry ) ) {
				continue;
			}
			$tools[] = $schemas[ $tool ];
			$any_dx  = $any_dx || $entry['destructive'];
		}
		if ( $any_dx ) {
			$tools[] = self::apply_tool_schema();
		}
		return $tools;
	}

	/**
	 * The shared apply tool.
	 *
	 * @return array<string,mixed>
	 */
	public static function apply_tool_schema(): array {
		return array(
			'name'        => self::APPLY_TOOL,
			'description' => Strings::get( 'applyDescription' ),
			'inputSchema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'token' => array(
						'type'        => 'string',
						'description' => Strings::get( 'applyToken' ),
						'minLength'   => 16,
						'maxLength'   => 64,
					),
				),
				'required'             => array( 'token' ),
				'additionalProperties' => false,
			),
			'annotations' => array(
				'title'           => Strings::get( 'applyTitle' ),
				'readOnlyHint'    => false,
				'destructiveHint' => true,
				'idempotentHint'  => false,
				'openWorldHint'   => false,
			),
		);
	}

	/**
	 * Wrap key for a non-object output schema (null = no wrap needed).
	 *
	 * @param mixed $schema Output schema.
	 */
	public static function wrap_key( $schema ): ?string {
		if ( ! is_array( $schema ) || array() === $schema ) {
			return null;
		}
		return ( isset( $schema['type'] ) && 'object' === $schema['type'] ) ? null : 'results';
	}

	/**
	 * Ability name to MCP tool name ('moksafocou/create-coupon' -> 'moksafocou-create-coupon').
	 *
	 * @param string $ability Ability name.
	 */
	public static function tool_name( string $ability ): string {
		return str_replace( '/', '-', $ability );
	}

	/**
	 * Resource definitions from every provider in scope the user can reach.
	 *
	 * @param string $scope Provider key, or '' for all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function resources( string $scope ): array {
		$out = array();
		foreach ( self::scoped( $scope ) as $p ) {
			if ( null === $p['resources'] || ! current_user_can( $p['capability'] ) ) {
				continue;
			}
			$list = call_user_func( $p['resources'] );
			foreach ( is_array( $list ) ? $list : array() as $def ) {
				if ( is_array( $def ) && isset( $def['uri'] ) ) {
					$out[] = $def;
				}
			}
		}
		return $out;
	}

	/**
	 * Read one resource: the provider that lists the uri answers it.
	 *
	 * @param string $scope Provider key, or '' for all.
	 * @param string $uri   Resource uri.
	 * @return mixed|null Payload (array, or WP_Error from the provider), null when nobody owns the uri.
	 */
	public static function read_resource( string $scope, string $uri ) {
		foreach ( self::scoped( $scope ) as $p ) {
			if ( null === $p['resources'] || null === $p['read_resource'] || ! current_user_can( $p['capability'] ) ) {
				continue;
			}
			$list = call_user_func( $p['resources'] );
			foreach ( is_array( $list ) ? $list : array() as $def ) {
				if ( is_array( $def ) && isset( $def['uri'] ) && $uri === (string) $def['uri'] ) {
					return call_user_func( $p['read_resource'], $uri );
				}
			}
		}
		return null;
	}

	/**
	 * Prompt definitions from every provider in scope the user can reach.
	 *
	 * @param string $scope Provider key, or '' for all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function prompts( string $scope ): array {
		$out = array();
		foreach ( self::scoped( $scope ) as $p ) {
			if ( null === $p['prompts'] || ! current_user_can( $p['capability'] ) ) {
				continue;
			}
			$list = call_user_func( $p['prompts'] );
			foreach ( is_array( $list ) ? $list : array() as $def ) {
				if ( is_array( $def ) && isset( $def['name'] ) ) {
					$out[] = $def;
				}
			}
		}
		return $out;
	}

	/**
	 * Build one prompt: the provider that lists the name answers it.
	 *
	 * @param string              $scope Provider key, or '' for all.
	 * @param string              $name  Prompt name.
	 * @param array<string,mixed> $args  Prompt arguments.
	 * @return mixed|null Messages array, or null when nobody owns the name.
	 */
	public static function get_prompt( string $scope, string $name, array $args ) {
		foreach ( self::scoped( $scope ) as $p ) {
			if ( null === $p['prompts'] || null === $p['get_prompt'] || ! current_user_can( $p['capability'] ) ) {
				continue;
			}
			$list = call_user_func( $p['prompts'] );
			foreach ( is_array( $list ) ? $list : array() as $def ) {
				if ( is_array( $def ) && isset( $def['name'] ) && $name === (string) $def['name'] ) {
					return call_user_func( $p['get_prompt'], $name, $args );
				}
			}
		}
		return null;
	}

	/**
	 * Tool schemas for the entries, cached. The key folds in everything that changes the set:
	 * this copy's version, each provider's version and open-tool list, and the tool names.
	 *
	 * @param string                            $scope   Provider key, or '' for all.
	 * @param array<string,array<string,mixed>> $entries Entries from entries().
	 * @return array<string,array<string,mixed>>
	 */
	private static function schemas( string $scope, array $entries ): array {
		$sig = array( Server::version(), $scope );
		foreach ( self::scoped( $scope ) as $key => $p ) {
			$sig[] = $key . '@' . $p['version'] . ':' . implode( ',', $p['destructive_allow'] );
		}
		$sig[]  = implode( ',', array_keys( $entries ) );
		$key    = 'moksa_mcp_tools_' . md5( implode( '|', $sig ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out = array();
		foreach ( $entries as $tool => $entry ) {
			$out[ $tool ] = self::tool_schema( $tool, $entry['ability'] );
		}
		set_transient( $key, $out, DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * One ability as an MCP tool object.
	 *
	 * @param string $tool    Tool name.
	 * @param object $ability WP_Ability.
	 * @return array<string,mixed>
	 */
	private static function tool_schema( string $tool, $ability ): array {
		$meta  = (array) $ability->get_meta();
		$ann   = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$input = self::for_client( $ability->get_input_schema() );
		if ( ! is_array( $input ) || array() === $input ) {
			$input = array(
				'type'       => 'object',
				'properties' => (object) array(),
			);
		}
		$schema = array(
			'name'        => $tool,
			'description' => (string) $ability->get_description(),
			'inputSchema' => $input,
			'annotations' => array(
				'title'           => (string) $ability->get_label(),
				'readOnlyHint'    => ! empty( $ann['readonly'] ),
				'destructiveHint' => ! empty( $ann['destructive'] ),
				'idempotentHint'  => ! empty( $ann['idempotent'] ),
				'openWorldHint'   => false,
			),
		);
		if ( empty( $ann['destructive'] ) ) {
			$out = self::for_client( $ability->get_output_schema() );
			if ( is_array( $out ) && array() !== $out ) {
				$wrap                   = self::wrap_key( $out );
				$schema['outputSchema'] = null === $wrap
					? $out
					: array(
						'type'       => 'object',
						'properties' => array( $wrap => $out ),
						'required'   => array( $wrap ),
					);
			}
		}
		return $schema;
	}

	/**
	 * Strip server-only schema keys (WP 7.1+); older versions pass through.
	 *
	 * @param mixed $schema Schema.
	 * @return mixed
	 */
	private static function for_client( $schema ) {
		if ( ! is_array( $schema ) || array() === $schema ) {
			return $schema;
		}
		return function_exists( 'wp_prepare_json_schema_for_client' )
			? (array) wp_prepare_json_schema_for_client( $schema )
			: $schema;
	}
}
