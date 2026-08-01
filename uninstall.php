<?php
/**
 * Uninstall cleanup for Moksa Coupons for WooCommerce.
 *
 * Removes every plugin option / transient (matched by the moksafocou_ prefix, so new options are
 * covered automatically) and the plugin's own post-meta. Coupons themselves (shop_coupon CPT) are
 * intentionally left untouched — they are user content created via WooCommerce.
 *
 * @package Moksafocou
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/*
 * Prefix sweep of every plugin option + its transients. A prepared LIKE on the prefix removes all
 * moksafocou_* options (module toggles, URL/cron/rewrite flags, remarketing config, AI settings…)
 * without an ever-stale hand-maintained list. Direct queries are the correct tool for one-shot
 * uninstall cleanup (no caching applies, and the data is being deleted).
 */
$moksafocou_like    = $wpdb->esc_like( 'moksafocou_' ) . '%';
$moksafocou_t_like  = $wpdb->esc_like( '_transient_moksafocou_' ) . '%';
$moksafocou_tt_like = $wpdb->esc_like( '_transient_timeout_moksafocou_' ) . '%';
foreach ( array( $moksafocou_like, $moksafocou_t_like, $moksafocou_tt_like ) as $moksafocou_pattern ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot uninstall cleanup; options table, prepared LIKE, nothing to cache.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $moksafocou_pattern ) );
}

/*
 * Delete the plugin's own coupon meta. uninstall.php has no PSR-4 autoloader, so load the Keys
 * class directly (guarded) and reuse its single source of truth.
 */
$moksafocou_keys_file = __DIR__ . '/src/Coupon/Meta/Keys.php';
if ( is_readable( $moksafocou_keys_file ) ) {
	require_once $moksafocou_keys_file;
	if ( class_exists( \Moksafocou\Coupon\Meta\Keys::class ) ) {
		foreach ( \Moksafocou\Coupon\Meta\Keys::all() as $moksafocou_meta_key ) {
			delete_post_meta_by_key( $moksafocou_meta_key );
		}
	}
}

/*
 * Post-meta NOT in Keys::all(): the coupon-owner link (MyAccount) and the order-side stamps for
 * the remarketing + cashback runtimes.
 */
foreach ( array( '_moksafocou_owner_user', '_moksafocou_remarketing_issued', '_moksafocou_cashback_awarded' ) as $moksafocou_extra_meta ) {
	delete_post_meta_by_key( $moksafocou_extra_meta );
}

// Per-user "recently used templates" list (stored as user meta on the templates page).
delete_metadata( 'user', 0, 'moksafocou_recent_templates', '', true );

// Scheduled cron events.
wp_clear_scheduled_hook( 'moksafocou_cron_daily' );
wp_clear_scheduled_hook( 'moksafocou_cron_hourly' );

// Action Scheduler group cleanup (safe no-op if unused).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'moksa-coupons-for-woocommerce' );
}
