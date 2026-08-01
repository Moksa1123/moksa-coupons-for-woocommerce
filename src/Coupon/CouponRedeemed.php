<?php

declare( strict_types=1 );

namespace Moksafocou\Coupon;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\OrderOnce;

defined( 'ABSPATH' ) || exit;

/**
 * Fires ONE integration event — `moksafocou_coupon_redeemed` — once per coupon when an
 * order that used it is paid (processing or completed). This is the keystone other Moksa
 * plugins listen to: a points / loyalty plugin awards points, a group-buy / affiliate
 * plugin attributes the sale to a KOL and computes commission. moksafocou records
 * nothing here — it only announces the fact, keeping value (points) and attribution
 * (commission) out of the coupon plugin's lane.
 *
 * Always-on (an integration point must fire regardless of which modules are enabled).
 * See platform-plan/README.md §3.5-D.
 */
final class CouponRedeemed {

	/** Guard so the event fires once per order even though both status hooks run. */
	private const DONE_META = '_moksafocou_redeemed_fired';

	public static function register(): void {
		add_action( 'woocommerce_order_status_completed', array( self::class, 'fire' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'fire' ), 20, 1 );
	}

	/**
	 * @param mixed $order_id Order id from the status hook.
	 */
	public static function fire( $order_id ): void {
		$order = OrderOnce::get( $order_id );
		if ( null === $order || OrderOnce::done( $order, self::DONE_META ) ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}
			$code     = (string) $item->get_code();
			$discount = (float) $item->get_discount();
			$campaign = self::campaign_of( $code );

			/**
			 * Fires once when an order that used this coupon is paid. For points /
			 * commission integrations — moksafocou stores no value/attribution itself.
			 *
			 * @param string    $code     The coupon code used.
			 * @param \WC_Order $order    The paid order.
			 * @param int       $user_id  Customer id (0 for guest).
			 * @param float     $discount Discount this coupon line gave on the order.
			 * @param string    $campaign The coupon's campaign tag ('' if none / deleted).
			 */
			do_action( 'moksafocou_coupon_redeemed', $code, $order, $user_id, $discount, $campaign );
		}
		OrderOnce::mark( $order, self::DONE_META, '1' );
	}

	/** The coupon's campaign tag (best-effort; '' if the coupon was deleted). */
	private static function campaign_of( string $code ): string {
		$id = function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $code ) : 0;
		return $id ? (string) get_post_meta( (int) $id, Keys::CAMPAIGN, true ) : '';
	}
}
