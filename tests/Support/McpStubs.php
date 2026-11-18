<?php
/**
 * In-memory WordPress doubles for the shared MCP layer tests (lib/moksa-mcp).
 *
 * Everything lives in $GLOBALS['__mcp'] so each test can reset it. Only defines what is missing,
 * so it never replaces a double from tests/bootstrap.php.
 *
 * @package Moksafocou\Tests
 */

declare( strict_types=1 );

// phpcs:disable -- test doubles for WordPress core functions and classes.

function mcp_stub_reset(): void {
	$GLOBALS['__mcp'] = array(
		'options'    => array(),
		'notoptions' => array(),
		'ext_cache'  => true,
		'cache'      => array(),
		'transients' => array(),
		'filters'    => array(),
		'user'       => 0,
		'caps'       => array(),
		'abilities'  => array(),
	);
}
mcp_stub_reset();

// 'notoptions' models core's shared "this option does not exist" cache: a name listed there reads as
// missing even when it is stored, and add_option() then skips its existence check (as core does).
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		if ( isset( $GLOBALS['__mcp']['notoptions'][ $name ] ) ) {
			return $default;
		}
		return array_key_exists( $name, $GLOBALS['__mcp']['options'] ) ? $GLOBALS['__mcp']['options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $name, $value = '', $deprecated = '', $autoload = null ): bool {
		if ( ! isset( $GLOBALS['__mcp']['notoptions'][ $name ] ) && array_key_exists( $name, $GLOBALS['__mcp']['options'] ) ) {
			return false;
		}
		unset( $GLOBALS['__mcp']['notoptions'][ $name ] );
		$GLOBALS['__mcp']['options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache( $using = null ): bool {
		return (bool) $GLOBALS['__mcp']['ext_cache'];
	}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) {
		if ( 'notoptions' === $key && 'options' === $group ) {
			return $GLOBALS['__mcp']['notoptions'];
		}
		return $GLOBALS['__mcp']['cache'][ $group ][ $key ] ?? false;
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ): bool {
		if ( 'notoptions' === $key && 'options' === $group ) {
			$GLOBALS['__mcp']['notoptions'] = (array) $data;
			return true;
		}
		$GLOBALS['__mcp']['cache'][ $group ][ $key ] = $data;
		return true;
	}
}
if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $key, $data, $group = '', $expire = 0 ): bool {
		if ( isset( $GLOBALS['__mcp']['cache'][ $group ][ $key ] ) ) {
			return false;
		}
		$GLOBALS['__mcp']['cache'][ $group ][ $key ] = $data;
		return true;
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ): bool {
		if ( 'notoptions' === $key && 'options' === $group ) {
			$GLOBALS['__mcp']['notoptions'] = array();
			return true;
		}
		unset( $GLOBALS['__mcp']['cache'][ $group ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['__mcp']['options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $name ) {
		return $GLOBALS['__mcp']['transients'][ $name ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $name, $value, int $ttl = 0 ): bool {
		$GLOBALS['__mcp']['transients'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $name ): bool {
		if ( ! array_key_exists( $name, $GLOBALS['__mcp']['transients'] ) ) {
			return false;
		}
		unset( $GLOBALS['__mcp']['transients'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $cb, int $priority = 10, int $args = 1 ): bool {
		$GLOBALS['__mcp']['filters'][ $hook ][] = $cb;
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $cb, int $priority = 10, int $args = 1 ): bool {
		return add_filter( $hook, $cb, $priority, $args );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$rest ) {
		foreach ( $GLOBALS['__mcp']['filters'][ $hook ] ?? array() as $cb ) {
			$value = $cb( $value, ...$rest );
		}
		return $value;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) $GLOBALS['__mcp']['user'];
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return get_current_user_id() > 0;
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
		return substr( bin2hex( random_bytes( $length ) ), 0, $length );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'wp_get_abilities' ) ) {
	function wp_get_abilities( array $args = array() ): array {
		return $GLOBALS['__mcp']['abilities'];
	}
}
if ( ! function_exists( 'wp_trigger_error' ) ) {
	function wp_trigger_error( string $fn, string $message, int $level = E_USER_NOTICE ): void {
		$GLOBALS['__schema_notices'][] = $message;
	}
}
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, $value ) {
		return 'email' === $field && 'u7@example.test' === $value ? (object) array( 'ID' => 7 ) : false;
	}
}

/**
 * current_user_can() in tests/bootstrap.php reads $GLOBALS['__mfc_test_can']; when that is a
 * callable it is asked per capability (see the bootstrap).
 */
function mcp_stub_caps( array $caps ): void {
	$GLOBALS['__mcp']['caps']    = $caps;
	$GLOBALS['__mfc_test_can'] = static function ( string $cap ): bool {
		return in_array( $cap, $GLOBALS['__mcp']['caps'], true );
	};
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private string $body;
		public function __construct( string $body = '' ) {
			$this->body = $body;
		}
		public function get_body(): string {
			return $this->body;
		}
		public function get_json_params() {
			return json_decode( $this->body, true );
		}
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public int $status;
		public array $headers = array();
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}
		public function get_data() {
			return $this->data;
		}
		public function get_status(): int {
			return $this->status;
		}
	}
}

/**
 * Minimal WP_Ability double.
 */
final class McpFakeAbility {
	public int $executed = 0;
	public function __construct(
		private string $name,
		private array $meta,
		private $execute,
		private $permission = null,
		private array $output = array()
	) {}
	public function get_name(): string {
		return $this->name;
	}
	public function get_meta(): array {
		return $this->meta;
	}
	public function get_label(): string {
		return $this->name;
	}
	public function get_description(): string {
		return 'desc ' . $this->name;
	}
	public function get_input_schema(): array {
		return array( 'type' => 'object' );
	}
	public function get_output_schema(): array {
		return $this->output;
	}
	public function check_permissions( $args ) {
		return null === $this->permission ? true : ( $this->permission )( $args );
	}
	public function execute( $args ) {
		++$this->executed;
		return ( $this->execute )( $args );
	}
}

// phpcs:enable
if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $data ) {
		return is_array( $data ) || is_object( $data ) ? serialize( $data ) : $data;
	}
}
