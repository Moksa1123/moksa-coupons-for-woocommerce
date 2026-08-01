<?php
/**
 * Moksa AI Launcher — the single elected instance. Renders ONE floating admin assistant that
 * is ability-driven over the whole WordPress Abilities API (every Moksa plugin's namespace),
 * so it operates the entire suite from one window. Loaded once by the version election in
 * moksa-ai.php; never instantiate directly.
 *
 * @package Moksa\AI
 */

declare( strict_types=1 );

namespace Moksa\AI;

defined( 'ABSPATH' ) || exit;

final class Launcher {

	private const REST_NS = 'moksa-ai/v1';
	private const VERSION = '1.1.1';

	private static bool $booted = false;
	private static string $url  = '';

	/**
	 * Boot the single launcher. $dir = the elected copy's directory (its assets live under it).
	 *
	 * 注意：新功能只能掛在這裡，不能加到 moksa-ai.php 的 moksa_ai_boot()。
	 * 那個函式是「第一個被載入的副本」定義的，可能是舊版；但它 require 的一定是
	 * 選舉勝出（最新版）那份的 Launcher.php。所以這裡才是新舊交界。
	 */
	public static function init( string $dir ): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		// Filesystem dir → URL of this bundled copy (works wherever a plugin bundled it).
		self::$url = plugins_url( '', $dir . '/moksa-ai.php' );

		require_once __DIR__ . '/Registry.php';
		require_once __DIR__ . '/Agent.php';
		require_once __DIR__ . '/Host.php';

		// 對話窗與命令選盤兩邊都掛上，各自在 enqueue 當下才判斷該不該畫自己
		// （見 Host::enqueue() 與 self::enqueue()）。不在這裡就決定，是因為各外掛
		// 註冊 abilities 的時機不一致，這裡判斷等於賭 hook 順序。
		Host::init( $dir );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/** Sections registered by each plugin (label/icon/namespace/chips); pure presentation grouping. */
	public static function sections(): array {
		$sections = (array) apply_filters( 'moksa_ai_sections', array() );
		$clean    = array();
		foreach ( $sections as $s ) {
			if ( is_array( $s ) && ! empty( $s['namespace'] ) ) {
				$clean[] = array(
					'id'        => (string) ( $s['id'] ?? sanitize_key( (string) $s['namespace'] ) ),
					'label'     => (string) ( $s['label'] ?? $s['namespace'] ),
					'icon'      => (string) ( $s['icon'] ?? 'admin-generic' ),
					'namespace' => (string) $s['namespace'],
					'chips'     => isset( $s['chips'] ) && is_array( $s['chips'] ) ? array_values( $s['chips'] ) : array(),
				);
			}
		}
		return $clean;
	}

