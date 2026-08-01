<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\FreeGift;

use Moksafocou\Coupon\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a coupon's single free-gift config and computes the gift's cart price. The
 * gift product is added to the cart with the ITEM_* cart-item meta so the runtime
 * can identify it, lock it, price it idempotently, and remove it. The gift_price()
 * helper is pure (unit-tested).
 */
final class GiftConfig {

	public const MODES = array( 'free', 'percent', 'fixed' );

	/* Cart-item meta keys carried on the auto-added gift line. */
	public const ITEM_FLAG  = 'moksafocou_gift';       // coupon code this gift belongs to.
	public const ITEM_QTY   = 'moksafocou_gift_qty';   // locked quantity.
	public const ITEM_BASE  = 'moksafocou_gift_base';  // original price captured at add time.
	public const ITEM_MODE  = 'moksafocou_gift_mode';
	public const ITEM_VALUE = 'moksafocou_gift_value';

	/**
	 * @return array{enabled:bool,product_id:int,qty:int,mode:string,value:float}
	 */
	public static function read( int $coupon_id ): array {
		return array(
			'enabled'    => 'yes' === get_post_meta( $coupon_id, Keys::GIFT_ENABLED, true ),
			'product_id' => (int) get_post_meta( $coupon_id, Keys::GIFT_PRODUCT_ID, true ),
			'qty'        => max( 1, (int) get_post_meta( $coupon_id, Keys::GIFT_QTY, true ) ),
			'mode'       => self::mode( (string) get_post_meta( $coupon_id, Keys::GIFT_MODE, true ) ),
			'value'      => max( 0.0, (float) get_post_meta( $coupon_id, Keys::GIFT_VALUE, true ) ),
		);
	}

	/**
	 * @param array{enabled:bool,product_id:int,qty:int,mode:string,value:float} $cfg
	 */
	public static function is_active( array $cfg ): bool {
		return $cfg['enabled'] && $cfg['product_id'] > 0;
	}

	public static function mode( string $mode ): string {
		return in_array( $mode, self::MODES, true ) ? $mode : 'free';
	}

	/**
	 * Pure: the gift's per-unit cart price, clamped to ≥ 0 (a fixed discount larger
	 * than the price floors the item at free, never negative).
	 */
	public static function gift_price( float $base, string $mode, float $value ): float {
		switch ( $mode ) {
			case 'free':
				$price = 0.0;
				break;
			case 'fixed':
				$price = $base - $value;
				break;
			case 'percent':
			default:
				$price = $base - ( $base * ( max( 0.0, min( 100.0, $value ) ) / 100 ) );
				break;
		}
		return max( 0.0, $price );
	}
}
