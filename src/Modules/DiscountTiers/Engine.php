<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\DiscountTiers;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\Tiers;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime for tiered discounts. A native percent coupon is turned into a tier-driven discount:
 * we measure the cart against the coupon's chosen basis (subtotal / quantity / weight) on
 * woocommerce_before_calculate_totals, resolve the best-value tier (percent or fixed), then on
 * woocommerce_coupon_get_discount_amount (per line) return that line's share — limited to the
 * targeted products / categories. A percent tier discounts each line by its percentage; a fixed
 * tier distributes its (capped) amount across the targeted lines proportionally. When tiers are
 * enabled the coupon is FULLY tier-driven (its native amount is ignored); a cart below every
 * tier, or a line out of scope, gets no discount.
 *
 * Runs the per-line filter at priority 20 — before DiscountCap's cap at 90 — so they compose.
 */
final class Engine {

	/**
	 * Per-coupon active config for the current calculation.
	 *
	 * @var array<string,array{kind:string,value:float,base:float,mode:string,products:array<int,int>,categories:array<int,int>}>
	 */
	private static array $active = array();

	public static function boot(): void {
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'register' ), 9 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( self::class, 'apply' ), 20, 5 );
	}

	/**
	 * @param mixed $cart WC_Cart.
	 */
	public static function register( $cart ): void {
		self::$active = array();
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		list( $subtotal, $qty, $weight ) = self::measure( $cart );

		foreach ( $cart->get_coupons() as $coupon ) {
			if ( ! $coupon instanceof \WC_Coupon || 'percent' !== $coupon->get_discount_type() ) {
				continue;
			}
			if ( 'yes' !== $coupon->get_meta( Keys::TIERS_ENABLED, true ) ) {
				continue;
			}
			$tiers = Tiers::parse( (string) $coupon->get_meta( Keys::TIERS, true ) );
			$basis = Tiers::basis( $coupon->get_meta( Keys::TIERS_BASIS, true ) );
			$mode  = (string) $coupon->get_meta( Keys::TIERS_TARGET_MODE, true );
			$mode  = in_array( $mode, array( 'products', 'categories' ), true ) ? $mode : 'all';

			$products   = self::ids( $coupon->get_meta( Keys::TIERS_TARGET_PRODUCTS, true ) );
			$categories = self::ids( $coupon->get_meta( Keys::TIERS_TARGET_CATEGORIES, true ) );
			$base       = self::targeted_subtotal( $cart, $mode, $products, $categories );

			$measure  = 'quantity' === $basis ? (float) $qty : ( 'weight' === $basis ? $weight : $subtotal );
			$resolved = Tiers::resolve( $tiers, $measure, $base );

			$value = (float) $resolved['value'];
			if ( 'percent' === $resolved['kind'] ) {
				/**
				 * Filter the resolved tier percent for a coupon before it is applied (e.g. a
				 * loyalty bump for VIP customers). Only fires for percent-kind tiers.
				 *
				 * @param float      $value    Resolved percent-off for the active tier.
				 * @param \WC_Coupon $coupon   The coupon.
				 * @param float      $subtotal Cart subtotal.
				 * @param int        $qty      Cart quantity.
				 */
				$value = (float) apply_filters( 'moksafocou_tier_percent', $value, $coupon, $subtotal, $qty );
			}

			self::$active[ $coupon->get_code() ] = array(
				'kind'       => (string) $resolved['kind'],
				'value'      => $value,
				'base'       => $base,
				'mode'       => $mode,
				'products'   => $products,
				'categories' => $categories,
			);
		}
	}

	/**
	 * The cart's subtotal (Σ price × qty), total quantity and total weight — the three bases a
	 * tier ladder can be measured against. Shared so the runtime and the nudge agree exactly.
	 *
	 * @param \WC_Cart $cart
	 * @return array{0:float,1:int,2:float} [ subtotal, qty, weight ]
	 */
	public static function measure( \WC_Cart $cart ): array {
		$subtotal = 0.0;
		$qty      = 0;
		$weight   = 0.0;
		foreach ( $cart->get_cart_contents() as $item ) {
			$count   = (int) ( $item['quantity'] ?? 0 );
			$qty    += $count;
			$product = $item['data'] ?? null;
			if ( $product instanceof \WC_Product ) {
				$subtotal += (float) $product->get_price() * $count;
				$weight   += (float) $product->get_weight() * $count;
			}
		}
		return array( $subtotal, $qty, $weight );
	}

	/**
	 * Sum of the discountable (price × qty) amount over the lines a tier coupon targets — the
	 * base a fixed tier distributes across and a percent tier is applied to.
	 *
	 * @param \WC_Cart       $cart
	 * @param string         $mode
	 * @param array<int,int> $products
	 * @param array<int,int> $categories
	 */
	public static function targeted_subtotal( \WC_Cart $cart, string $mode, array $products, array $categories ): float {
		$sum = 0.0;
		foreach ( $cart->get_cart_contents() as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$pid  = (int) ( $item['product_id'] ?? 0 );
			$vid  = (int) ( $item['variation_id'] ?? 0 );
			$cats = ( $pid > 0 && function_exists( 'wc_get_product_cat_ids' ) ) ? array_map( 'intval', wc_get_product_cat_ids( $pid ) ) : array();
			if ( Tiers::is_targeted( $mode, $products, $categories, $pid, $vid, $cats ) ) {
				$sum += (float) $product->get_price() * (int) ( $item['quantity'] ?? 0 );
			}
		}
		return $sum;
	}

	/**
	 * @param mixed $discount           Per-line discount WooCommerce computed (currency units).
	 * @param mixed $discounting_amount Amount being discounted for this line.
	 * @param mixed $cart_item          Cart item.
	 * @param mixed $single             Single-qty flag.
	 * @param mixed $coupon             WC_Coupon.
	 * @return mixed Tier-driven per-line discount.
	 */
	public static function apply( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon || 'percent' !== $coupon->get_discount_type() ) {
			return $discount;
		}
		$code = $coupon->get_code();
		if ( ! isset( self::$active[ $code ] ) ) {
			return $discount;
		}
		$cfg = self::$active[ $code ];

		$pid  = is_array( $cart_item ) ? (int) ( $cart_item['product_id'] ?? 0 ) : 0;
		$vid  = is_array( $cart_item ) ? (int) ( $cart_item['variation_id'] ?? 0 ) : 0;
		$cats = ( $pid > 0 && function_exists( 'wc_get_product_cat_ids' ) )
			? array_map( 'intval', wc_get_product_cat_ids( $pid ) )
			: array();

		if ( ! Tiers::is_targeted( $cfg['mode'], $cfg['products'], $cfg['categories'], $pid, $vid, $cats ) ) {
			return 0.0;
		}
		if ( 'fixed' === $cfg['kind'] ) {
			return Tiers::fixed_line_share( (float) $discounting_amount, (float) $cfg['base'], (float) $cfg['value'] );
		}
		return Tiers::line_discount( (float) $discounting_amount, (float) $cfg['value'] );
	}

	/**
	 * @param mixed $value
	 * @return array<int,int>
	 */
	private static function ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $v ) {
			$id = (int) $v;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
