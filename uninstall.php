<?php
/**
 * Uninstall cleanup for Moksa Coupons for WooCommerce.
 *
 * Removes every plugin option and the plugin's own post-meta and index terms. Coupons themselves
 * (shop_coupon CPT) are intentionally left untouched — they are user content created via WooCommerce.
 *
 * @package Moksafocou
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// uninstall.php runs without the plugin's autoloader: load the few classes used here directly.
foreach ( array( 'Support/OptionRegistry.php', 'Support/PersonalCoupon.php', 'Coupon/Meta/Keys.php', 'Support/Cron.php', 'Support/CouponIndex.php' ) as $moksafocou_file ) {
	if ( is_readable( __DIR__ . '/src/' . $moksafocou_file ) ) {
		require_once __DIR__ . '/src/' . $moksafocou_file;
	}
}

/*
 * Options: every option this plugin wrote is recorded by Support\OptionRegistry (added_option hook
 * plus a one-time back-fill), so each is deleted through the options API — WordPress has no API for
 * deleting by prefix. As a safety net, also sweep the back-fill sources (autoloaded options with
 * our prefix, and the known autoload-off ones), in case the back-fill never ran on this site.
 * Transients are not recorded; WordPress deletes expired ones daily.
 */
if ( class_exists( \Moksafocou\Support\OptionRegistry::class ) ) {
	$moksafocou_options = \Moksafocou\Support\OptionRegistry::names();
	foreach ( array_keys( wp_load_alloptions() ) as $moksafocou_name ) {
		if ( is_string( $moksafocou_name ) && \Moksafocou\Support\OptionRegistry::ours( $moksafocou_name ) ) {
			$moksafocou_options[] = $moksafocou_name;
		}
	}
	$moksafocou_options = array_merge( $moksafocou_options, \Moksafocou\Support\OptionRegistry::known_unautoloaded() );
	foreach ( array_unique( $moksafocou_options ) as $moksafocou_name ) {
		delete_option( $moksafocou_name );
	}
	delete_option( \Moksafocou\Support\OptionRegistry::OPTION );
}

/*
 * The shared MCP audit log (lib/moksa-mcp) is site-wide: delete it only when no other plugin still
 * bundles moksa-mcp (it is the last one going).
 */
$moksafocou_mcp_others = glob( WP_PLUGIN_DIR . '/*/lib/moksa-mcp/moksa-mcp.php' );
$moksafocou_mcp_others = array_filter(
	is_array( $moksafocou_mcp_others ) ? $moksafocou_mcp_others : array(),
	static function ( $path ) {
		return false === strpos( wp_normalize_path( (string) $path ), wp_normalize_path( __DIR__ ) . '/' );
	}
);
if ( array() === $moksafocou_mcp_others ) {
	delete_option( 'moksa_mcp_audit' );
	delete_option( 'moksa_mcp_audit_changes' );
}

// Coupon lookup index (private taxonomy): delete every term through the taxonomy API.
if ( class_exists( \Moksafocou\Support\CouponIndex::class ) ) {
	\Moksafocou\Support\CouponIndex::purge();
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
 * Post-meta NOT in Keys::all(): the coupon-owner link (MyAccount), the order-side stamps for the
 * remarketing + cashback runtimes, and the expiry-reminder "already sent" stamp.
 */
foreach ( array( '_moksafocou_owner_user', '_moksafocou_remarketing_issued', '_moksafocou_cashback_awarded', '_moksafocou_expiry_reminded' ) as $moksafocou_extra_meta ) {
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
