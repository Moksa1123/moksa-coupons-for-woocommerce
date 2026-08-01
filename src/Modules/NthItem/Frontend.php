<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\NthItem;

use Moksafocou\Support\SpecialPriceFrontend;
use Moksafocou\Support\SpecialPriceTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Nth-item runtime: turns a 'moksafocou_nth_item' coupon into a real discount by lowering the price
 * of the discounted units already in the cart (set_price), never via WC's coupon-amount engine.
 * Works for classic AND Block/Store-API because both run the standard WC_Cart calculation.
 *
 * The shared set_price plumbing (boot, one-per-cart validity, cart/order display, savings feed,
 * persistence, anti-compounding base price) lives in SpecialPriceFrontend; this class supplies only
 * the Nth-item engine (set membership → NthItemCalc) and its notice wording.
 */
final class Frontend {

	use SpecialPriceFrontend;

	private const ORDER_META       = '_moksafocou_nth_order_discounts';
	private const COUPON_LINE_META = '_moksafocou_nth_coupon_discount';

	protected static function coupon_type(): string {
		return NthItemMeta::TYPE;
	}

	protected static function css_slug(): string {
		return 'nth';
	}

	protected static function priority_filter(): string {
		return 'moksafocou_nthitem_priority';
	}

	/**
	 * @param mixed $cart WC_Cart passed by the hook.
	 */
	public static function apply( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		$applied = $cart->get_applied_coupons();
		if ( empty( $applied ) ) {
			self::reset();
			return;
		}

		$code = '';
		foreach ( $applied as $applied_code ) {
			$coupon = SpecialPriceTypes::safe_coupon( (string) $applied_code );
			if ( $coupon instanceof \WC_Coupon && $coupon->is_type( NthItemMeta::TYPE ) ) {
				$code = $applied_code;
				break;
			}
		}
		if ( '' === $code ) {
			self::reset();
			return;
		}

		$coupon = new \WC_Coupon( $code );
		$cfg    = NthItemMeta::read( $coupon->get_id() );

		$lines = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			if ( ! self::in_set( $product, $cfg ) ) {
				continue;
			}
			$lines[] = array(
				'key'   => (string) $key,
				'qty'   => (int) $item['quantity'],
				'price' => self::base_price( (string) $key, $product ),
			);
		}

		$plan = NthItemCalc::compute(
			array(
				'n'            => $cfg['n'],
				'reward_mode'  => $cfg['reward_mode'],
				'reward_value' => $cfg['reward_value'],
				'deal_mode'    => $cfg['deal_mode'],
				'repeat_limit' => $cfg['repeat_limit'],
				'group_by'     => $cfg['group_by'],
			),
			$lines
		);

		$display = self::set_reward_prices( $cart, $plan['rewards'] );

		self::$price_display = array() === $display ? array() : array( $code => $display );
		self::$notices       = $plan['short'] ? array( $code => self::notice_text( $cfg, $coupon ) ) : array();
	}

	/** Whether a product is in the coupon's set. Empty config (no products AND no categories) = all. */
	private static function in_set( \WC_Product $product, array $cfg ): bool {
		$product_ids  = $cfg['product_ids'];
		$category_ids = $cfg['category_ids'];
		if ( array() === $product_ids && array() === $category_ids ) {
			return true;
		}
		$pid       = $product->get_id();
		$parent_id = $product->get_parent_id();
		if ( in_array( $pid, $product_ids, true ) || ( $parent_id && in_array( $parent_id, $product_ids, true ) ) ) {
			return true;
		}
		if ( ! empty( $category_ids ) && function_exists( 'wc_get_product_cat_ids' ) ) {
			$terms = wc_get_product_cat_ids( $parent_id ? $parent_id : $pid );
			if ( array_intersect( $category_ids, $terms ) ) {
				return true;
			}
		}
		return false;
	}

	private static function notice_text( array $cfg, \WC_Coupon $coupon ): string {
		$msg = trim( (string) ( $cfg['notice_msg'] ?? '' ) );
		if ( '' === $msg ) {
			/* translators: %d: required item count N. */
			$msg = sprintf( __( 'Buy %d items to enjoy the Nth-item discount.', 'moksa-coupons-for-woocommerce' ), (int) ( $cfg['n'] ?? 2 ) );
		}
		return str_replace(
			array( '{nth_n}', '{coupon_code}' ),
			array( (string) ( $cfg['n'] ?? 2 ), $coupon->get_code() ),
			$msg
		);
	}
}
