<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

use Moksafocou\Coupon\CouponService;
use Moksafocou\Modules\UrlCoupons\ShareService;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only presentation helpers for a coupon shared by every surface that shows one (front-end
 * cards, the send-coupon email, the expiry-reminder email): its one-click apply URL (when the URL
 * module is on) and its human-readable discount summary.
 */
final class CouponPresenter {

	/** The shareable auto-apply URL, when the URL-coupons module is active; else ''. */
	public static function apply_url( \WC_Coupon $coupon ): string {
		if ( ! class_exists( ShareService::class ) ) {
			return '';
		}
		$info = ShareService::info( $coupon );
		return ! empty( $info['enabled'] ) ? (string) $info['share_url'] : '';
	}

	/** A short human-readable discount summary, or '' if the coupon can't be read. */
	public static function summary( \WC_Coupon $coupon ): string {
		$data = CouponService::get( $coupon->get_id() );
		return null !== $data ? CouponService::build_summary( $data, '' ) : '';
	}
}
