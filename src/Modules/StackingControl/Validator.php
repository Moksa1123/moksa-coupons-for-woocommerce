<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\StackingControl;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces coupon stacking rules at apply + recalculation time via the shared
 * woocommerce_coupon_is_valid path (classic + Block/Store API). A conflict throws an
 * Exception whose message names the conflicting coupon; for the Block checkout the
 * message is restored through woocommerce_coupon_error.
 *
 * Runs at priority 12 — after the conditions Validator (10) and BOGO (11) — so those
 * cheaper, single-coupon checks fail first and we only reach stacking once a coupon
 * is otherwise valid.
 */
final class Validator {

	/** @var array<int,string> Custom error message per coupon id, for the Block error filter. */
	private static array $last_error = array();

	public static function boot(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'validate' ), 12, 2 );
		add_filter( 'woocommerce_coupon_error', array( self::class, 'block_error' ), 12, 3 );
	}

	/**
	 * @param mixed $valid  WC's prior validity result.
	 * @param mixed $coupon WC_Coupon.
	 * @return mixed
	 * @throws \Exception When the coupon conflicts with an already-applied coupon.
	 */
	public static function validate( $valid, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon ) {
			return $valid;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return $valid; // No cart context (e.g. admin save) — nothing to stack against.
		}
		$conflict = self::conflict( $coupon, WC()->cart );
		if ( null === $conflict ) {
			return $valid;
		}

		$id  = $coupon->get_id();
		$cfg = StackConfig::read( $coupon );
		$msg = '' !== trim( $cfg['msg'] )
			? $cfg['msg']
			: sprintf(
				/* translators: %s: the conflicting coupon code. */
				__( 'This coupon cannot be used together with "%s".', 'moksafocou' ),
				$conflict
			);
		self::$last_error[ $id ] = $msg;
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message run through wp_kses_post.
		throw new \Exception( wp_kses_post( $msg ) );
	}

	/**
	 * The conflicting already-applied coupon code, or null. Builds the "others" rule
	 * set from the cart's applied coupons (excluding the one under validation).
	 */
	public static function conflict( \WC_Coupon $coupon, \WC_Cart $cart ): ?string {
		$code = StackConfig::normalize( $coupon->get_code() );
		$self = StackConfig::read( $coupon );

		$others = array();
		foreach ( $cart->get_applied_coupons() as $applied_code ) {
			$norm = StackConfig::normalize( (string) $applied_code );
			if ( $norm === $code || '' === $norm ) {
				continue;
			}
			$other = new \WC_Coupon( (string) $applied_code );
			if ( ! $other->get_id() ) {
				continue;
			}
			$rules    = StackConfig::read( $other );
			$others[] = array(
				'code'       => $norm,
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- 'exclude' is the stacking flag from StackConfig::read(), not a get_posts/WP_Query parameter.
				'exclude'    => $rules['exclude'],
				'allowed'    => $rules['allowed'],
				'disallowed' => $rules['disallowed'],
			);
		}

		return StackConfig::stack_conflict( $code, $self, $others );
	}

	/**
	 * Store API shows WC_Coupon::get_error_message() (by code), not the thrown
	 * Exception text — restore our custom message here.
	 *
	 * @param mixed $message Default WC message.
	 * @param mixed $code    Error code.
	 * @param mixed $coupon  WC_Coupon.
	 * @return mixed
	 */
	public static function block_error( $message, $code, $coupon ) {
		if ( $coupon instanceof \WC_Coupon && isset( self::$last_error[ $coupon->get_id() ] ) ) {
			return self::$last_error[ $coupon->get_id() ];
		}
		return $message;
	}
}
