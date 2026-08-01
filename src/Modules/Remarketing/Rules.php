<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Remarketing;

defined( 'ABSPATH' ) || exit;

/**
 * Pure decision logic for the post-purchase remarketing trigger: given the configured condition
 * and the order's facts, decide whether a coupon should be issued. Kept free of WordPress so it
 * can be unit-tested.
 */
final class Rules {

	/** Supported trigger conditions. */
	public const CONDITIONS = array( 'all', 'first_order', 'min_total' );

	public static function normalize_condition( string $condition ): string {
		return in_array( $condition, self::CONDITIONS, true ) ? $condition : 'all';
	}

	/**
	 * @param string $condition             One of CONDITIONS.
	 * @param float  $order_total            The completed order's grand total.
	 * @param float  $min_total             Threshold for the min_total condition.
	 * @param int    $completed_order_count The customer's completed-order count (incl. this one);
	 *                                       pass a large number for guests so first_order never matches.
	 */
	public static function qualifies( string $condition, float $order_total, float $min_total, int $completed_order_count ): bool {
		switch ( self::normalize_condition( $condition ) ) {
			case 'first_order':
				return $completed_order_count <= 1;
			case 'min_total':
				return $order_total >= $min_total;
			case 'all':
			default:
				return true;
		}
	}
}
