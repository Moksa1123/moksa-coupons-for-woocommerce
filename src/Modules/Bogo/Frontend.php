<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Bogo;

use Moksafocou\Support\SpecialPriceFrontend;
use Moksafocou\Support\SpecialPriceTypes;

defined( 'ABSPATH' ) || exit;

/**
 * BOGO runtime: turns a 'moksafocou_bogo' coupon into a real discount by lowering the price of
 * reward line items already in the cart (set_price), never via WC's coupon-amount engine. Works for
 * classic AND Block/Store-API because both run the standard WC_Cart calculation.
 *
 * The shared set_price plumbing — boot, one-per-cart validity, cart/order display, savings feed,
 * order persistence and the anti-compounding base-price memo — lives in SpecialPriceFrontend. This
 * class supplies only the BOGO engine (trigger/reward roles → BogoCalc) and its notice wording.
 */
final class Frontend {

	use SpecialPriceFrontend;

	private const ORDER_META       = '_moksafocou_bogo_order_discounts';
	private const COUPON_LINE_META = '_moksafocou_bogo_coupon_discount';

	protected static function coupon_type(): string {
		return BogoMeta::TYPE;
	}

	protected static function css_slug(): string {
		return 'bogo';
	}

	protected static function priority_filter(): string {
		return 'moksafocou_bogo_priority';
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

		// First applied BOGO coupon wins (one per cart — is_valid rejects the rest).
		$code = '';
		foreach ( $applied as $applied_code ) {
			$coupon = SpecialPriceTypes::safe_coupon( (string) $applied_code );
			if ( $coupon instanceof \WC_Coupon && $coupon->is_type( BogoMeta::TYPE ) ) {
				$code = $applied_code;
				break;
			}
		}
		if ( '' === $code ) {
			self::reset();
			return;
		}

		$coupon = new \WC_Coupon( $code );
		$cfg    = BogoMeta::read( $coupon->get_id() );

		$lines = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$role = self::classify( $product, $cfg );
			if ( 'none' === $role ) {
				continue;
			}
			$lines[] = array(
				'key'   => (string) $key,
				'qty'   => (int) $item['quantity'],
				'price' => self::base_price( (string) $key, $product ),
				'role'  => $role,
			);
		}

		$plan = BogoCalc::compute(
			array(
				'trigger_qty'  => $cfg['trigger_qty'],
				'reward_qty'   => $cfg['reward_qty'],
				'reward_mode'  => $cfg['reward_mode'],
				'reward_value' => $cfg['reward_value'],
				'deal_mode'    => $cfg['deal_mode'],
				'repeat_limit' => $cfg['repeat_limit'],
			),
			$lines
		);

		$display = self::set_reward_prices( $cart, $plan['rewards'] );

		self::$price_display = array() === $display ? array() : array( $code => $display );
		self::$notices       = $plan['reward_short'] ? array( $code => self::notice_text( $cfg, $coupon ) ) : array();
	}

	private static function classify( \WC_Product $product, array $cfg ): string {
		$pid       = $product->get_id();
		$parent_id = $product->get_parent_id();
		if ( self::matches( $pid, $parent_id, $cfg['trigger_product_ids'], $cfg['trigger_category_ids'] ) ) {
			return 'trigger';
		}
		if ( self::matches( $pid, $parent_id, $cfg['reward_product_ids'], $cfg['reward_category_ids'] ) ) {
			return 'reward';
		}
		return 'none';
	}

	/**
	 * @param int            $pid          Product ID.
	 * @param int            $parent_id    Parent product ID (variations), or 0.
	 * @param array<int,int> $product_ids  Configured product IDs.
	 * @param array<int,int> $category_ids Configured category term IDs.
	 */
	private static function matches( int $pid, int $parent_id, array $product_ids, array $category_ids ): bool {
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
			/* translators: %s: coupon code. */
			$msg = sprintf( __( 'You qualify for the Buy X Get Y offer on "%s" — add the gift to your cart to get the discount.', 'moksa-coupons-for-woocommerce' ), $coupon->get_code() );
		}
		return str_replace(
			array( '{bogo_qty}', '{coupon_code}' ),
			array( (string) ( $cfg['reward_qty'] ?? 1 ), $coupon->get_code() ),
			$msg
		);
	}
}
