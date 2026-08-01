<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\MyAccount;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 'My Account coupons' module — lazy-loaded, boots only when moksafocou_myaccount_enabled is 'yes'.
 * Adds a 'My coupons' tab to the WooCommerce My Account area listing the coupons issued to /
 * locked to the logged-in customer. The marketing loop: a coupon sent to a customer (via the
 * send-coupon ability or a post-purchase rule) shows up here for them to copy or one-click apply.
 */
final class Module extends AbstractModule {

	private const REWRITE_FLAG = 'moksafocou_myaccount_rewrite';

	public function slug(): string {
		return 'myaccount';
	}

	public function label(): string {
		return __( 'My Account coupons', 'moksafocou' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Show customer-exclusive coupons in WooCommerce "My Account", with copy or one-click apply', 'moksafocou' );
	}

	public function boot(): void {
		add_action( 'init', array( Endpoint::class, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( Endpoint::class, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . Endpoint::SLUG . '_endpoint', array( Endpoint::class, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( Endpoint::class, 'enqueue' ) );

		// Register the endpoint then flush rewrite rules once per plugin version (the new endpoint
		// needs fresh rules to resolve). Cheap after the first run thanks to the version flag.
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
	}

	public static function maybe_flush(): void {
		if ( get_option( self::REWRITE_FLAG ) === MOKSAFOCOU_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::REWRITE_FLAG, MOKSAFOCOU_VERSION );
	}
}
