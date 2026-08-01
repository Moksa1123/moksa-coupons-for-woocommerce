<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers for the "do this once per order" pattern shared by every order-completion handler
 * (cashback award, remarketing issue, referral reward, store-credit debit): resolve the order id to
 * a WC_Order, check a meta flag so the action never runs twice, and stamp it when done.
 */
final class OrderOnce {

	/** Resolve an order id (from a hook arg) to a WC_Order, or null. */
	public static function get( $order_id ): ?\WC_Order {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		return $order instanceof \WC_Order ? $order : null;
	}

	/** Whether this one-time action has already run for the order (the flag meta is set). */
	public static function done( \WC_Order $order, string $flag ): bool {
		return '' !== (string) $order->get_meta( $flag );
	}

	/** Record that the action ran (and persist the order). */
	public static function mark( \WC_Order $order, string $flag, string $value = '1' ): void {
		$order->update_meta_data( $flag, $value );
		$order->save();
	}
}
