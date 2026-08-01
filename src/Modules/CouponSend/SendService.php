<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponSend;

use Moksafocou\Support\CouponPresenter;

defined( 'ABSPATH' ) || exit;

/**
 * Emails a coupon to a recipient. The email-restriction merge (merge_email_restriction)
 * is pure and unit-tested; build_email / send touch WordPress (i18n, esc, wp_mail).
 */
final class SendService {

	/**
	 * Add a recipient to an existing email-restriction list (lowercased, trimmed,
	 * de-duplicated). Pure — no WordPress dependency.
	 *
	 * @param array<int,string> $existing Current restriction list.
	 * @param string            $email    Recipient to add.
	 * @return array<int,string>
	 */
	public static function merge_email_restriction( array $existing, string $email ): array {
		$out = array();
		foreach ( $existing as $value ) {
			$norm = strtolower( trim( (string) $value ) );
			if ( '' !== $norm && ! in_array( $norm, $out, true ) ) {
				$out[] = $norm;
			}
		}
		$email = strtolower( trim( $email ) );
		if ( '' !== $email && ! in_array( $email, $out, true ) ) {
			$out[] = $email;
		}
		return $out;
	}

	/**
	 * Build the coupon email subject + HTML body.
	 *
	 * @return array{subject:string,body:string}
	 */
	public static function build_email( string $code, string $summary, string $apply_url, string $note, string $site_name ): array {
		/* translators: %s: coupon code. */
		$subject = sprintf( __( 'Your coupon: %s', 'moksafocou' ), $code );

		$parts = array();
		/* translators: %s: site name. */
		$parts[] = '<p>' . esc_html( sprintf( __( 'Hello, %s is sending you a coupon.', 'moksafocou' ), $site_name ) ) . '</p>';
		if ( '' !== trim( $note ) ) {
			$parts[] = '<p>' . esc_html( $note ) . '</p>';
		}
		/* translators: %s: coupon code. */
		$parts[] = '<p><strong>' . esc_html( sprintf( __( 'Coupon code: %s', 'moksafocou' ), $code ) ) . '</strong></p>';
		if ( '' !== trim( $summary ) ) {
			$parts[] = '<p>' . esc_html( $summary ) . '</p>';
		}
		if ( '' !== trim( $apply_url ) ) {
			$parts[] = '<p><a href="' . esc_url( $apply_url ) . '">' . esc_html__( 'Click here to apply the coupon automatically', 'moksafocou' ) . '</a></p>';
		}
		return array(
			'subject' => $subject,
			'body'    => implode( "\n", $parts ),
		);
	}

	/**
	 * Send the coupon email. Optionally lock the coupon to the recipient by adding the
	 * address to its WC email_restrictions.
	 *
	 * @return true|\WP_Error
	 */
	public static function send( int $coupon_id, string $email, string $note = '', bool $restrict = false ) {
		$email = sanitize_email( $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'moksafocou_bad_email', __( 'Recipient email is invalid.', 'moksafocou' ) );
		}
		$coupon = new \WC_Coupon( $coupon_id );
		if ( ! $coupon->get_id() ) {
			return new \WP_Error( 'moksafocou_not_found', __( 'Coupon not found.', 'moksafocou' ) );
		}

		if ( $restrict ) {
			$coupon->set_email_restrictions( self::merge_email_restriction( (array) $coupon->get_email_restrictions(), $email ) );
			$coupon->save();
		}

		$summary   = self::discount_summary( $coupon );
		$apply_url = self::apply_url( $coupon );
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$mail      = self::build_email( $coupon->get_code(), $summary, $apply_url, $note, $site_name );

		$sent = wp_mail(
			$email,
			$mail['subject'],
			$mail['body'],
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
		if ( ! $sent ) {
			return new \WP_Error( 'moksafocou_send_failed', __( 'Send failed (please check the site email settings).', 'moksafocou' ) );
		}
		return true;
	}

	private static function discount_summary( \WC_Coupon $coupon ): string {
		return CouponPresenter::summary( $coupon );
	}

	/** The shareable auto-apply URL, when the URL-coupons module is active; else ''. */
	private static function apply_url( \WC_Coupon $coupon ): string {
		return CouponPresenter::apply_url( $coupon );
	}
}
