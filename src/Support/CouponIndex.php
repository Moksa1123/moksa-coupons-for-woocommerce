<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

use Moksafocou\Coupon\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Lookup index for coupons, kept in a private taxonomy.
 *
 * Questions like "which coupons are auto-applied", "which coupon owns the URL code summer" or
 * "which coupons belong to customer 12" used to be meta queries over every coupon. This index
 * answers them from term relationships instead (the same approach as mowp's order-number index):
 *
 * - one term per fact, named "{field}:{value}", e.g. url_slug:summer, owner:12, discount_type:percent;
 *   an email restriction is stored as a hash ("email:<sha256>"), never as the address itself;
 * - each coupon–term link is its own row, written with wp_set_object_terms(), so two coupons saved
 *   at the same moment cannot overwrite each other (an option holding a whole ID list could);
 * - reads go through get_objects_in_term(), which uses the term_relationships index;
 * - deleting a coupon removes its links (WordPress does that for every registered taxonomy).
 *
 * Kept up to date from the meta hooks (WooCommerce's coupon data store writes through the meta API
 * too), rebuilt in the background on install / upgrade, and fully re-checked once a week. Until the
 * first build has finished, lookups fall back to a paged scan so nothing breaks in between.
 */
final class CouponIndex {

	public const TAXONOMY = 'moksafocou_coupon_ix';

	/** Index layout version; bump to force a rebuild when the term naming changes. */
	private const FORMAT = 1;

	private const READY_OPTION     = 'moksafocou_coupon_ix_ready';
	private const PROGRESS_OPTION  = 'moksafocou_coupon_ix_progress';
	private const LAST_FULL_OPTION = 'moksafocou_coupon_ix_last_full';
	private const STEP_HOOK        = 'moksafocou_coupon_ix_step';
	private const SCAN_MAP         = 'moksafocou_ix_scan_map';
	private const SCAN_LOCK        = 'moksafocou_ix_scan_lock';

	/** Coupons per page while rebuilding / scanning (WordPress' PostsPerPage guidance). */
	private const PAGE = 100;

	/** Pages handled per background step. */
	private const PAGES_PER_STEP = 3;

	/** The coupon-owner link written by the "My coupons" module. */
	public const OWNER_META = PersonalCoupon::OWNER_META;

	/**
	 * Coupons whose watched meta changed in this request, re-indexed once at shutdown (saving one
	 * coupon touches several meta keys; a bulk-generate touches hundreds of coupons).
	 *
	 * @var array<int,true>
	 */
	private static array $dirty = array();

	public static function boot(): void {
		if ( did_action( 'init' ) ) {
			self::register();
		} else {
			add_action( 'init', array( self::class, 'register' ), 5 );
		}
		add_action( 'added_post_meta', array( self::class, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'on_meta_change' ), 10, 3 );
		add_action( self::STEP_HOOK, array( self::class, 'step' ) );
		add_action( Cron::HOOK, array( self::class, 'weekly_check' ) );
		// Sites with WP-Cron switched off would never finish the first build: let admin page loads
		// move it forward too (one bounded step per request).
		add_action( 'admin_init', array( self::class, 'step_if_building' ) );
	}

	/** Run one rebuild step from an admin request while the first build is still going. */
	public static function step_if_building(): void {
		if ( ! self::ready() && is_array( get_option( self::PROGRESS_OPTION, null ) ) && current_user_can( 'edit_shop_coupons' ) ) {
			self::step();
		}
	}

	public static function register(): void {
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}
		register_taxonomy(
			self::TAXONOMY,
			'shop_coupon',
			array(
				'public'                => false,
				'publicly_queryable'    => false,
				'show_ui'               => false,
				'show_in_menu'          => false,
				'show_in_nav_menus'     => false,
				'show_in_rest'          => false,
				'show_tagcloud'         => false,
				'show_admin_column'     => false,
				'hierarchical'          => false,
				'rewrite'               => false,
				'query_var'             => false,
				// Count links, not published posts: draft coupons are indexed too.
				'update_count_callback' => '_update_generic_term_count',
			)
		);
	}

	/* ---------------- term naming (pure) ---------------- */

	/**
	 * Meta keys the index is built from.
	 *
	 * @return array<int,string>
	 */
	public static function watched_keys(): array {
		return array( Keys::AUTO_APPLY, Keys::URL_SLUG, Keys::SHOW_IN_LIST, Keys::CAMPAIGN, self::OWNER_META, 'customer_email', 'discount_type' );
	}

	/**
	 * Term names for one coupon, from its meta values (key => value). Pure, so it is unit-tested.
	 *
	 * @param array<string,mixed> $meta Meta key => value.
	 * @return array<int,string>
	 */
	public static function names_from_meta( array $meta ): array {
		$names = array();
		if ( 'yes' === ( $meta[ Keys::AUTO_APPLY ] ?? '' ) ) {
			$names[] = 'auto_apply:1';
		}
		$slug = trim( (string) ( $meta[ Keys::URL_SLUG ] ?? '' ) );
		if ( '' !== $slug ) {
			$names[] = self::name( 'url_slug', strtolower( $slug ) );
		}
		if ( 'yes' === ( $meta[ Keys::SHOW_IN_LIST ] ?? '' ) ) {
			$names[] = 'list_visible:1';
		}
		if ( '' !== trim( (string) ( $meta[ Keys::CAMPAIGN ] ?? '' ) ) ) {
			$names[] = 'campaign:1';
		}
		$personal = false;
		$owner    = (int) ( $meta[ self::OWNER_META ] ?? 0 );
		if ( $owner > 0 ) {
			$names[]  = 'owner:' . $owner;
			$personal = true;
		}
		foreach ( (array) ( $meta['customer_email'] ?? array() ) as $email ) {
			$term = self::email_term( (string) $email );
			if ( '' !== $term ) {
				$names[]  = $term;
				$personal = true;
			}
		}
		if ( $personal ) {
			$names[] = 'personal:1';
		}
		$type = trim( (string) ( $meta['discount_type'] ?? '' ) );
		if ( '' !== $type ) {
			$names[] = self::name( 'discount_type', $type );
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Term name for an email restriction: a hash of the lower-cased address, never the address.
	 *
	 * @param string $email Email address.
	 */
	public static function email_term( string $email ): string {
		$email = strtolower( trim( $email ) );
		return '' === $email ? '' : 'email:' . substr( hash( 'sha256', $email ), 0, 40 );
	}

	/**
	 * "{field}:{value}", capped to the terms.name column.
	 *
	 * @param string $field Field.
	 * @param string $value Value.
	 */
	public static function name( string $field, string $value ): string {
		return mb_substr( $field . ':' . $value, 0, 190 );
	}

	/* ---------------- reads ---------------- */

	/**
	 * Coupon IDs carrying a term (any post status), or every match from a scan before the first
	 * build has finished.
	 *
	 * @param string $name Term name, e.g. "owner:12".
	 * @return array<int,int>
	 */
	public static function ids( string $name ): array {
		return self::ids_any( array( $name ) );
	}

	/**
	 * Coupon IDs carrying any of the terms.
	 *
	 * @param array<int,string> $names Term names.
	 * @return array<int,int>
	 */
	public static function ids_any( array $names ): array {
		$names = array_values( array_filter( array_map( 'strval', $names ) ) );
		if ( array() === $names ) {
			return array();
		}
		if ( ! self::ready() ) {
			return self::scan( $names );
		}
		self::register();
		$term_ids = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'name'       => $names,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $term_ids ) || array() === $term_ids ) {
			return array();
		}
		$objects = get_objects_in_term( array_map( 'intval', $term_ids ), self::TAXONOMY );
		return is_array( $objects ) ? array_values( array_unique( array_map( 'intval', $objects ) ) ) : array();
	}

	/** Has the first full build finished (for the current layout)? */
	public static function ready(): bool {
		return self::FORMAT === (int) get_option( self::READY_OPTION, 0 );
	}

	/* ---------------- writes ---------------- */

	/**
	 * Re-derive one coupon's terms from its current meta.
	 *
	 * @param int $coupon_id Coupon post ID.
	 */
	public static function reindex( int $coupon_id ): void {
		if ( $coupon_id <= 0 || 'shop_coupon' !== get_post_type( $coupon_id ) ) {
			return;
		}
		self::register();
		$before = wp_get_object_terms( $coupon_id, self::TAXONOMY, array( 'fields' => 'ids' ) );
		wp_set_object_terms( $coupon_id, self::names_from_meta( self::meta_of( $coupon_id ) ), self::TAXONOMY, false );
		self::delete_orphans( is_array( $before ) ? array_map( 'intval', $before ) : array() );
	}

	/**
	 * Keep the index current whenever a watched meta changes, through any path (admin, REST,
	 * WooCommerce's data store, AI / MCP, WP-CLI). Only marks the coupon; flush() does the work.
	 *
	 * @param mixed $meta_id   Unused.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 */
	public static function on_meta_change( $meta_id, $object_id, $meta_key ): void {
		if ( ! is_string( $meta_key ) || ! in_array( $meta_key, self::watched_keys(), true ) || (int) $object_id <= 0 ) {
			return;
		}
		if ( array() === self::$dirty ) {
			add_action( 'shutdown', array( self::class, 'flush' ) );
		}
		self::$dirty[ (int) $object_id ] = true;
	}

	/** Re-index every coupon changed in this request (runs at shutdown; callable earlier). */
	public static function flush(): void {
		$ids         = array_keys( self::$dirty );
		self::$dirty = array();
		foreach ( $ids as $id ) {
			self::reindex( (int) $id );
		}
	}

	/* ---------------- rebuild ---------------- */

	/** Start a background rebuild (no-op while one is already running). */
	public static function start_rebuild(): void {
		$progress = get_option( self::PROGRESS_OPTION, null );
		if ( is_array( $progress ) && ( time() - (int) ( $progress['touched'] ?? 0 ) ) < HOUR_IN_SECONDS ) {
			return;
		}
		update_option(
			self::PROGRESS_OPTION,
			array(
				'page'    => 1,
				'touched' => time(),
			),
			false
		);
		self::schedule_step();
	}

	/** One background step: a few pages of coupons, then schedule the next step or finish. */
	public static function step(): void {
		$progress = get_option( self::PROGRESS_OPTION, null );
		if ( ! is_array( $progress ) ) {
			return;
		}
		$page = max( 1, (int) ( $progress['page'] ?? 1 ) );
		for ( $i = 0; $i < self::PAGES_PER_STEP; $i++, $page++ ) {
			$ids = self::page_of_ids( $page );
			foreach ( $ids as $id ) {
				self::reindex( $id );
			}
			if ( count( $ids ) < self::PAGE ) {
				self::finish();
				return;
			}
		}
		update_option(
			self::PROGRESS_OPTION,
			array(
				'page'    => $page,
				'touched' => time(),
			),
			false
		);
		self::schedule_step();
	}

	/** Daily cron: start the weekly full re-check (and the first build if it never ran). */
	public static function weekly_check(): void {
		$last = (int) get_option( self::LAST_FULL_OPTION, 0 );
		if ( ! self::ready() || ( time() - $last ) >= WEEK_IN_SECONDS ) {
			self::start_rebuild();
		}
	}

	/** Remove every index term (uninstall). Safe to call when the taxonomy is not registered yet. */
	public static function purge(): void {
		self::register();
		do {
			$term_ids = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => false,
					'fields'     => 'ids',
					'number'     => self::PAGE,
				)
			);
			$term_ids = is_array( $term_ids ) ? $term_ids : array();
			foreach ( $term_ids as $tid ) {
				wp_delete_term( (int) $tid, self::TAXONOMY );
			}
			$full = count( $term_ids ) === self::PAGE;
		} while ( $full );
		foreach ( array( self::READY_OPTION, self::PROGRESS_OPTION, self::LAST_FULL_OPTION ) as $option ) {
			delete_option( $option );
		}
		wp_clear_scheduled_hook( self::STEP_HOOK );
	}

	/* ---------------- internals ---------------- */

	private static function finish(): void {
		self::delete_empty_terms();
		update_option( self::READY_OPTION, self::FORMAT, false );
		update_option( self::LAST_FULL_OPTION, time(), false );
		delete_option( self::PROGRESS_OPTION );
	}

	private static function schedule_step(): void {
		if ( ! wp_next_scheduled( self::STEP_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::STEP_HOOK );
		}
	}

	/**
	 * One page of coupon IDs (every status), oldest first, no meta conditions.
	 *
	 * @param int $page 1-based page.
	 * @return array<int,int>
	 */
	private static function page_of_ids( int $page ): array {
		$ids = get_posts(
			array(
				'post_type'              => 'shop_coupon',
				'post_status'            => 'any',
				'posts_per_page'         => self::PAGE,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Before the first build: walk every coupon and match its would-be terms. Correct but slow,
	 * and only used until the background build has finished (minutes after install / upgrade).
	 * Lookups can come from anonymous cart requests, so a result is kept for a few minutes: however
	 * many requests arrive, each lookup scans at most once per window.
	 *
	 * @param array<int,string> $names Term names.
	 * @return array<int,int>
	 */
	private static function scan( array $names ): array {
		$small = array();
		$large = array();
		foreach ( $names as $name ) {
			if ( self::is_small_name( $name ) ) {
				$small[] = $name;
			} else {
				$large[] = $name;
			}
		}
		$found = array();
		if ( array() !== $small ) {
			$map = self::small_map();
			foreach ( $small as $name ) {
				foreach ( (array) ( $map[ $name ] ?? array() ) as $id ) {
					$found[ (int) $id ] = (int) $id;
				}
			}
		}
		// Owner / email / personal / discount-type lists can be as large as the store. Before the index
		// is ready they are only scanned for admin, cron and CLI work; a front-end request gets nothing
		// for them (My coupons shows its list once the background build finishes, minutes later).
		if ( array() !== $large && ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
			foreach ( self::walk( $large ) as $name => $ids ) {
				foreach ( $ids as $id ) {
					$found[ (int) $id ] = (int) $id;
				}
			}
		}
		return array_values( $found );
	}

	/**
	 * Names whose coupon lists stay small (set by the shop owner per coupon), safe to keep in one
	 * cached map: auto-apply, show-in-list, campaign, URL codes.
	 *
	 * @param string $name Term name.
	 */
	private static function is_small_name( string $name ): bool {
		foreach ( array( 'auto_apply:', 'list_visible:', 'campaign:', 'url_slug:' ) as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One cached map of the small names for the whole store, under one fixed key, so whatever names
	 * a visitor's URL asks for, the store is scanned at most once per window — and only by one
	 * request at a time (the others get an empty answer until the map is there).
	 *
	 * @return array<string,array<int,int>>
	 */
	private static function small_map(): array {
		$map = get_transient( self::SCAN_MAP );
		if ( is_array( $map ) ) {
			return $map;
		}
		$lock = (int) get_option( self::SCAN_LOCK, 0 );
		if ( $lock > 0 && ( time() - $lock ) < MINUTE_IN_SECONDS ) {
			return array();
		}
		delete_option( self::SCAN_LOCK );
		if ( ! add_option( self::SCAN_LOCK, time(), '', false ) ) {
			return array(); // Another request just took the lock.
		}
		$map = array();
		foreach ( self::walk( null ) as $name => $ids ) {
			if ( self::is_small_name( $name ) ) {
				$map[ $name ] = $ids;
			}
		}
		set_transient( self::SCAN_MAP, $map, 5 * MINUTE_IN_SECONDS );
		delete_option( self::SCAN_LOCK );
		return $map;
	}

	/**
	 * Walk every coupon once (100 per page, meta primed per page) and group IDs by term name.
	 *
	 * @param array<int,string>|null $only Keep only these names (null = all).
	 * @return array<string,array<int,int>>
	 */
	private static function walk( ?array $only ): array {
		$out = array();
		for ( $page = 1; ; $page++ ) {
			$ids = self::page_of_ids( $page );
			update_meta_cache( 'post', $ids );
			foreach ( $ids as $id ) {
				foreach ( self::names_from_meta( self::meta_of( $id ) ) as $name ) {
					if ( null === $only || in_array( $name, $only, true ) ) {
						$out[ $name ][] = $id;
					}
				}
			}
			if ( count( $ids ) < self::PAGE ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The watched meta of one coupon.
	 *
	 * @param int $coupon_id Coupon.
	 * @return array<string,mixed>
	 */
	private static function meta_of( int $coupon_id ): array {
		$meta = array();
		foreach ( self::watched_keys() as $key ) {
			$meta[ $key ] = get_post_meta( $coupon_id, $key, true );
		}
		return $meta;
	}

	/**
	 * Delete terms that no coupon carries any more.
	 *
	 * @param array<int,int> $term_ids Terms the coupon carried before.
	 */
	private static function delete_orphans( array $term_ids ): void {
		foreach ( $term_ids as $tid ) {
			$term = get_term( $tid, self::TAXONOMY );
			if ( $term instanceof \WP_Term && 0 === (int) $term->count ) {
				wp_delete_term( $tid, self::TAXONOMY );
			}
		}
	}

	/** Sweep terms left empty by coupons that were deleted outright. */
	private static function delete_empty_terms(): void {
		$offset = 0;
		do {
			$terms = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => false,
					'number'     => self::PAGE,
					'offset'     => $offset,
				)
			);
			$terms = is_array( $terms ) ? $terms : array();
			foreach ( $terms as $term ) {
				if ( $term instanceof \WP_Term && 0 === (int) $term->count ) {
					wp_delete_term( (int) $term->term_id, self::TAXONOMY );
				} else {
					++$offset;
				}
			}
			$full = count( $terms ) === self::PAGE;
		} while ( $full );
	}

	private function __construct() {}
}