	public static function enqueue( string $hook ): void {
		// Admin-only assistant; show on every admin screen (it spans the suite).
		if ( ! is_admin() || ! is_user_logged_in() ) {
			return;
		}
		// 這個選盤是 fallback：只要有真正的 AI 對話窗在跑就讓位，確保全站只有一個窗。
		if ( Registry::has_agent() || Registry::host_suppressed() ) {
			return;
		}
		$base = self::$url;
		$css  = self::$url ? plugins_url( 'assets/launcher.css', self::elected_main() ) : '';
		$js   = self::$url ? plugins_url( 'assets/launcher.js', self::elected_main() ) : '';
		if ( '' === $js ) {
			return;
		}
		wp_enqueue_style( 'moksa-ai-launcher', $css, array(), self::VERSION );
		wp_enqueue_script( 'moksa-ai-launcher', $js, array(), self::VERSION, true );
		wp_localize_script(
			'moksa-ai-launcher',
			'moksaAI',
			array(
				'rest'     => esc_url_raw( rest_url( self::REST_NS ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'sections' => self::sections(),
				'strings'  => array(
					'title'   => Registry::string( 'paletteTitle' ),
					'search'  => Registry::string( 'paletteSearch' ),
					'run'     => Registry::string( 'paletteRun' ),
					'confirm' => Registry::string( 'paletteConfirm' ),
					'empty'   => Registry::string( 'paletteEmpty' ),
				),
			)
		);
	}

	/** The elected copy's moksa-ai.php path (anchor for plugins_url of the assets). */
	private static function elected_main(): string {
		// self::$url is the dir URL; rebuild a filesystem anchor via the pool's latest dir.
		$pool = isset( $GLOBALS['moksa_ai_pool'] ) && is_array( $GLOBALS['moksa_ai_pool'] ) ? $GLOBALS['moksa_ai_pool'] : array();
		usort( $pool, static fn( $a, $b ) => version_compare( (string) ( $a['version'] ?? '0' ), (string) ( $b['version'] ?? '0' ) ) );
		$latest = end( $pool );
		return (string) ( $latest['dir'] ?? '' ) . '/moksa-ai.php';
	}

	public static function routes(): void {
		register_rest_route(
			self::REST_NS,
			'/abilities',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'list_abilities' ),
				'permission_callback' => static fn(): bool => is_user_logged_in(),
			)
		);
		register_rest_route(
			self::REST_NS,
			'/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'run_ability' ),
				'permission_callback' => static fn(): bool => is_user_logged_in(),
			)
		);
	}

	/** Namespaces the platform has declared (from sections) — only these are surfaced. */
	private static function allowed_namespaces(): array {
		$ns = array();
		foreach ( self::sections() as $s ) {
			$ns[] = $s['namespace'];
		}
		return $ns;
	}

	/** List the public abilities the current user is permitted to run, grouped data for the panel. */
	public static function list_abilities(): \WP_REST_Response {
		$out = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return new \WP_REST_Response( array( 'abilities' => $out ), 200 );
		}
		$namespaces = self::allowed_namespaces();
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$name = (string) $ability->get_name();
			// Only abilities whose namespace a Moksa plugin declared (e.g. points/, member/…).
			$in_ns = false;
			foreach ( $namespaces as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					$in_ns = true;
					break;
				}
			}
			if ( ! $in_ns ) {
				continue;
			}
			$meta = (array) $ability->get_meta();
			$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
			if ( array_key_exists( 'public', $mcp ) && ! $mcp['public'] ) {
				continue; // not agent-visible
			}
			// Only include what THIS user may run (per-ability cap, defence in depth).
			$perm = method_exists( $ability, 'check_permissions' ) ? $ability->check_permissions( array() ) : true;
			if ( is_wp_error( $perm ) || false === $perm ) {
				continue;
			}
			$ann         = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			$destructive = ! empty( $ann['destructive'] );
			$out[]       = array(
				'name'         => $name,
				'namespace'    => substr( $name, 0, (int) strpos( $name, '/' ) + 1 ),
				'label'        => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $name,
				'description'  => method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '',
				'destructive'  => $destructive,
				'input_schema' => method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null,
			);
		}
		return new \WP_REST_Response( array( 'abilities' => $out ), 200 );
	}

	/** Execute one ability after re-checking its permission; destructive needs an explicit confirm. */
	public static function run_ability( \WP_REST_Request $request ): \WP_REST_Response {
		$name    = (string) $request->get_param( 'ability' );
		$input   = (array) ( $request->get_param( 'input' ) ?? array() );
		$confirm = (bool) $request->get_param( 'confirm' );

		$ability = self::find_ability( $name );
		if ( null === $ability ) {
			return new \WP_REST_Response( array( 'error' => Registry::string( 'notFound' ) ), 404 );
		}
		// Only allow declared namespaces (don't become a generic ability runner for the whole site).
		$ok_ns = false;
		foreach ( self::allowed_namespaces() as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				$ok_ns = true;
				break;
			}
		}
		if ( ! $ok_ns ) {
			return new \WP_REST_Response( array( 'error' => Registry::string( 'outOfScope' ) ), 403 );
		}
		$perm = method_exists( $ability, 'check_permissions' ) ? $ability->check_permissions( $input ) : true;
		if ( is_wp_error( $perm ) || false === $perm ) {
			return new \WP_REST_Response( array( 'error' => Registry::string( 'noPermission' ) ), 403 );
		}
		$meta        = (array) $ability->get_meta();
		$ann         = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$destructive = ! empty( $ann['destructive'] );
		if ( $destructive && ! $confirm ) {
			return new \WP_REST_Response(
				array(
					'needs_confirm' => true,
					'message'       => Registry::string( 'needsConfirm' ),
				),
				200
			);
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'error' => $result->get_error_message() ), 400 );
		}
		return new \WP_REST_Response( array( 'result' => $result ), 200 );
	}

	private static function find_ability( string $name ): ?object {
		if ( '' === $name ) {
			return null;
		}
		if ( function_exists( 'wp_get_ability' ) ) {
			$a = wp_get_ability( $name );
			return is_object( $a ) ? $a : null;
		}
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $a ) {
				if ( is_object( $a ) && method_exists( $a, 'get_name' ) && $name === (string) $a->get_name() ) {
					return $a;
				}
			}
		}
		return null;
	}
}
