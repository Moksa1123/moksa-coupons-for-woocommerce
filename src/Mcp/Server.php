<?php

declare( strict_types=1 );

namespace Moksafocou\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Compliant stateless MCP (Model Context Protocol) Streamable HTTP server.
 *
 * Wraps this plugin's registered WP Abilities as MCP tools so any standard MCP
 * client (mcp-remote / Claude's built-in HTTP client) can connect directly with
 * no bridge. Design:
 * - Stateless: initialize sends no Mcp-Session-Id and later requests do not require one.
 * - Compliant tool objects: name / description / inputSchema / outputSchema / annotations
 *   only; annotations use the spec camelCase keys (readOnlyHint…).
 * - outputSchema is always an object (non-object schemas are wrapped, and the call
 *   result is wrapped to match).
 * - tools/list is cached (transient keyed by version + destructive-exposure + the exposed
 *   ability names, so it self-invalidates whenever the available tool set changes).
 * - Auth: permission_callback requires manage_woocommerce (WP Application Password
 *   Basic auth or cookie+nonce); destructive abilities are not exposed by default.
 *
 * Runs in parallel with the official WordPress MCP Adapter: abilities also carry
 * meta.mcp.public=true so an installed mcp-adapter can discover them too.
 */
final class Server {

	const NS       = 'moksafocou/v1';
	const ROUTE    = '/mcp';
	const PROTOCOL = '2025-06-18';

	public static function enabled(): bool {
		return 'yes' === get_option( 'moksafocou_mcp_server_enabled', 'no' );
	}

	public static function register(): void {
		if ( ! self::enabled() ) {
			return;
		}
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle' ),
					'permission_callback' => array( self::class, 'authorize' ),
				),
				// This server offers no SSE stream / session termination → spec requires 405 for GET/DELETE.
				array(
					'methods'             => 'GET, DELETE',
					'callback'            => array( self::class, 'method_not_allowed' ),
					'permission_callback' => array( self::class, 'authorize' ),
				),
			)
		);
	}

	public static function authorize(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function method_not_allowed(): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 405 );
		$response->header( 'Allow', 'POST' );
		return $response;
	}

	/**
	 * @param \WP_REST_Request $request JSON-RPC request (single or batch).
	 * @return \WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array() === $body ) {
			return new \WP_REST_Response( self::rpc_error( null, -32700, 'Parse error' ), 200 );
		}

		if ( array_is_list( $body ) ) {
			if ( count( $body ) > 50 ) {
				return new \WP_REST_Response( self::rpc_error( null, -32600, 'Batch too large' ), 200 );
			}
			$out = array();
			foreach ( $body as $msg ) {
				$res = self::dispatch( is_array( $msg ) ? $msg : array() );
				if ( null !== $res ) {
					$out[] = $res;
				}
			}
			return new \WP_REST_Response( array() === $out ? null : $out, array() === $out ? 202 : 200 );
		}

		$res = self::dispatch( $body );
		if ( null === $res ) {
			return new \WP_REST_Response( null, 202 ); // notification → no response.
		}
		return new \WP_REST_Response( $res, 200 );
	}

	/**
	 * @param array<string,mixed> $msg Single JSON-RPC message.
	 * @return array<string,mixed>|null null = notification (no response).
	 */
	private static function dispatch( array $msg ): ?array {
		$id      = $msg['id'] ?? null;
		$method  = isset( $msg['method'] ) ? (string) $msg['method'] : '';
		$params  = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();
		$is_note = ! array_key_exists( 'id', $msg );

		switch ( $method ) {
			case 'initialize':
				// Return the version this server supports (spec: do not echo the client version).
				return self::rpc_result(
					$id,
					array(
						'protocolVersion' => self::PROTOCOL,
						'capabilities'    => array(
							'tools'     => array( 'listChanged' => false ),
							'resources' => array( 'listChanged' => false ),
							'prompts'   => array( 'listChanged' => false ),
						),
						'serverInfo'      => array(
							'name'    => 'moksafocou',
							'title'   => 'Moksa Coupons',
							'version' => MOKSAFOCOU_VERSION,
						),
					)
				);

			case 'notifications/initialized':
			case 'notifications/cancelled':
				return null;

			case 'ping':
				return self::rpc_result( $id, (object) array() );

			case 'tools/list':
				return self::rpc_result( $id, array( 'tools' => self::tools() ) );

			case 'tools/call':
				return self::call_tool( $id, $params );

			case 'resources/list':
				return self::rpc_result( $id, array( 'resources' => self::resource_defs() ) );

			case 'resources/read':
				return self::read_resource( $id, $params );

			case 'prompts/list':
				return self::rpc_result( $id, array( 'prompts' => self::prompt_defs() ) );

			case 'prompts/get':
				return self::get_prompt( $id, $params );

			default:
				return $is_note ? null : self::rpc_error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * The abilities this plugin exposes (name => WP_Ability), applying mcp.public +
	 * the destructive gate.
	 *
	 * @return array<string, object>
	 */
	private static function abilities(): array {
		$out = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $out;
		}
		$expose_destructive = 'yes' === get_option( 'moksafocou_mcp_expose_destructive', 'no' );
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$name = (string) $ability->get_name();
			if ( 0 !== strpos( $name, 'moksafocou/' ) ) {
				continue;
			}
			$meta = (array) $ability->get_meta();
			$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
			if ( array_key_exists( 'public', $mcp ) && ! $mcp['public'] ) {
				continue;
			}
			$ann = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			if ( ! empty( $ann['destructive'] ) && ! $expose_destructive ) {
				continue;
			}
			$out[ $name ] = $ability;
		}
		return $out;
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	private static function tools(): array {
		// Enumerating abilities is cheap (in-memory registry); building each tool schema is
		// the cost we cache. Fold the current ability NAMES into the cache key so the cache
		// self-invalidates the moment the exposed tool set changes — a module toggled on/off,
		// the expose-destructive option, a filter, or a plugin update — with no manual hooks.
		$abilities = self::abilities();
		$key       = 'moksafocou_mcp_tools_' . md5(
			MOKSAFOCOU_VERSION . '|'
			. get_option( 'moksafocou_mcp_expose_destructive', 'no' ) . '|'
			. implode( ',', array_keys( $abilities ) )
		);
		$cached    = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$tools = array();
		foreach ( $abilities as $ability ) {
			$tools[] = self::tool_schema( $ability );
		}
		set_transient( $key, $tools, DAY_IN_SECONDS );
		return $tools;
	}

	/**
	 * @param object $ability WP_Ability.
	 * @return array<string,mixed>
	 */
	private static function tool_schema( $ability ): array {
		$name  = (string) $ability->get_name();
		$meta  = (array) $ability->get_meta();
		$ann   = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$input = $ability->get_input_schema();
		$input = is_array( $input ) && ! empty( $input )
			? $input
			: array(
				'type'       => 'object',
				'properties' => (object) array(),
			);

		$tool = array(
			'name'        => self::tool_name( $name ),
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

		$out = $ability->get_output_schema();
		if ( is_array( $out ) && ! empty( $out ) ) {
			$wrap_key             = self::wrap_key( $out );
			$tool['outputSchema'] = null === $wrap_key
				? $out
				: array(
					'type'       => 'object',
					'properties' => array( $wrap_key => $out ),
					'required'   => array( $wrap_key ),
				);
		}
		return $tool;
	}

	/**
	 * @param mixed               $id     JSON-RPC id.
	 * @param array<string,mixed> $params { name, arguments }.
	 * @return array<string,mixed>
	 */
	private static function call_tool( $id, array $params ): array {
		$tool_name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args      = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$abilities    = self::abilities();
		$ability_name = self::ability_name( $tool_name );
		if ( '' === $ability_name || ! isset( $abilities[ $ability_name ] ) ) {
			return self::rpc_error( $id, -32602, 'Unknown tool: ' . $tool_name );
		}
		$ability = $abilities[ $ability_name ];

		$perm = $ability->check_permissions( $args );
		if ( is_wp_error( $perm ) || false === $perm ) {
			return self::tool_error( $id, __( 'You do not have permission to do this.', 'moksafocou' ) );
		}

		try {
			$result = $ability->execute( $args );
		} catch ( \Throwable $e ) {
			$message = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
				? $e->getMessage()
				: __( 'The tool failed to run; please try again later.', 'moksafocou' );
			return self::tool_error( $id, $message );
		}
		if ( is_wp_error( $result ) ) {
			return self::tool_error( $id, $result->get_error_message() );
		}

		$wrap_key   = self::wrap_key( $ability->get_output_schema() );
		$structured = null !== $wrap_key ? array( $wrap_key => $result ) : $result;

		return self::rpc_result(
			$id,
			array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => (string) wp_json_encode( $structured, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
					),
				),
				'structuredContent' => $structured,
				'isError'           => false,
			)
		);
	}

	/* ---------------- resources (read-only domain models) ---------------- */

	/**
	 * Read-only domain models a client can fetch once instead of repeatedly calling tools.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function resource_defs(): array {
		return array(
			array(
				'uri'         => 'moksafocou://rule-types',
				'name'        => __( 'Advanced rule types', 'moksafocou' ),
				'description' => __( '26 AND/OR advanced rule types, operators, and value shapes.', 'moksafocou' ),
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'moksafocou://settings-schema',
				'name'        => __( 'Coupon advanced settings schema', 'moksafocou' ),
				'description' => __( 'JSON schema for the moksafocou advanced settings object for create / update.', 'moksafocou' ),
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'moksafocou://templates',
				'name'        => __( 'Coupon template library', 'moksafocou' ),
				'description' => __( 'Built-in coupon templates (id / name / description / category / type).', 'moksafocou' ),
				'mimeType'    => 'application/json',
			),
		);
	}

	/**
	 * @param string $uri Resource uri.
	 * @return array<mixed>|null Payload, or null when the uri is unknown.
	 */
	private static function resource_payload( string $uri ): ?array {
		switch ( $uri ) {
			case 'moksafocou://rule-types':
				return \Moksafocou\Support\Rules::types();
			case 'moksafocou://settings-schema':
				return \Moksafocou\Coupon\Meta\CouponSettings::schema();
			case 'moksafocou://templates':
				$out = array();
				foreach ( \Moksafocou\Modules\Templates\Catalog::all() as $tpl ) {
					$out[] = array(
						'id'       => (string) ( $tpl['id'] ?? '' ),
						'label'    => (string) ( $tpl['label'] ?? '' ),
						'desc'     => (string) ( $tpl['desc'] ?? '' ),
						'category' => (string) ( $tpl['category'] ?? '' ),
						'type_key' => (string) ( $tpl['type_key'] ?? '' ),
					);
				}
				return $out;
			default:
				return null;
		}
	}

	/**
	 * @param mixed               $id     JSON-RPC id.
	 * @param array<string,mixed> $params { uri }.
	 * @return array<string,mixed>
	 */
	private static function read_resource( $id, array $params ): array {
		$uri     = isset( $params['uri'] ) ? (string) $params['uri'] : '';
		$payload = self::resource_payload( $uri );
		if ( null === $payload ) {
			return self::rpc_error( $id, -32602, 'Unknown resource: ' . $uri );
		}
		return self::rpc_result(
			$id,
			array(
				'contents' => array(
					array(
						'uri'      => $uri,
						'mimeType' => 'application/json',
						'text'     => (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
					),
				),
			)
		);
	}

	/* ---------------- prompts ---------------- */

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function prompt_defs(): array {
		return array(
			array(
				'name'        => 'create-coupon-from-idea',
				'description' => __( 'Guide coupon creation with a single marketing idea', 'moksafocou' ),
				'arguments'   => array(
					array(
						'name'        => 'idea',
						'description' => __( 'Marketing campaign idea, for example "Site-wide 10% off for the anniversary sale"', 'moksafocou' ),
						'required'    => true,
					),
				),
			),
			array(
				'name'        => 'campaign-ideas',
				'description' => __( 'Brainstorm coupon campaign ideas based on the store context', 'moksafocou' ),
				'arguments'   => array(
					array(
						'name'        => 'goal',
						'description' => __( 'Goal, for example raise average order value / acquire new customers', 'moksafocou' ),
						'required'    => false,
					),
				),
			),
		);
	}

	/**
	 * @param mixed               $id     JSON-RPC id.
	 * @param array<string,mixed> $params { name, arguments }.
	 * @return array<string,mixed>
	 */
	private static function get_prompt( $id, array $params ): array {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		if ( 'create-coupon-from-idea' === $name ) {
			$idea = isset( $args['idea'] ) ? (string) $args['idea'] : '';
			$text = sprintf(
				/* translators: %s: the marketing idea. */
				__( 'Please create a coupon with the moksafocou tools based on this marketing idea: "%s". First use find-coupon-by-code to confirm the code is not duplicated; when advanced conditions are needed, use list-rule-types / get-settings-schema to check the details, then call create-coupon or create-tiered-coupon.', 'moksafocou' ),
				$idea
			);
		} elseif ( 'campaign-ideas' === $name ) {
			$goal = isset( $args['goal'] ) ? (string) $args['goal'] : '';
			$text = '' !== $goal
				? sprintf(
					/* translators: %s: the campaign goal. */
					__( 'For the goal "%s", use list-templates to reference the built-in templates and propose 3 coupon campaign ideas suitable for this store, explaining the setup.', 'moksafocou' ),
					$goal
				)
				: __( 'Use list-templates to reference the built-in templates and propose 3 coupon campaign ideas suitable for this store, explaining the setup.', 'moksafocou' );
		} else {
			return self::rpc_error( $id, -32602, 'Unknown prompt: ' . $name );
		}

		return self::rpc_result(
			$id,
			array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => array(
							'type' => 'text',
							'text' => $text,
						),
					),
				),
			)
		);
	}

	/**
	 * Non-object output schemas must be wrapped to be compliant; returns the wrap
	 * key (null = no wrap needed).
	 *
	 * @param mixed $out output schema.
	 */
	private static function wrap_key( $out ): ?string {
		if ( ! is_array( $out ) || empty( $out ) ) {
			return null;
		}
		return ( isset( $out['type'] ) && 'object' === $out['type'] ) ? null : 'results';
	}

	private static function tool_name( string $ability ): string {
		return str_replace( '/', '-', $ability );
	}

	private static function ability_name( string $tool ): string {
		if ( 0 !== strpos( $tool, 'moksafocou-' ) ) {
			return '';
		}
		return 'moksafocou/' . substr( $tool, strlen( 'moksafocou-' ) );
	}

	/**
	 * @param mixed                      $id     JSON-RPC id.
	 * @param array<string,mixed>|object $result Result.
	 * @return array<string,mixed>
	 */
	private static function rpc_result( $id, $result ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * @param mixed $id JSON-RPC id.
	 * @return array<string,mixed>
	 */
	private static function rpc_error( $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * Tool execution error → spec requires a normal result with isError=true (not a
	 * JSON-RPC error).
	 *
	 * @param mixed $id JSON-RPC id.
	 * @return array<string,mixed>
	 */
	private static function tool_error( $id, string $message ): array {
		return self::rpc_result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $message,
					),
				),
				'isError' => true,
			)
		);
	}
}
