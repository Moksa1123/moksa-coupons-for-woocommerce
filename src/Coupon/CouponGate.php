<?php

declare( strict_types=1 );

namespace Moksafocou\Coupon;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes ONE filter — `moksafocou_coupon_allowed_for_user` — on the shared
 * woocommerce_coupon_is_valid path, so a membership / tier plugin can gate a coupon for
 * the current user (e.g. a VIP-only coupon) without moksafocou knowing anything about
 * membership. Default allows; a listener returns false to block, and the customer-facing
 * reason is filterable too. The block message is restored for the Block / Store API
 * checkout via woocommerce_coupon_error (mirrors the conditions Validator).
 *
 * Always-on (a gate must apply regardless of which modules are enabled).
 * See platform-plan/README.md §3.5-E.
 */
final class CouponGate {

	/** @var array<int,string> Custom block reason per coupon id, for the Block error filter. */
	private static array $last_error = array();

	public static function register(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'gate' ), 9, 2 );
		add_filter( 'woocommerce_coupon_error', array( self::class, 'block_error' ), 9, 3 );
	}

	/**
	 * @param mixed $valid  WC's prior validity result.
	 * @param mixed $coupon WC_Coupon.
	 * @return mixed
	 * @throws \Exception When a listener blocks the coupon (message shown to the customer).
	 */
	public static function gate( $valid, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon ) {
			return $valid;
		}
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		/**
		 * Gate a coupon for the current user (membership / tier plugins). Return false to block.
		 *
		 * @param bool       $allowed Whether the user may use this coupon (default true).
		 * @param \WC_Coupon $coupon  The coupon being validated.
		 * @param int        $user_id Current user id (0 = guest).
		 */
		$allowed = apply_filters( 'moksafocou_coupon_allowed_for_user', true, $coupon, $user_id );
		if ( false === $allowed ) {
			/**
			 * Customer-facing reason shown when the coupon is gated off.
			 *
			 * @param string     $message Default reason.
			 * @param \WC_Coupon $coupon  The coupon being validated.
			 * @param int        $user_id Current user id.
			 */
			$message                               = (string) apply_filters(
				'moksafocou_coupon_not_allowed_message',
				__( 'This coupon does not apply to your account.', 'moksa-coupons-for-woocommerce' ),
				$coupon,
				$user_id
			);
			self::$last_error[ $coupon->get_id() ] = $message;
			throw new \Exception( wp_kses_post( $message ) );
		}
		return $valid;
	}

	/**
	 * Store API shows WC_Coupon::get_error_message() (by code), not the thrown Exception
	 * text — restore our custom message here.
	 *
	 * @param mixed $message Default WC message.
	 * @param mixed $code    Error code.
	 * @param mixed $coupon  WC_Coupon.
	 * @return mixed
	 */
	public static function block_error( $message, $code, $coupon ) {
		if ( $coupon instanceof \WC_Coupon && isset( self::$last_error[ $coupon->get_id() ] ) ) {
			return self::$last_error[ $coupon->get_id() ];
		}
		return $message;
	}
}
