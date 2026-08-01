<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Remarketing;

use Moksafocou\Coupon\CouponService;
use Moksafocou\Modules\CouponSend\SendService;
use Moksafocou\Support\OrderOnce;
use Moksafocou\Support\PersonalCoupon;

defined( 'ABSPATH' ) || exit;

/**
 * Issues a remarketing coupon once an order is completed: clones the configured template coupon
 * into a unique, customer-locked coupon (inheriting all of the template's mechanics — percentage /
 * fixed / tiered / conditions / free shipping…), stamps it as the customer's so it shows in their
 * 'My coupons' page, optionally emails it, and stamps the order so it never double-issues.
 */
final class Runtime {

	private const ORDER_META = '_moksafocou_remarketing_issued';

	public static function boot(): void {
		add_action( 'woocommerce_order_status_completed', array( self::class, 'maybe_issue' ) );
	}

	/** @return array{source:string,condition:string,min_total:float,expiry_days:int,email:bool} */
	private static function config(): array {
		return array(
			'source'      => trim( (string) get_option( 'moksafocou_remarketing_source', '' ) ),
			'condition'   => Rules::normalize_condition( (string) get_option( 'moksafocou_remarketing_condition', 'all' ) ),
			'min_total'   => (float) get_option( 'moksafocou_remarketing_min_total', 0 ),
			'expiry_days' => max( 0, (int) get_option( 'moksafocou_remarketing_expiry_days', 30 ) ),
			'email'       => 'yes' === get_option( 'moksafocou_remarketing_email', 'no' ),
		);
	}

	/**
	 * @param mixed $order_id
	 */
	public static function maybe_issue( $order_id ): void {
		$order = OrderOnce::get( $order_id );
		if ( null === $order || OrderOnce::done( $order, self::ORDER_META ) ) {
			return; // not an order, or already issued.
		}

		$cfg = self::config();
		if ( '' === $cfg['source'] ) {
			return; // no template configured.
		}
		$source_id = CouponService::resolve_id( $cfg['source'] );
		if ( ! $source_id ) {
			return; // template coupon not found.
		}

		$user_id = (int) $order->get_customer_id();
		$email   = (string) $order->get_billing_email();
		// The order count is only needed for the first_order condition. Compute it from a direct
		// query (NOT the cached wc_get_customer_order_count, which can be stale at completion time)
		// so the decision is correct. Guests can't be verified → never match first_order.
		$count = 0;
		if ( 'first_order' === $cfg['condition'] ) {
			$count = $user_id > 0 ? self::paid_order_count( $user_id ) : PHP_INT_MAX;
		}

		if ( ! Rules::qualifies( $cfg['condition'], (float) $order->get_total(), $cfg['min_total'], $count ) ) {
			return;
		}

		$new_id = PersonalCoupon::issue( $source_id, self::prefix( $cfg['source'] ), $user_id, $email, $cfg['expiry_days'] );
		if ( is_wp_error( $new_id ) ) {
			return;
		}
		$new_id = (int) $new_id;
		$code   = ( new \WC_Coupon( $new_id ) )->get_code();

		if ( $cfg['email'] && '' !== $email && is_email( $email ) && class_exists( SendService::class ) ) {
			SendService::send( $new_id, $email, __( 'Thank you for your order! Here is a coupon you can use on your next purchase.', 'moksa-coupons-for-woocommerce' ), false );
		}

		OrderOnce::mark( $order, self::ORDER_META, $code );

		/**
		 * Fires after a remarketing coupon has been issued for a completed order.
		 *
		 * @param int       $new_id  New coupon post id.
		 * @param string    $code    New coupon code.
		 * @param \WC_Order $order   The order that triggered it.
		 * @param int       $user_id Customer user id (0 for guests).
		 */
		do_action( 'moksafocou_remarketing_issued', $new_id, $code, $order, $user_id );
	}

	/**
	 * The customer's paid orders, capped at 2 — enough to answer "is this their first?". Counts
	 * the just-completed order (already saved), so a genuine first purchase returns 1.
	 */
	private static function paid_order_count( int $user_id ): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return PHP_INT_MAX;
		}
		$ids = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'completed', 'processing' ),
				'limit'       => 2,
				'return'      => 'ids',
			)
		);
		return count( (array) $ids );
	}

	/** A readable code prefix derived from the template code (alphanumerics, capped), e.g. NEWCUST-. */
	private static function prefix( string $source ): string {
		$base = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $source ) );
		$base = '' !== $base ? substr( $base, 0, 10 ) : 'THANKS';
		return $base . '-';
	}
}
