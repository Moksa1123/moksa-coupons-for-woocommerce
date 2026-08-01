<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Gutenberg dynamic block `moksafocou/coupon-cards` — a block wrapper around the
 * [moksafocou_coupons] shortcode so editors can drop the coupon wall in visually and tune
 * the count in the inspector. Server-rendered (render_callback reuses Shortcode::render), so
 * the markup + cache logic stay in one place. The editor script is plain `wp.*` — no build step.
 */
final class Block {

	public static function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$dir = \MOKSAFOCOU_PLUGIN_DIR . 'src/Modules/Frontend/blocks/coupon-cards';
		if ( ! file_exists( $dir . '/block.json' ) ) {
			return;
		}
		$rel  = 'src/Modules/Frontend/blocks/coupon-cards/index.js';
		$path = \MOKSAFOCOU_PLUGIN_DIR . $rel;
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : \MOKSAFOCOU_VERSION;
		wp_register_script(
			'moksafocou-coupon-cards-editor',
			\MOKSAFOCOU_PLUGIN_URL . $rel,
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render', 'wp-components', 'wp-i18n' ),
			$ver,
			true
		);
		// Wire just-in-time translations for the editor script's wp.i18n.__() strings.
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'moksafocou-coupon-cards-editor', 'moksafocou' );
		}
		register_block_type( $dir, array( 'render_callback' => array( self::class, 'render' ) ) );
	}

	/**
	 * @param mixed $attributes
	 * @return string
	 */
	public static function render( $attributes ): string {
		$limit = ( is_array( $attributes ) && isset( $attributes['limit'] ) ) ? (int) $attributes['limit'] : 20;
		return Shortcode::render( array( 'limit' => $limit ) );
	}
}
