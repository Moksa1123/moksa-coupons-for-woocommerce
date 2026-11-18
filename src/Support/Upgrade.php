<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One-time housekeeping when the plugin is updated.
 *
 * The activation hook does not run on an update, so anything an update has to tidy up lives here.
 * It runs once per version change: the stored version stamp is compared with the running version
 * on every request (one autoloaded option read), and the steps run only when they differ.
 *
 * Every step must be idempotent — a site can skip versions, or run the same step twice if two
 * requests race right after an update.
 */
final class Upgrade {

	private const OPTION = 'moksafocou_db_version';

	public static function maybe_run(): void {
		if ( MOKSAFOCOU_VERSION === (string) FreshOption::get( self::OPTION, '' ) ) {
			return;
		}

		self::remove_legacy_chat_options();
		self::remove_legacy_mcp_switch();
		self::start_coupon_index();

		update_option( self::OPTION, MOKSAFOCOU_VERSION, true );
	}

	/**
	 * The plugin's own floating chat was replaced by the shared Moksa AI chat; its
	 * leftover code (and the only reader of these two options) was removed. Drop the options too.
	 */
	private static function remove_legacy_chat_options(): void {
		delete_option( 'moksafocou_ai_greeting' );
		delete_option( 'moksafocou_ai_examples' );
	}

	/**
	 * MCP moved to the shared Moksa MCP endpoint, where each destructive tool is ticked one by one
	 * (moksafocou_mcp_destructive_tools). The old all-or-nothing switch only ever exposed previews,
	 * so it is dropped and nothing is ticked on the owner's behalf.
	 */
	private static function remove_legacy_mcp_switch(): void {
		delete_option( 'moksafocou_mcp_expose_destructive' );
	}

	/**
	 * Coupon lookups moved from meta queries to the private-taxonomy index (CouponIndex). Build it
	 * in the background; lookups scan coupons directly until it is ready. The auto-apply ID list
	 * kept in an option is replaced by the index.
	 */
	private static function start_coupon_index(): void {
		if ( ! CouponIndex::ready() ) {
			CouponIndex::start_rebuild();
		}
		delete_option( 'moksafocou_autoapply_ids' );
		// Cached coupon-card HTML from 1.2.1 may list personal coupons; rebuild it.
		\Moksafocou\Modules\Frontend\CardsCache::flush();
	}
}
