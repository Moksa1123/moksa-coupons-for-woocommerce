<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\UrlCoupons;

defined( 'ABSPATH' ) || exit;

/**
 * Always-on rewrite-rule lifecycle for URL coupons. The module is toggled by an
 * option long after plugin activation and there is no per-module activation hook,
 * so the /<endpoint>/ rewrite rule must be (re)generated whenever the module
 * toggle or the endpoint slug changes.
 *
 * Strategy: option changes only SET a short-lived transient; the actual
 * flush_rewrite_rules() runs once on a later request, gated by that transient (so
 * a normal cached page never flushes — the perf/wp.org trap). A version stamp adds
 * a one-time self-heal after a plugin update. register() must be called on every
 * request regardless of whether the module is enabled, otherwise toggling OFF
 * could never remove the now-stale rule.
 */
final class Lifecycle {

	private const FLUSH_TRANSIENT = 'moksafocou_url_flush';
	private const VERSION_OPTION  = 'moksafocou_url_rewrite_version';

	public static function register(): void {
		// Any change to the gate or the endpoint slug schedules a flush.
		add_action( 'add_option_moksafocou_url_enabled', array( self::class, 'schedule_flush' ) );
		add_action( 'update_option_moksafocou_url_enabled', array( self::class, 'schedule_flush' ) );
		add_action( 'add_option_' . ShareService::ENDPOINT_OPTION, array( self::class, 'schedule_flush' ) );
		add_action( 'update_option_' . ShareService::ENDPOINT_OPTION, array( self::class, 'schedule_flush' ) );

		// Endpoint slug is a plain WC text field → force sanitize_title on save.
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . ShareService::ENDPOINT_OPTION,
			array( self::class, 'sanitize_endpoint' ),
			10,
			3
		);

		// Transient-gated flush, late on init (after WC re-registers the CPT at init:5).
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
	}

	public static function schedule_flush(): void {
		set_transient( self::FLUSH_TRANSIENT, '1', 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * @param mixed $value
	 * @param mixed $option
	 * @param mixed $raw
	 * @return string
	 */
	public static function sanitize_endpoint( $value, $option = '', $raw = '' ): string {
		$slug = sanitize_title( (string) $value );
		// Avoid clobbering core/permalink bases that would shadow real pages.
		$reserved = array( 'wp-admin', 'wp-json', 'wp-content', 'feed', 'page', 'comments', 'cart', 'checkout', 'shop', 'product', 'product-category', 'product-tag' );
		if ( '' === $slug || in_array( $slug, $reserved, true ) ) {
			return ShareService::ENDPOINT_DEFAULT;
		}
		return $slug;
	}

	/**
	 * Flush once when scheduled, or one-time after a plugin/version change so a
	 * lost rule self-heals. Never flushes on an ordinary request.
	 */
	public static function maybe_flush(): void {
		$scheduled = (bool) get_transient( self::FLUSH_TRANSIENT );
		$stale     = ShareService::module_enabled()
			&& (string) get_option( self::VERSION_OPTION, '' ) !== MOKSAFOCOU_VERSION;

		if ( ! $scheduled && ! $stale ) {
			return;
		}

		flush_rewrite_rules( false );
		delete_transient( self::FLUSH_TRANSIENT );
		update_option( self::VERSION_OPTION, MOKSAFOCOU_VERSION, false );
	}

	/** Deactivation: drop the stale /<endpoint>/ rule (flush is impossible at uninstall). */
	public static function on_deactivate(): void {
		delete_transient( self::FLUSH_TRANSIENT );
		delete_option( self::VERSION_OPTION );
		flush_rewrite_rules( false );
	}
}
