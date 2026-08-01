<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * The [moksafocou_coupons] shortcode: a card wall of the merchant's advertised
 * coupons (code + type badge + discount + expiry + copy button + apply link).
 */
final class Shortcode {

	private const HANDLE = 'moksafocou-coupon-cards';

	public static function register(): void {
		add_shortcode( 'moksafocou_coupons', array( self::class, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
	}

	public static function register_assets(): void {
		$css_rel = 'src/Modules/Frontend/assets/css/coupon-cards.css';
		$js_rel  = 'src/Modules/Frontend/assets/js/coupon-cards.js';
		wp_register_style(
			self::HANDLE,
			MOKSAFOCOU_PLUGIN_URL . $css_rel,
			array(),
			self::ver( $css_rel )
		);
		wp_register_script(
			self::HANDLE,
			MOKSAFOCOU_PLUGIN_URL . $js_rel,
			array(),
			self::ver( $js_rel ),
			true
		);
	}

	private static function ver( string $rel ): string {
		$path = MOKSAFOCOU_PLUGIN_DIR . $rel;
		return file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOCOU_VERSION;
	}

	/**
	 * @param mixed $atts
	 * @return string
	 */
	public static function render( $atts ): string {
		$atts  = shortcode_atts( array( 'limit' => 20 ), is_array( $atts ) ? $atts : array(), 'moksafocou_coupons' );
		$limit = (int) $atts['limit'];

		// Assets are cheap and must register regardless of the cache outcome.
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		// The card wall is identical for every visitor → serve from cache when warm,
		// skipping the meta_query + N WC_Coupon loads + per-card work entirely. Bypass the
		// cache when a multi-currency switcher filters the currency per request (the cards
		// embed wc_price() markup, which would otherwise be frozen to one currency).
		$cacheable = ! has_filter( 'woocommerce_currency' );

		if ( $cacheable ) {
			$cached = CardsCache::get( $limit );
			if ( null !== $cached ) {
				return $cached;
			}
		}

		$items = Catalog::query( $limit );

		if ( array() === $items ) {
			$empty = '<div class="moksafocou-coupons moksafocou-coupons--empty">' . esc_html__( 'There are no available coupons right now.', 'moksafocou' ) . '</div>';
			if ( $cacheable ) {
				CardsCache::set( $limit, $empty, CardsCache::ttl_for( array(), time() ) );
			}
			return $empty;
		}

		$now          = time();
		$valid_untils = array();
		$cards        = '';
		foreach ( $items as $coupon ) {
			$expires        = $coupon->get_date_expires();
			$valid_untils[] = $expires ? $expires->getTimestamp() + DAY_IN_SECONDS : null;
			$cards         .= CouponCard::render( $coupon );
		}

		$html = '<div class="moksafocou-coupons">' . $cards . '</div>';
		if ( $cacheable ) {
			CardsCache::set( $limit, $html, CardsCache::ttl_for( $valid_untils, $now ) );
		}
		return $html;
	}
}
