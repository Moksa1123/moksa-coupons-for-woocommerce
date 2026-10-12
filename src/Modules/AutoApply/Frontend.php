<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\AutoApply;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-applies eligible coupons to the cart (classic + Block/Store-API share one
 * path). Runs on woocommerce_after_calculate_totals (so cart totals are settled and
 * our CouponConditions Validator evaluates cart-minimums correctly), validates via
 * WC_Discounts::is_coupon_valid (which internally catches our Validator's thrown
 * Exception and runs BOGO is_valid), and adds the coupon with set_applied_coupons —
 * NOT apply_coupon — to avoid recalc/notice spam. A static sentinel makes the one
 * follow-up calculate_totals() (which lets the Store-API first response reflect the
 * discount) safe against recursion.
 *
 * An auto-coupon that later becomes invalid is taken back out here, quietly. This used to be left
 * to WC core's check_cart_coupons(), which holds for the classic cart only: the block cart reads
 * through the Store API, which never runs that check, so a coupon the shop had since drafted or
 * deleted stayed in em925's block cart as a NT$0 chip until checkout refused it. Only codes this
 * class added are tracked (in the WC session) and only those are removed — a code the customer
 * typed is WooCommerce's business, not ours.
 */
final class Frontend {

	/** WC session key: the codes this class put into the cart. */
	public const SESSION_KEY = 'moksafocou_autoapplied';

	private static bool $running = false;

	public static function boot(): void {
		add_action( 'woocommerce_after_calculate_totals', array( self::class, 'apply' ), 20, 1 );
		add_action( 'wp', array( self::class, 'force_create_cart_session' ) );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( self::class, 'hide_remove_link' ), 10, 2 );
	}

	/**
	 * @param mixed $cart WC_Cart.
	 */
	public static function apply( $cart ): void {
		if ( self::$running || ! $cart instanceof \WC_Cart ) {
			return;
		}
		if ( ! function_exists( 'wc_coupons_enabled' ) || ! wc_coupons_enabled() ) {
			return;
		}
		$ids  = (array) apply_filters( 'moksafocou_autoapply_coupons', AutoApplyMeta::ids() );
		$ours = self::tracked();
		if ( array() === $ids && array() === $ours ) {
			return;
		}

		self::$running = true;
		$changed       = false;
		try {
			// 1. Take back what we added and can no longer apply.
			$stale = AutoApplyMeta::stale( $cart->get_applied_coupons(), $ours, static fn( string $code ): bool => self::still_valid( $cart, $code ) );
			foreach ( $stale as $code ) {
				// remove_coupon(), not set_applied_coupons(): it also clears the coupon's discount
				// totals and fires woocommerce_removed_coupon, exactly as a customer's removal would.
				$cart->remove_coupon( $code );
				$changed = true;
			}

			// 2. Add what is eligible and not there yet.
			$applied = $cart->get_applied_coupons();
			foreach ( $ids as $id ) {
				$coupon = new \WC_Coupon( (int) $id );
				if ( ! $coupon->get_id() ) {
					continue;
				}
				$code = $coupon->get_code();
				if ( in_array( $code, $applied, true ) ) {
					continue;
				}
				if ( ! AutoApplyMeta::eligible( $coupon ) ) {
					continue;
				}
				$discounts = new \WC_Discounts( $cart );
				if ( true === $discounts->is_coupon_valid( $coupon ) ) {
					$applied[] = $code;
					$ours[]    = $code;
					$cart->set_applied_coupons( $applied );
					$coupon->add_coupon_message( \WC_Coupon::WC_COUPON_SUCCESS );
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberately firing WooCommerce's own coupon-applied hook (mirrors WC_Cart::apply_coupon) so listeners react to our auto-applied coupon.
					do_action( 'woocommerce_applied_coupon', $code );
					$changed = true;
				}
			}

			// Forget codes that have left the cart, by our hand or the customer's.
			self::track( array_values( array_intersect( $ours, $cart->get_applied_coupons() ) ) );

			if ( $changed ) {
				// Recompute so this request's totals already reflect the change; the sentinel
				// makes the nested after_calculate_totals a no-op.
				$cart->calculate_totals();
			}
		} finally {
			self::$running = false;
		}
	}

	/**
	 * Can this code still apply? A drafted, trashed or deleted coupon no longer resolves to an ID
	 * at all; one that resolves goes through the same WC_Discounts check that put it in the cart.
	 */
	private static function still_valid( \WC_Cart $cart, string $code ): bool {
		$coupon = new \WC_Coupon( $code );
		if ( ! $coupon->get_id() || 'publish' !== get_post_status( $coupon->get_id() ) ) {
			return false;
		}
		return true === ( new \WC_Discounts( $cart ) )->is_coupon_valid( $coupon );
	}

	/** @return array<int,string> */
	private static function tracked(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}
		$codes = WC()->session->get( self::SESSION_KEY, array() );
		return is_array( $codes ) ? array_values( array_unique( array_map( 'strval', $codes ) ) ) : array();
	}

	/** @param array<int,string> $codes */
	private static function track( array $codes ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$codes = array_values( array_unique( $codes ) );
		if ( $codes !== self::tracked() ) {
			WC()->session->set( self::SESSION_KEY, array() === $codes ? null : $codes );
		}
	}

	/** Prime a guest session on classic cart/checkout so the auto-coupon can persist. */
	public static function force_create_cart_session(): void {
		if ( is_admin() || ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return;
		}
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}
		if ( WC()->cart->is_empty() && array() !== AutoApplyMeta::ids() && WC()->session ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	/**
	 * Hide the remove link for auto-applied coupons in the classic cart (cosmetic;
	 * removal is non-authoritative — the loop re-adds a still-valid coupon next calc).
	 *
	 * @param mixed $html
	 * @param mixed $coupon
	 * @return mixed
	 */
	public static function hide_remove_link( $html, $coupon ) {
		if ( $coupon instanceof \WC_Coupon && in_array( $coupon->get_id(), AutoApplyMeta::ids(), true ) ) {
			$html = preg_replace( '#<a[^>]*\bwoocommerce-remove-coupon\b[^>]*>.*?</a>#i', '', (string) $html );
		}
		return $html;
	}
}
