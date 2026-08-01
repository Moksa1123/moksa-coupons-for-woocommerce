<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\DiscountCap;

use Moksafocou\Coupon\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Caps the TOTAL discount a percent coupon may give at a per-coupon maximum amount
 * ("8 折但最多折 500"). WooCommerce applies a percent discount per cart item, so we
 * keep a remaining-budget per coupon: reset it each calculation on
 * woocommerce_before_calculate_totals, then on woocommerce_coupon_get_discount_amount
 * (called per item) grant at most the remaining budget and deduct it. When the budget
 * is exhausted, further items get no discount, so the cumulative discount equals the cap.
 *
 * Budgets are tracked in WooCommerce's integer "number precision" (cents) to avoid
 * float drift; the pure cap_step() helper is unit-tested in either unit.
 */
final class Cap {

	/** @var array<string,int> code => remaining discount budget (in WC number precision). */
	private static array $budgets = array();

	public static function boot(): void {
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'register_budgets' ), 9 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( self::class, 'apply_cap' ), 90, 5 );
	}

	/**
	 * @param mixed $cart WC_Cart.
	 */
	public static function register_budgets( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		self::$budgets = array();
		// Iterate the applied CODES and construct each coupon defensively: a cart can carry a
		// dangling code (e.g. a coupon deleted while still applied to a session), and constructing
		// it can throw "Invalid coupon" — which must never fatal the whole cart from our hook.
		// WC removes such invalid coupons later in the same calculation; we just skip them here.
		foreach ( $cart->get_applied_coupons() as $code ) {
			try {
				$coupon = new \WC_Coupon( $code );
			} catch ( \Exception $e ) {
				continue;
			}
			if ( 'percent' !== $coupon->get_discount_type() ) {
				continue;
			}
			$cap = (float) $coupon->get_meta( Keys::DISCOUNT_CAP, true );
			if ( $cap > 0 && function_exists( 'wc_add_number_precision' ) ) {
				self::$budgets[ $coupon->get_code() ] = (int) wc_add_number_precision( $cap );
			}
		}
	}

	/**
	 * @param mixed $discount           Per-item discount (currency units).
	 * @param mixed $discounting_amount Amount being discounted.
	 * @param mixed $cart_item          Cart item.
	 * @param mixed $single             Single-qty flag.
	 * @param mixed $coupon             WC_Coupon.
	 * @return mixed Capped per-item discount.
	 */
	public static function apply_cap( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon || 'percent' !== $coupon->get_discount_type() ) {
			return $discount;
		}
		$code = $coupon->get_code();
		if ( ! isset( self::$budgets[ $code ] ) || ! function_exists( 'wc_add_number_precision' ) ) {
			return $discount;
		}

		$precise                = (int) wc_add_number_precision( (float) $discount );
		$step                   = self::cap_step( $precise, self::$budgets[ $code ] );
		self::$budgets[ $code ] = $step['remaining'];

		return wc_remove_number_precision( $step['granted'] );
	}

	/**
	 * Pure: grant at most the remaining budget for this item, then deduct it.
	 *
	 * @return array{granted:int,remaining:int}
	 */
	public static function cap_step( int $discount, int $remaining ): array {
		$remaining = max( 0, $remaining );
		$granted   = max( 0, min( $discount, $remaining ) );
		return array(
			'granted'   => $granted,
			'remaining' => $remaining - $granted,
		);
	}
}
