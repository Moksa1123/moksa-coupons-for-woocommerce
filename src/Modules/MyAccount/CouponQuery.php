<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\MyAccount;

use Moksafocou\Support\CouponIndex;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the coupons that belong to a given customer and decides which are worth showing. A
 * coupon "belongs" to a customer when it was issued to them (the _moksafocou_owner_user meta)
 * or when it is locked to their email (WooCommerce's customer_email / email_restrictions). The
 * display decision (should_display) is pure so it can be unit-tested without WordPress.
 */
final class CouponQuery {

	/** Post meta stamped on a coupon when it is issued to a specific user (exact-match owner). */
	public const OWNER_META = \Moksafocou\Support\PersonalCoupon::OWNER_META;

	/**
	 * Whether a coupon should appear in the customer's list: published, not past its expiry, and
	 * not already used up.
	 */
	public static function should_display( string $status, ?int $expires_ts, int $usage_count, int $usage_limit, int $now ): bool {
		if ( 'publish' !== $status ) {
			return false;
		}
		if ( null !== $expires_ts && $expires_ts < $now ) {
			return false;
		}
		if ( $usage_limit > 0 && $usage_count >= $usage_limit ) {
			return false;
		}
		return true;
	}

	/**
	 * Coupon post IDs owned by / restricted to this user, newest first.
	 *
	 * @return array<int,int>
	 */
	public static function owned_ids( int $user_id, string $email ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		// Owned by this user, or locked to their email address (the coupon index stores the email
		// as a hash). Then the newest 100 published ones.
		$names = array( 'owner:' . $user_id );
		$email = CouponIndex::email_term( $email );
		if ( '' !== $email ) {
			$names[] = $email;
		}
		$candidates = CouponIndex::ids_any( $names );
		if ( array() === $candidates ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'        => 'shop_coupon',
				'post_status'      => 'publish',
				'post__in'         => $candidates,
				'posts_per_page'   => 100,
				'fields'           => 'ids',
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
		return array_map( 'intval', (array) $ids );
	}
}
