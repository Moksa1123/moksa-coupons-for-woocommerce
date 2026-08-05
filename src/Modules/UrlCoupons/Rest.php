<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\UrlCoupons;

use Moksafocou\Coupon\CouponService;

defined( 'ABSPATH' ) || exit;

/**
 * REST surface for coupon sharing — both gated by manage_woocommerce (an anonymous
 * code→{share_url,qr} endpoint would be a bulk enumeration oracle):
 *   GET /coupons/<ref>/share   → JSON { enabled, share_url, qr_url, via, reason, [qr_svg] }
 *   GET /coupons/<ref>/qr      → raw image/svg+xml (the qr_url pointer / admin <img>)
 */
final class Rest {

	private const NS = 'moksafocou/v1';

	public static function register(): void {
		register_rest_route(
			self::NS,
			'/coupons/(?P<ref>[^/]+)/share',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'share' ),
				'permission_callback' => array( self::class, 'can' ),
				'args'                => array(
					'inline' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/coupons/(?P<ref>[^/]+)/qr',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'qr' ),
				'permission_callback' => array( self::class, 'can' ),
			)
		);
	}

	public static function can(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function share( \WP_REST_Request $request ): \WP_REST_Response {
		$coupon = self::resolve( (string) $request['ref'] );
		if ( ! $coupon instanceof \WC_Coupon ) {
			return new \WP_REST_Response( array( 'message' => __( 'Coupon not found.', 'moksa-coupons-for-woocommerce' ) ), 404 );
		}

		$info = ShareService::info( $coupon );
		$out  = array(
			'enabled'   => $info['enabled'],
			'share_url' => $info['share_url'],
			'qr_url'    => $info['enabled'] ? ShareService::qr_url( $coupon->get_id() ) : '',
			'via'       => $info['via'],
			'reason'    => $info['reason'],
		);

		// Inline SVG only for the in-dashboard preview (kept out of the AI/MCP path
		// where a multi-KB string would bloat model context every call).
		if ( $info['enabled'] && $request->get_param( 'inline' ) ) {
			$svg = ShareService::qr_svg( $info['share_url'] );
			if ( ! ( $svg instanceof \WP_Error ) ) {
				$out['qr_svg'] = $svg;
			}
		}

		return new \WP_REST_Response( $out, 200 );
	}

	/**
	 * Raw SVG image response. Echo+exit is the standard way to return a non-JSON
	 * body from a REST route; the markup is integers + hard-coded colours only.
	 */
	public static function qr( \WP_REST_Request $request ): ?\WP_REST_Response {
		$coupon = self::resolve( (string) $request['ref'] );
		if ( ! $coupon instanceof \WC_Coupon ) {
			return new \WP_REST_Response( array( 'message' => __( 'Coupon not found.', 'moksa-coupons-for-woocommerce' ) ), 404 );
		}
		$info = ShareService::info( $coupon );
		if ( ! $info['enabled'] ) {
			return new \WP_REST_Response( array( 'message' => $info['reason'] ), 409 );
		}
		$svg = ShareService::qr_svg( $info['share_url'] );
		if ( $svg instanceof \WP_Error ) {
			return new \WP_REST_Response( array( 'message' => $svg->get_error_message() ), 400 );
		}

		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Cache-Control: private, max-age=3600' );
		if ( $request->get_param( 'download' ) ) {
			header( 'Content-Disposition: attachment; filename="coupon-' . $coupon->get_id() . '-qr.svg"' );
		}
		// Safe by construction: the SVG is assembled from plugin-computed integers + two hard-coded
		// colour literals (no user data in markup) and is streamed as a standalone image/svg+xml
		// response (Content-Type set above). wp_kses() cannot be used here because it lower-cases the
		// case-sensitive viewBox attribute to "viewbox", which a standalone SVG parser silently
		// ignores, breaking the QR coordinate system.
		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see comment above.
		exit;
	}

	private static function resolve( string $ref ): ?\WC_Coupon {
		$id = CouponService::resolve_id( $ref );
		if ( ! $id ) {
			return null;
		}
		$coupon = new \WC_Coupon( $id );
		return $coupon->get_id() ? $coupon : null;
	}
}
