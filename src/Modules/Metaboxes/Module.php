<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Metaboxes;

use Moksafocou\Modules\AbstractModule;
use Moksafocou\Coupon\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Consolidated-metabox module — lazy-loaded, boots only when
 * moksafocou_metaboxes_enabled is 'yes'. It does NOT register the coupon fields
 * itself: every feature module renders through Admin\CouponSections, which reads the
 * same option and either keeps each section as a WooCommerce coupon-data tab or
 * gathers them ALL into one 'Moksa coupon settings' metabox laid out as a WooCommerce-style
 * vertical tabbed panel. This module owns the settings toggle plus the CSS + JS that
 * give that single box its left-tab / switchable-panel behaviour.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'metaboxes';
	}

	public function label(): string {
		return __( 'Centralized settings metabox', 'moksafocou' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Consolidate the coupon setting sections into a single metabox, presented with WooCommerce-style left-hand tabs', 'moksafocou' );
	}

	public function boot(): void {
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		}
	}

	/** Inline the tabbed-metabox CSS + switcher JS only on the coupon edit screen. */
	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'shop_coupon' !== $screen->id ) {
			return;
		}
		wp_register_style( 'moksafocou-metaboxes', false, array(), \MOKSAFOCOU_VERSION );
		wp_enqueue_style( 'moksafocou-metaboxes' );
		wp_add_inline_style( 'moksafocou-metaboxes', self::css() );

		$rel  = 'src/Modules/Metaboxes/assets/js/metabox-tabs.js';
		$path = \MOKSAFOCOU_PLUGIN_DIR . $rel;
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : \MOKSAFOCOU_VERSION;
		wp_enqueue_script(
			'moksafocou-metabox-tabs',
			\MOKSAFOCOU_PLUGIN_URL . $rel,
			array( 'jquery' ),
			$ver,
			true
		);

		// Hide each feature section's dependent fields while its enable checkbox is off.
		$cond  = 'src/Modules/Metaboxes/assets/js/conditional-fields.js';
		$cpath = \MOKSAFOCOU_PLUGIN_DIR . $cond;
		$cver  = file_exists( $cpath ) ? (string) filemtime( $cpath ) : \MOKSAFOCOU_VERSION;
		wp_enqueue_script( 'moksafocou-conditional-fields', \MOKSAFOCOU_PLUGIN_URL . $cond, array( 'jquery' ), $cver, true );
		wp_localize_script(
			'moksafocou-conditional-fields',
			'moksafocouConditional',
			array(
				'toggles' => array(
					Keys::SCHEDULE_ENABLED,
					Keys::ROLE_ENABLED,
					Keys::CUST_ENABLED,
					Keys::DAYTIME_ENABLED,
					Keys::TIERS_ENABLED,
					Keys::RULES_ENABLED,
					Keys::URL_ENABLED,
					Keys::SHIPREGION_ENABLED,
					Keys::PAYMENT_ENABLED,
					Keys::GIFT_ENABLED,
				),
			)
		);
	}

	/**
	 * Vertical tabbed-panel layout for the single consolidated metabox, mirroring the
	 * native WooCommerce coupon-data box (left tab column + right panel) but scoped to
	 * our own classes. The fields keep WooCommerce's .woocommerce_options_panel styling.
	 */
	public static function css(): string {
		return '.post-type-shop_coupon #moksafocou_coupon_settings > .inside{margin:0;padding:0;}'
			. '.moksafocou-panel-wrap{position:relative;overflow:hidden;}'
			. '.moksafocou-settings-tabs{float:left;width:20%;margin:0;padding:0 0 10px;box-sizing:border-box;'
				. 'background:#f9f9f9;border-right:1px solid #eee;line-height:1.4em;}'
			. '.moksafocou-settings-tabs li{margin:0;padding:0;display:block;position:relative;}'
			. '.moksafocou-settings-tabs li a{display:block;padding:10px;text-decoration:none;box-shadow:none;'
				. 'border-bottom:1px solid #eee;}'
			. '.moksafocou-settings-tabs li.active a{background:#fff;color:#555;box-shadow:inset 3px 0 0 #2271b1;}'
			. '.moksafocou-settings-panels{float:left;width:80%;box-sizing:border-box;min-height:260px;}'
			. '.moksafocou-panel{display:none;padding:9px 12px 12px;}'
			. '.moksafocou-panel select,.moksafocou-panel input,.moksafocou-panel .select2-container{float:none;}'
			. '.moksafocou-panel .options_group{border-top:1px solid #f0f0f1;}'
			. '.moksafocou-panel .options_group:first-child{border-top:0;}'
			. '@media screen and (max-width:782px){'
				. '.moksafocou-settings-tabs,.moksafocou-settings-panels{float:none;width:100%;}'
				. '.moksafocou-settings-tabs{border-right:0;border-bottom:1px solid #eee;}}';
	}
}
