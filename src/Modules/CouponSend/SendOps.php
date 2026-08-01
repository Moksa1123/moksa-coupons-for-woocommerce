<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponSend;

use Moksafocou\Coupon\CouponService;
use Moksafocou\Support\GuardedOps;

defined( 'ABSPATH' ) || exit;

/**
 * Propose/apply pair for the send-coupon ability. execute_callback points at
 * send_prepare (proposal only — never sends); send_apply does the real send and runs
 * solely via the in-dashboard confirm flow / admin action after a human confirmation.
 * Both ends re-check the capability.
 */
final class SendOps {

	use GuardedOps;

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function send_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input = is_array( $input ) ? $input : array();
		$id    = CouponService::resolve_id( $input['code_or_id'] ?? '' );
		if ( ! $id ) {
			return new \WP_Error( 'moksafocou_not_found', __( 'Coupon not found.', 'moksafocou' ) );
		}
		$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'moksafocou_bad_email', __( 'Recipient email is invalid.', 'moksafocou' ) );
		}
		$note     = isset( $input['note'] ) ? sanitize_text_field( (string) $input['note'] ) : '';
		$restrict = ! empty( $input['restrict_to_email'] );
		$data     = CouponService::get( $id );
		$code     = (string) ( $data['code'] ?? $id );

		return array(
			'id'       => $id,
			'email'    => $email,
			'note'     => $note,
			'restrict' => $restrict,
			'summary'  => sprintf(
				/* translators: 1: coupon code, 2: recipient email, 3: optional restriction note. */
				__( 'Send coupon %1$s to %2$s%3$s', 'moksafocou' ),
				$code,
				$email,
				$restrict ? __( ' (and lock it to this Email only)', 'moksafocou' ) : ''
			),
		);
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function send_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$id     = (int) ( $params['id'] ?? 0 );
		$email  = (string) ( $params['email'] ?? '' );
		$note   = (string) ( $params['note'] ?? '' );
		$result = SendService::send( $id, $email, $note, ! empty( $params['restrict'] ) );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		return array(
			'id'    => $id,
			/* translators: %s: recipient email. */
			'reply' => sprintf( __( 'Sent the coupon to %s.', 'moksafocou' ), $email ),
		);
	}
}
