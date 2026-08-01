<?php
/**
 * Lightweight PHPUnit bootstrap. Does not load WordPress; provides minimal
 * polyfills so pure-logic units can be tested in isolation.
 *
 * @package Moksafocou\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// WordPress time constants used in plugin const expressions (e.g. cache TTLs).
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- polyfilling WP core constants.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = 'default' ): string { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string { // phpcs:ignore
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( $str ) ) ?? '' );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $str ): string { // phpcs:ignore
		return trim( wp_strip_all_tags( $str ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string { // phpcs:ignore
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '' );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ): int { // phpcs:ignore
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) { // phpcs:ignore
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	// Test double: returns $GLOBALS['__mfc_test_can'] (default true) so tests can simulate
	// an unauthorised user without a real WP roles/caps stack.
	function current_user_can( $capability, ...$args ): bool { // phpcs:ignore
		return (bool) ( $GLOBALS['__mfc_test_can'] ?? true );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	// Test double: a nonce is "valid" iff it equals "valid:<action>", making verification
	// deterministic and tied to the action argument (no WP session/token machinery needed).
	function wp_verify_nonce( $nonce, $action = -1 ) { // phpcs:ignore
		return ( $nonce === 'valid:' . $action ) ? 1 : false;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ) { // phpcs:ignore
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string { // phpcs:ignore
		$title = strtolower( trim( wp_strip_all_tags( $title ) ) );
		$title = preg_replace( '/[^a-z0-9\s\-]/', '', $title ) ?? '';
		return trim( preg_replace( '/[\s\-]+/', '-', $title ) ?? '', '-' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $string ): string { // phpcs:ignore
		return trim( strip_tags( $string ) );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore
		private string $code;
		private string $message;
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

// Built-in PSR-4 autoloader for src/ (mirrors the runtime fallback).
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Moksafocou\\';
		$length = strlen( $prefix );
		if ( strncmp( $prefix, $class, $length ) !== 0 ) {
			return;
		}
		$relative = substr( $class, $length );
		$path     = __DIR__ . '/../src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
