<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Frontend;

use Moksafocou\Coupon\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Transient cache for the public [moksafocou_coupons] card wall. The rendered HTML is
 * the same for every visitor (no per-user data), so a page view that previously ran a
 * meta_query + N WC_Coupon loads + per-card work now returns a single cached string.
 *
 * Correctness:
 *  - Content changes flush the cache via a version counter (so every cached limit-variant
 *    is invalidated at once, with no key bookkeeping). Hooked on coupon save / trash /
 *    delete and on changes to the two meta keys that affect the list (show-in-list label).
 *  - Expiry is handled by the TTL: ttl_for() shortens the lifetime so the cache lapses
 *    exactly when the next listed coupon would drop off, never showing a stale-expired one
 *    longer than the (bounded) ceiling.
 */
final class CardsCache {

	private const PREFIX     = 'moksafocou_cards_';
	private const VER_OPTION = 'moksafocou_cards_ver';

	/**
	 * Site options that change the apply links baked into every card (the URL-coupon
	 * module toggle, the pretty endpoint slug, and the query-string fallback toggle).
	 * Changing any of these must invalidate the cache or stale links would persist.
	 */
	private const URL_OPTIONS = array(
		'moksafocou_url_enabled',
		'moksafocou_url_endpoint',
		'moksafocou_url_query_enabled',
	);

	/** Ceiling so an edit that somehow skips every flush hook can't go stale forever. */
	private const MAX_TTL = 6 * HOUR_IN_SECONDS;
	/** Floor so a coupon expiring imminently doesn't cause cache stampede churn. */
	private const MIN_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Request-scoped memo of the version counter (avoids re-reading the option per call).
	 *
	 * @var int|null
	 */
	private static ?int $version = null;

	/** Hook flush-on-change. Called from the Frontend module boot (cache only lives then). */
	public static function register(): void {
		add_action( 'save_post_shop_coupon', array( self::class, 'flush' ) );
		add_action( 'trashed_post', array( self::class, 'flush_for_post' ) );
		add_action( 'untrashed_post', array( self::class, 'flush_for_post' ) );
		add_action( 'deleted_post', array( self::class, 'flush_for_post' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'on_meta_change' ), 10, 3 );
		}
		// The apply link in each card depends on these site options → flush when they change.
		foreach ( self::URL_OPTIONS as $option ) {
			add_action( 'add_option_' . $option, array( self::class, 'flush' ) );
			add_action( 'update_option_' . $option, array( self::class, 'flush' ) );
		}
	}

	public static function get( int $limit ): ?string {
		$value = get_transient( self::key( $limit ) );
		return is_string( $value ) ? $value : null;
	}

	public static function set( int $limit, string $html, int $ttl ): void {
		set_transient( self::key( $limit ), $html, $ttl );
	}

	/** Bump the version → every cached limit-variant is abandoned at once (lazy expiry). */
	public static function flush(): void {
		$ver = (int) get_option( self::VER_OPTION, 0 );
		update_option( self::VER_OPTION, $ver + 1, false );
		self::$version = $ver + 1;
	}

	/**
	 * @param int|string $post_id
	 */
	public static function flush_for_post( $post_id ): void {
		if ( 'shop_coupon' === get_post_type( (int) $post_id ) ) {
			self::flush();
		}
	}

	/**
	 * Flush only when a list-affecting meta key changes on a coupon.
	 *
	 * @param int|string $meta_id    Unused (meta row id).
	 * @param int|string $object_id  Post the meta belongs to.
	 * @param string     $meta_key   Meta key that changed.
	 */
	public static function on_meta_change( $meta_id, $object_id, $meta_key ): void {
		// SHOW_IN_LIST/FRONT_LABEL change which coupons show and their label; URL_ENABLED/
		// URL_SLUG change the per-coupon apply link baked into the cached card; the urgency
		// keys change the countdown target / stock badge baked into the card. (usage_count is
		// WC-internal, not our meta — the remaining-count display refreshes via the TTL.)
		$watched = array(
			Keys::SHOW_IN_LIST,
			Keys::FRONT_LABEL,
			Keys::URL_ENABLED,
			Keys::URL_SLUG,
			Keys::COUNTDOWN_ENABLED,
			Keys::COUNTDOWN_SOURCE,
			Keys::STOCK_SHOW,
			Keys::STOCK_THRESHOLD,
		);
		if ( ! in_array( $meta_key, $watched, true ) ) {
			return;
		}
		if ( 'shop_coupon' === get_post_type( (int) $object_id ) ) {
			self::flush();
		}
	}

	private static function key( int $limit ): string {
		if ( null === self::$version ) {
			self::$version = (int) get_option( self::VER_OPTION, 0 );
		}
		return self::PREFIX . self::$version . '_' . $limit;
	}

	/**
	 * Pure: cache lifetime for a list whose coupons lapse at the given epochs. Returns the
	 * time until the soonest still-future expiry, clamped to [MIN_TTL, MAX_TTL]; MAX_TTL
	 * when nothing expires. Keeps the cached HTML from outliving a coupon's validity.
	 *
	 * @param array<int,int|null> $valid_untils Per-coupon exclusive validity end epoch (or null = never).
	 * @param int                 $now          Current epoch.
	 */
	public static function ttl_for( array $valid_untils, int $now ): int {
		$soonest = null;
		foreach ( $valid_untils as $valid_until ) {
			if ( null === $valid_until || $valid_until <= $now ) {
				continue;
			}
			if ( null === $soonest || $valid_until < $soonest ) {
				$soonest = $valid_until;
			}
		}
		if ( null === $soonest ) {
			return self::MAX_TTL;
		}
		return max( self::MIN_TTL, min( self::MAX_TTL, $soonest - $now ) );
	}
}
