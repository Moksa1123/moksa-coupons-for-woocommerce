<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\MixMatch;

use Moksafocou\Modules\AbstractModule;
use Moksafocou\Admin\CouponSections;

defined( 'ABSPATH' ) || exit;

/**
 * 'Mix & Match' module. Lazy-loaded — boots only when moksafocou_mixmatch_enabled is
 * 'yes'. Registers the 'moksafocou_mixmatch' discount type, the cart runtime (classic +
 * Block/Store-API), the admin panel, and the create-mixmatch-coupon ability for the AI / MCP.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'mixmatch';
	}

	public function label(): string {
		return __( 'Mix & Match', 'moksafocou' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Pick N for $X / N items at Y% off: pick and combine specific products for a special price, repeatable', 'moksafocou' );
	}

	public function boot(): void {
		Type::register();
		Frontend::boot();

		if ( function_exists( 'wp_register_ability' ) ) {
			add_action( 'wp_abilities_api_init', array( Ability::class, 'register' ) );
			add_filter( 'moksafocou_ai_assistant_abilities', array( self::class, 'ai_abilities' ) );
			add_filter( 'moksafocou_ai_destructive_handlers', array( self::class, 'ai_handlers' ) );
		}

		if ( is_admin() ) {
			$fields = new Fields();
			CouponSections::register( 24, array( $fields, 'render_nonce' ), array( $fields, 'sections' ) );
			add_action( 'woocommerce_coupon_options_save', array( $fields, 'save' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin' ) );
		}
	}

	/**
	 * @param array<int,string> $abilities
	 * @return array<int,string>
	 */
	public static function ai_abilities( array $abilities ): array {
		$abilities[] = 'moksafocou/create-mixmatch-coupon';
		return $abilities;
	}

	/**
	 * @param array<string,array{prepare:callable,apply:callable}> $handlers
	 * @return array<string,array{prepare:callable,apply:callable}>
	 */
	public static function ai_handlers( array $handlers ): array {
		$handlers['moksafocou/create-mixmatch-coupon'] = array(
			'prepare' => array( MixMatchOps::class, 'create_prepare' ),
			'apply'   => array( MixMatchOps::class, 'create_apply' ),
		);
		return $handlers;
	}

	/** Screen-gated admin toggle JS (coupon editor only). */
	public static function enqueue_admin( string $hook = '' ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'shop_coupon' !== $screen->post_type ) {
			return;
		}
		$rel  = 'src/Modules/MixMatch/assets/js/mixmatch-admin.js';
		$path = MOKSAFOCOU_PLUGIN_DIR . $rel;
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOCOU_VERSION;
		wp_enqueue_script(
			'moksafocou-mixmatch-admin',
			MOKSAFOCOU_PLUGIN_URL . $rel,
			array( 'jquery' ),
			$ver,
			true
		);
	}
}
