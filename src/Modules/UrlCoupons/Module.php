<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\UrlCoupons;

use Moksafocou\Modules\AbstractModule;
use Moksafocou\Admin\CouponSections;

defined( 'ABSPATH' ) || exit;

/**
 * URL coupons module: a per-coupon auto-apply link (/<endpoint>/<code> and the
 * optional ?coupon=CODE) plus a server-rendered QR code and a share ability for
 * the AI assistant / MCP. Lazy-loaded — boots only when moksafocou_url_enabled
 * is 'yes'. The rewrite-flush lifecycle (Lifecycle) is registered separately and
 * always-on, so toggling this module on/off can (un)install the rewrite rule.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'url';
	}

	public function label(): string {
		return __( 'Coupon URL / QR (click or scan to apply)', 'moksa-coupons-for-woocommerce' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Dedicated link + server-side QR, one-tap apply for customers', 'moksa-coupons-for-woocommerce' );
	}

	public function boot(): void {
		UrlHandler::register();

		add_action( 'rest_api_init', array( Rest::class, 'register' ) );

		// Share ability (read) → command palette / REST / AI / MCP, behind the 6.9+ guard.
		if ( function_exists( 'wp_register_ability' ) ) {
			add_action( 'wp_abilities_api_init', array( ShareAbility::class, 'register' ) );
			add_filter( 'moksafocou_ai_assistant_abilities', array( self::class, 'ai_abilities' ) );
		}

		if ( is_admin() ) {
			$fields = new Fields();
			CouponSections::register( 21, array( $fields, 'render_nonce' ), array( $fields, 'sections' ) );
			add_action( 'woocommerce_coupon_options_save', array( $fields, 'save' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin' ) );
		}
	}

	/**
	 * @param array<int,string> $abilities
	 * @return array<int,string>
	 */
	public static function ai_abilities( array $abilities ): array {
		$abilities[] = 'moksafocou/get-coupon-share';
		return $abilities;
	}

	/** Screen-gated admin QR preview assets (coupon editor only). */
	public static function enqueue_admin( string $hook = '' ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'shop_coupon' !== $screen->post_type ) {
			return;
		}
		$rel  = 'src/Modules/UrlCoupons/assets/js/qr-admin.js';
		$path = MOKSAFOCOU_PLUGIN_DIR . $rel;
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOCOU_VERSION;
		wp_enqueue_script(
			'moksafocou-qr-admin',
			MOKSAFOCOU_PLUGIN_URL . $rel,
			array( 'wp-api-fetch' ),
			$ver,
			true
		);
		wp_localize_script(
			'moksafocou-qr-admin',
			'moksafocouQr',
			array(
				'copyLabel'     => __( 'Copy link', 'moksa-coupons-for-woocommerce' ),
				'copiedLabel'   => __( 'Copied', 'moksa-coupons-for-woocommerce' ),
				'downloadLabel' => __( 'Download QR (PNG)', 'moksa-coupons-for-woocommerce' ),
				'shareTitle'    => __( 'Share link', 'moksa-coupons-for-woocommerce' ),
				'qrTitle'       => __( 'QR Code', 'moksa-coupons-for-woocommerce' ),
				'saveFirst'     => __( 'Please save the coupon first.', 'moksa-coupons-for-woocommerce' ),
			)
		);
	}
}
