<?php
/**
 * The site's one MCP endpoint (stateless Streamable HTTP, JSON-RPC 2.0).
 *
 * POST /wp-json/moksa/v1/mcp lists the tools of every Moksa plugin that registered as a provider
 * and is switched on. A provider may also keep its old endpoint (its `legacy_route`); that route
 * runs this same handler scoped to that one provider, so a client set up before the merge sees
 * exactly the tools it saw before.
 *
 * Every tools/call checks again: the provider's capability, the tool's own capability (for
 * destructive tools) and the ability's permission callback — being logged in is never enough.
 * Destructive tools go through Confirm (propose / apply); every call lands in Audit.
 *
 * @package Moksa\MCP
 */

declare( strict_types=1 );

namespace Moksa\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Routes and JSON-RPC dispatch.
 */
final class Server {

	public const NAMESPACE_V1 = 'moksa/v1';

	public const ROUTE = '/mcp';

	public const PROTOCOL = '2025-06-18';

	/**
	 * Directory of the copy that won the election.
	 *
	 * @var string
	 */
	private static $dir = '';

	/**
	 * Version of the copy that won the election.
	 *
	 * @var string
	 */
	private static $version = '';

	/**
	 * Boot (called once by moksa_mcp_boot with the winning copy).
	 *
	 * @param string $dir     Library directory.
	 * @param string $version Library version.
	 */
	public static function init( string $dir, string $version = '' ): void {
		if ( '' !== self::$dir ) {
			return;
		}
		self::$dir     = $dir;
		self::$version = $version;
		Audit::init();
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	/**
	 * Version of the serving copy.
	 */
	public static function version(): string {
		return self::$version;
	}

	/**
	 * Absolute URL of the shared endpoint.
	 */
	public static function endpoint_url(): string {
		return rest_url( self::NAMESPACE_V1 . self::ROUTE );
	}

	/**
	 * Register the shared route, plus each enabled provider's legacy route.
	 */
	public static function register(): void {
		$enabled = Catalog::enabled_providers();
		if ( array() === $enabled ) {
			return;
		}
		self::route( self::NAMESPACE_V1, self::ROUTE, '' );
		foreach ( $enabled as $key => $p ) {
			if ( null !== $p['legacy_route'] ) {
				self::route( $p['legacy_route'][0], $p['legacy_route'][1], $key );
			}
		}
	}

	/**
	 * One route (POST = JSON-RPC; GET / DELETE = 405, no SSE stream or session to end).
	 *
	 * @param string $ns    REST namespace.
	 * @param string $route Route.
	 * @param string $scope Provider key ('' = every provider).
	 */
	private static function route( string $ns, string $route, string $scope ): void {
		$permission = static function () use ( $scope ): bool {
			return is_user_logged_in() && Catalog::user_can_any( $scope );
		};
		register_rest_route(
			$ns,
			$route,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => static function ( \WP_REST_Request $request ) use ( $scope ): \WP_REST_Response {
						return self::handle( $request, $scope );
					},
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'GET, DELETE',
					'callback'            => array( self::class, 'method_not_allowed' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/**
	 * 405 for GET / DELETE.
	 */
	public static function method_not_allowed(): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 405 );
		$response->header( 'Allow', 'POST' );
		return $response;
	}

	/**
	 * Handle one HTTP request (a single message or a batch).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $scope   Provider key ('' = every provider).
	 */
	public static function handle( \WP_REST_Request $request, string $scope = '' ): \WP_REST_Response {
		if ( ! CallGuard::body_ok( (string) $request->get_body() ) ) {
			return new \WP_REST_Response( self::rpc_error( null, -32600, Strings::get( 'tooLarge' ) ), 200 );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array() === $body ) {
			return new \WP_REST_Response( self::rpc_error( null, -32700, 'Parse error' ), 200 );
		}

		// Every message counts against the per-minute cap, so a batch cannot multiply it.
		$messages = array_is_list( $body ) ? count( $body ) : 1;
		if ( $messages > CallGuard::MAX_BATCH ) {
			return new \WP_REST_Response( self::rpc_error( null, -32600, Strings::get( 'tooLarge' ) ), 200 );
		}
		if ( ! CallGuard::take_calls( get_current_user_id(), $messages ) ) {
			return new \WP_REST_Response( self::rpc_error( null, -32000, Strings::get( 'rateLimited' ) ), 429 );
		}

		if ( array_is_list( $body ) ) {
			$out = array();
			foreach ( $body as $msg ) {
				$res = self::dispatch( is_array( $msg ) ? $msg : array(), $scope );
				if ( null !== $res ) {
					$out[] = $res;
				}
			}
			return new \WP_REST_Response( array() === $out ? null : $out, array() === $out ? 202 : 200 );
		}

		$res = self::dispatch( $body, $scope );
		return null === $res ? new \WP_REST_Response( null, 202 ) : new \WP_REST_Response( $res, 200 );
	}

	/**
	 * Dispatch one JSON-RPC message.
	 *
	 * @param array<string,mixed> $msg   Message.
	 * @param string              $scope Provider key.
	 * @return array<string,mixed>|null Null for a notification.
	 */
	private static function dispatch( array $msg, string $scope ): ?array {
		$id      = $msg['id'] ?? null;
		$method  = isset( $msg['method'] ) ? (string) $msg['method'] : '';
		$params  = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();
		$is_note = ! array_key_exists( 'id', $msg );

		switch ( $method ) {
			case 'initialize':
				return self::rpc_result( $id, self::server_info( $scope ) );

			case 'notifications/initialized':
			case 'notifications/cancelled':
				return null;

			case 'ping':
				return self::rpc_result( $id, (object) array() );

			case 'tools/list':
				return self::rpc_result( $id, array( 'tools' => Catalog::tools( $scope ) ) );

			case 'tools/call':
				return self::call_tool( $id, $params, $scope );

			case 'resources/list':
				return self::rpc_result( $id, array( 'resources' => Catalog::resources( $scope ) ) );

			case 'resources/read':
				return self::read_resource( $id, $params, $scope );

			case 'prompts/list':
				return self::rpc_result( $id, array( 'prompts' => Catalog::prompts( $scope ) ) );

			case 'prompts/get':
				return self::get_prompt( $id, $params, $scope );

			default:
				return $is_note ? null : self::rpc_error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * Initialize result. A legacy route answers as its plugin did before, so existing clients see
	 * the same server name.
	 *
	 * @param string $scope Provider key.
	 * @return array<string,mixed>
	 */
	private static function server_info( string $scope ): array {
		$info = array(
			'name'    => 'moksa-mcp',
			'title'   => 'Moksa',
			'version' => self::$version,
		);
		if ( '' !== $scope ) {
			$providers = Catalog::providers();
			if ( isset( $providers[ $scope ] ) ) {
				$info = array(
					'name'    => $scope,
					'title'   => $providers[ $scope ]['label'],
					'version' => $providers[ $scope ]['version'],
				);
			}
		}
		return array(
			'protocolVersion' => self::PROTOCOL,
			'capabilities'    => array(
				'tools'     => array( 'listChanged' => false ),
				'resources' => array( 'listChanged' => false ),
				'prompts'   => array( 'listChanged' => false ),
			),
			'serverInfo'      => $info,
		);
	}

	/**
	 * Handle tools/call.
	 *
	 * @param mixed               $id     JSON-RPC id.
	 * @param array<string,mixed> $params { name, arguments }.
	 * @param string              $scope  Provider key.
	 * @return array<string,mixed>
	 */
	private static function call_tool( $id, array $params, string $scope ): array {
		$tool = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$via  = '' === $scope ? 'main' : $scope;

		if ( ! CallGuard::args_ok( $args ) ) {
			Audit::log( $tool, false, 'too_large', array(), $via );
			return self::rpc_error( $id, -32602, Strings::get( 'tooLarge' ) );
		}

		if ( Catalog::APPLY_TOOL === $tool ) {
			$done = Confirm::apply( isset( $args['token'] ) ? (string) $args['token'] : '', $scope );
			// applied / error / exception only happen after the token was spent (Confirm::apply).
			$spent = in_array( $done['outcome'], array( 'applied', 'error', 'exception' ), true );
			Audit::log( $done['tool'], true, $done['outcome'], array(), $via, $done['args_hash'], $spent );
			if ( is_wp_error( $done['result'] ) ) {
				return self::tool_error( $id, $done['result']->get_error_message() );
			}
			return self::tool_result( $id, $done['result'] );
		}

		$entries = Catalog::entries( $scope );
		$entry   = $entries[ $tool ] ?? null;
		if ( null === $entry ) {
			return self::rpc_error( $id, -32602, 'Unknown tool: ' . $tool );
		}
		$destructive = (bool) $entry['destructive'];

		if ( ! Catalog::user_can( $entry ) || ! Confirm::permitted( $entry['ability'], $args ) ) {
			Audit::log( $tool, $destructive, 'denied', $args, $via );
			return self::tool_error( $id, Strings::get( 'noPermission' ) );
		}

		if ( $destructive ) {
			$proposed = Confirm::propose( $entry, $args );
			if ( is_wp_error( $proposed ) ) {
				Audit::log( $tool, true, 'error', $args, $via );
				return self::tool_error( $id, $proposed->get_error_message() );
			}
			Audit::log( $tool, true, 'proposed', $args, $via );
			return self::tool_result( $id, $proposed );
		}

		try {
			$result = $entry['ability']->execute( $args );
		} catch ( \Throwable $e ) {
			Audit::log( $tool, false, 'exception', $args, $via );
			return self::tool_error( $id, ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? $e->getMessage() : Strings::get( 'toolFailed' ) );
		}
		if ( is_wp_error( $result ) ) {
			Audit::log( $tool, false, 'error', $args, $via );
			return self::tool_error( $id, $result->get_error_message() );
		}
		Audit::log( $tool, false, 'ok', $args, $via );

		$truncated = false;
		$result    = CallGuard::cap_rows( $result, $truncated );
		$wrap      = Catalog::wrap_key( $entry['ability']->get_output_schema() );
		$payload   = null !== $wrap ? array( $wrap => $result ) : $result;
		$response  = self::tool_result( $id, $payload );
		if ( $truncated ) {
			array_unshift(
				$response['result']['content'],
				array(
					'type' => 'text',
					'text' => sprintf( Strings::get( 'truncated' ), CallGuard::MAX_ROWS ),
				)
			);
		}
		return $response;
	}

	/**
	 * Handle resources/read.
	 *
	 * @param mixed               $id     JSON-RPC id.
	 * @param array<string,mixed> $params { uri }.
	 * @param string              $scope  Provider key.
	 * @return array<string,mixed>
	 */
	private static function read_resource( $id, array $params, string $scope ): array {
		$uri     = isset( $params['uri'] ) ? (string) $params['uri'] : '';
		$payload = Catalog::read_resource( $scope, $uri );
		if ( null === $payload ) {
			return self::rpc_error( $id, -32602, 'Unknown resource: ' . $uri );
		}
		if ( is_wp_error( $payload ) ) {
			return self::rpc_error( $id, -32603, $payload->get_error_message() );
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

	/**
	 * Handle prompts/get. A provider's get_prompt returns either the messages array or plain text.
	 *
	 * @param mixed               $id     JSON-RPC id.
	 * @param array<string,mixed> $params { name, arguments }.
	 * @param string              $scope  Provider key.
	 * @return array<string,mixed>
	 */
	private static function get_prompt( $id, array $params, string $scope ): array {
		$name   = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args   = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$prompt = Catalog::get_prompt( $scope, $name, $args );
		if ( null === $prompt ) {
			return self::rpc_error( $id, -32602, 'Unknown prompt: ' . $name );
		}
		if ( is_wp_error( $prompt ) ) {
			return self::rpc_error( $id, -32602, $prompt->get_error_message() );
		}
		if ( is_string( $prompt ) ) {
			$prompt = array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $prompt,
					),
				),
			);
		}
		return self::rpc_result( $id, array( 'messages' => $prompt ) );
	}

	/**
	 * Successful tool result (text + structuredContent).
	 *
	 * @param mixed $id      JSON-RPC id.
	 * @param mixed $payload Structured result.
	 * @return array<string,mixed>
	 */
	private static function tool_result( $id, $payload ): array {
		return self::rpc_result(
			$id,
			array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
					),
				),
				'structuredContent' => $payload,
				'isError'           => false,
			)
		);
	}

	/**
	 * Tool failure: the spec wants a normal result with isError, not a JSON-RPC error.
	 *
	 * @param mixed  $id      JSON-RPC id.
	 * @param string $message Message.
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

	/**
	 * JSON-RPC result envelope.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param mixed $result Result.
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
	 * JSON-RPC error envelope.
	 *
	 * @param mixed  $id      JSON-RPC id.
	 * @param int    $code    Error code.
	 * @param string $message Message.
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
}
