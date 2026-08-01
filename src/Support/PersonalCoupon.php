<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

use Moksafocou\Coupon\CouponService;

defined( 'ABSPATH' ) || exit;

/**
 * Issues a personalised coupon to one customer by cloning a template coupon (inheriting all of its
 * mechanics) and locking it to that customer: unique code, email restriction, relative expiry, and
 * the owner stamp that makes it show up in their 'My coupons' page. Shared by every "give this
 * customer a coupon" flow — remarketing, referral rewards, birthday coupons.
 */
final class PersonalCoupon {

	/** Post meta linking a coupon to the customer it was issued to (read by the MyAccount module). */
	public const OWNER_META = '_moksafocou_owner_user';

	/**
	 * @param int    $source_id   Template coupon to clone.
	 * @param string $code_prefix Prefix for the generated unique code (e.g. "NEWCUST-").
	 * @param int    $user_id     Customer to own it (0 = none / guest).
	 * @param string $email       Customer email to lock it to ('' = don't restrict).
	 * @param int    $expiry_days Days valid from now (0 = keep the template's own expiry).
	 * @return int|\WP_Error New coupon id.
	 */
	public static function issue( int $source_id, string $code_prefix, int $user_id, string $email, int $expiry_days ) {
		$code = CouponService::unique_code( $code_prefix );
		if ( '' === $code ) {
			return new \WP_Error( 'moksafocou_no_code', __( 'Could not generate a unique coupon code.', 'moksa-coupons-for-woocommerce' ) );
		}
		$new_id = CouponService::duplicate( $source_id, $code, true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		$new_id = (int) $new_id;

		$coupon = new \WC_Coupon( $new_id );
		if ( '' !== $email && is_email( $email ) ) {
			$coupon->set_email_restrictions( self::merge_email( (array) $coupon->get_email_restrictions(), $email ) );
		}
		if ( $expiry_days > 0 ) {
			$coupon->set_date_expires( time() + $expiry_days * DAY_IN_SECONDS );
		}
		$coupon->save();

		if ( $user_id > 0 ) {
			update_post_meta( $new_id, self::OWNER_META, $user_id );
		}
		return $new_id;
	}

	/**
	 * Add a recipient to an email-restriction list (lowercased, trimmed, de-duplicated).
	 *
	 * @param array<int,string> $existing
	 * @return array<int,string>
	 */
	public static function merge_email( array $existing, string $email ): array {
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
}
