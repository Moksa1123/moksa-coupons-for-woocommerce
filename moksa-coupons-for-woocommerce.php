<?php
/**
 * Plugin Name:        Moksa Coupons for WooCommerce
 * Plugin URI:         https://github.com/Moksa1123/moksa-coupons-for-woocommerce
 * Description:        A free, modular WooCommerce coupon toolkit: BOGO, cart conditions, role limits, scheduling, URL coupons and one-click templates, every feature off by default. Coupon actions are also WordPress Abilities, usable from an optional in-dashboard AI assistant, the REST API and MCP.
 * Version:            1.0.0
 * Requires at least:  7.0
 * Tested up to:       7.0
 * Requires PHP:       8.2
 * Requires Plugins:   woocommerce
 * WC requires at least: 10.7
 * WC tested up to:    10.9
 * Author:             MoksaWeb
 * Author URI:         https://moksaweb.com/
 * License:            GPLv3 or later
 * License URI:        https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:        moksa-coupons-for-woocommerce
 * Domain Path:        /languages
 *
 * @package Moksafocou
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/* Constants */
const MOKSAFOCOU_VERSION    = '1.0.0';
const MOKSAFOCOU_MIN_PHP    = '8.2';
const MOKSAFOCOU_MIN_WP     = '7.0';
const MOKSAFOCOU_MIN_WC     = '10.7';
const MOKSAFOCOU_TEXTDOMAIN = 'moksa-coupons-for-woocommerce';

define( 'MOKSAFOCOU_PLUGIN_FILE', __FILE__ );
define( 'MOKSAFOCOU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOKSAFOCOU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MOKSAFOCOU_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Autoload — prefer Composer's autoloader (dev / extra deps); otherwise fall back
 * to a built-in PSR-4 autoloader so core coupon features work without Composer.
 */
$moksafocou_autoload = MOKSAFOCOU_PLUGIN_DIR . 'vendor/autoload.php';
if ( is_readable( $moksafocou_autoload ) ) {
	require_once $moksafocou_autoload;
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'Moksafocou\\';
			$length = strlen( $prefix );
			if ( strncmp( $prefix, $class_name, $length ) !== 0 ) {
				return;
			}
			$relative = substr( $class_name, $length );
			$path     = MOKSAFOCOU_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

/* Shared Moksa AI launcher (bundled; single-instance version election across the suite). */
require_once __DIR__ . '/lib/moksa-ai/moksa-ai.php';
add_filter(
	'moksa_ai_sections',
	static function ( array $sections ): array {
		$sections[] = array(
			'id'        => 'coupon',
			'label'     => __( 'Coupon', 'moksa-coupons-for-woocommerce' ),
			'icon'      => 'tickets-alt',
			'namespace' => 'moksafocou/',
		);
		return $sections;
	}
);

/* HPOS + Block Checkout compatibility — must run before woocommerce_init */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				MOKSAFOCOU_PLUGIN_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				MOKSAFOCOU_PLUGIN_FILE,
				true
			);
		}
	}
);

/* Boot */
add_action(
	'plugins_loaded',
	static function (): void {
		\Moksafocou\Plugin::instance()->boot();
	},
	5
);

/*
 * i18n: no manual load_plugin_textdomain() — since WordPress 4.6 translations for a
 * wordpress.org-hosted plugin are loaded just-in-time from translate.wordpress.org, and
 * the textdomain matches the plugin slug. (Plugin Check flags the manual call as discouraged.)
 */

/*
 * Activation: seed a safe, additive set of modules on the very first activation so a fresh
 * install is immediately useful instead of blank. Idempotent + respects prior user choices.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		\Moksafocou\Support\Activation::on_activate();
	}
);

/*
 * Deactivation: drop the URL-coupon /<endpoint>/ rewrite rule (flush_rewrite_rules
 * is impossible at uninstall, so it must happen here) and clear its version stamp.
 */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		\Moksafocou\Modules\UrlCoupons\Lifecycle::on_deactivate();
		\Moksafocou\Support\Cron::clear();
	}
);
