<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\UrlCoupons;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\Qr\QrSvg;

defined( 'ABSPATH' ) || exit;

/**
 * Computes a coupon's shareable auto-apply URL and its (cached) QR SVG. The
 * share URL is only ever derived from a path that actually works:
 *   1. the pretty endpoint /<endpoint>/<slug>  — when the URL module is on AND the
 *      coupon has URL_ENABLED='yes';
 *   2. else ?coupon=CODE on the site root      — when the global query-string
 *      option is on;
 *   3. else none — callers report enabled=false so the AI can say "turn on URL
 *      coupons first" instead of emitting a dead 404 link.
 */
final class ShareService {

	public const ENDPOINT_OPTION  = 'moksafocou_url_endpoint';
	public const ENDPOINT_DEFAULT = 'coupon';

	public static function endpoint(): string {
		$value = sanitize_title( (string) get_option( self::ENDPOINT_OPTION, self::ENDPOINT_DEFAULT ) );
		return '' !== $value ? $value : self::ENDPOINT_DEFAULT;
	}

	public static function module_enabled(): bool {
		return 'yes' === get_option( 'moksafocou_url_enabled', 'no' );
	}

	public static function query_enabled(): bool {
		return 'yes' === get_option( 'moksafocou_url_query_enabled', 'no' );
	}

	/**
	 * Resolve the shareable info for a coupon.
	 *
	 * @return array{enabled:bool,share_url:string,via:string,reason:string}
	 */
	public static function info( \WC_Coupon $coupon ): array {
		$code = $coupon->get_code();

		// Pretty endpoint — requires the module on + this coupon opted in.
		if ( self::module_enabled() && 'yes' === $coupon->get_meta( Keys::URL_ENABLED, true ) ) {
			$slug = (string) $coupon->get_meta( Keys::URL_SLUG, true );
			$slug = '' !== trim( $slug ) ? sanitize_title( $slug ) : sanitize_title( $code );
			$url  = trailingslashit( home_url( '/' . self::endpoint() ) ) . rawurlencode( $slug );
			return array(
				'enabled'   => true,
				'share_url' => esc_url_raw( $url ),
				'via'       => 'pretty',
				'reason'    => '',
			);
		}

		// Query-string fallback — global feature, works for any existing coupon.
		if ( self::query_enabled() ) {
			$url = add_query_arg( 'coupon', rawurlencode( $code ), home_url( '/' ) );
			return array(
				'enabled'   => true,
				'share_url' => esc_url_raw( $url ),
				'via'       => 'query',
				'reason'    => '',
			);
		}

		return array(
			'enabled'   => false,
			'share_url' => '',
			'via'       => 'none',
			'reason'    => __( 'This coupon has not enabled URL apply yet. Check the "Enable" box on the coupon\'s "Coupon URL" tab, or turn on "Apply via URL query string" in the settings.', 'moksa-coupons-for-woocommerce' ),
		);
	}

	/**
	 * Rendered QR SVG for an arbitrary URL, cached by content hash (the QR matrix
	 * is CPU-heavy and the key auto-busts when the URL changes).
	 *
	 * @return string|\WP_Error
	 */
	public static function qr_svg( string $url, int $px_scale = 8 ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return new \WP_Error( 'moksafocou_qr_empty', __( 'QR content cannot be empty.', 'moksa-coupons-for-woocommerce' ) );
		}
		$key    = 'moksafocou_qr_' . md5( $url . '|' . $px_scale );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$svg = QrSvg::render( $url, $px_scale );
		if ( $svg instanceof \WP_Error ) {
			return $svg;
		}
		set_transient( $key, $svg, DAY_IN_SECONDS );
		return $svg;
	}

	/** The gated REST URL that returns this coupon's QR SVG (the AI/MCP qr_url pointer). */
	public static function qr_url( int $coupon_id ): string {
		return rest_url( 'moksafocou/v1/coupons/' . $coupon_id . '/qr' );
	}
}
