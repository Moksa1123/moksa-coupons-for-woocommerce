<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\MixMatch;

use Moksafocou\Coupon\CouponService;
use Moksafocou\Support\GuardedOps;

defined( 'ABSPATH' ) || exit;

/**
 * Destructive create-mixmatch-coupon op as a propose/apply pair, mirroring BogoOps. create_prepare
 * proposes only (no writes); create_apply runs solely after a human confirmation. Both ends re-check
 * the capability. The config is written via the shared MixMatchMeta so the AI and admin paths can
 * never diverge.
 */
final class MixMatchOps {

	use GuardedOps;

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input = is_array( $input ) ? $input : array();

		$native_input                  = $input;
		$native_input['discount_type'] = 'fixed_cart';
		$native_input['amount']        = 0;
		$fields                        = CouponService::normalize_and_validate( $native_input, false );
		if ( $fields instanceof \WP_Error ) {
			return $fields;
		}
		if ( empty( $fields['code'] ) ) {
			return new \WP_Error( 'moksafocou_invalid_code', __( 'Coupon code cannot be empty.', 'moksa-coupons-for-woocommerce' ) );
		}
		if ( CouponService::find_id_by_code( $fields['code'] ) > 0 ) {
			return new \WP_Error(
				'moksafocou_duplicate',
				/* translators: %s: coupon code. */
				sprintf( __( 'Coupon code %s already exists; please use another.', 'moksa-coupons-for-woocommerce' ), $fields['code'] )
			);
		}
		$fields['discount_type'] = MixMatchMeta::TYPE;
		$fields['amount']        = 0;

		$mixmatch = self::normalize_mixmatch( $input );
		if ( $mixmatch instanceof \WP_Error ) {
			return $mixmatch;
		}

		return array(
			'fields'   => $fields,
			'mixmatch' => $mixmatch,
			'summary'  => self::build_summary( (string) $fields['code'], $mixmatch ),
		);
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$fields   = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : array();
		$mixmatch = isset( $params['mixmatch'] ) && is_array( $params['mixmatch'] ) ? $params['mixmatch'] : array();

		$coupon = CouponService::save( $fields );
		if ( $coupon instanceof \WP_Error ) {
			return $coupon;
		}
		MixMatchMeta::write( $coupon->get_id(), $mixmatch );

		return array(
			'id'    => $coupon->get_id(),
			'reply' => sprintf(
				/* translators: %s: coupon code. */
				__( 'Created Mix & Match coupon %s.', 'moksa-coupons-for-woocommerce' ),
				$coupon->get_code()
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function normalize_mixmatch( array $input ) {
		// Validate the RAW value before sanitize clamps it, so an out-of-range request returns an
		// explicit error to the AI/MCP caller instead of being silently rewritten to qty=1.
		if ( (int) ( $input['qty'] ?? 0 ) < 1 ) {
			return new \WP_Error( 'moksafocou_mixmatch_bad_qty', __( 'The number of items to pick must be at least 1.', 'moksa-coupons-for-woocommerce' ) );
		}
		$cfg = MixMatchMeta::sanitize( $input );
		if ( 'percent' === $cfg['price_mode'] && $cfg['price_value'] > 100 ) {
			return new \WP_Error( 'moksafocou_mixmatch_bad_value', __( 'Percentage discount cannot exceed 100.', 'moksa-coupons-for-woocommerce' ) );
		}
		if ( 'fixed_total' === $cfg['price_mode'] && $cfg['price_value'] <= 0 ) {
			return new \WP_Error( 'moksafocou_mixmatch_bad_value', __( 'The fixed total must be greater than 0.', 'moksa-coupons-for-woocommerce' ) );
		}
		return $cfg;
	}

	/**
	 * @param string              $code Coupon code.
	 * @param array<string,mixed> $cfg  Normalized mix & match config.
	 */
	private static function build_summary( string $code, array $cfg ): string {
		if ( 'percent' === $cfg['price_mode'] ) {
			/* translators: 1: N, 2: percent. */
			$deal = sprintf( __( 'Pick %1$d items, %2$s%% off the group', 'moksa-coupons-for-woocommerce' ), (int) $cfg['qty'], (string) $cfg['price_value'] );
		} else {
			/* translators: 1: N, 2: total price. */
			$deal = sprintf( __( 'Pick %1$d items for %2$s', 'moksa-coupons-for-woocommerce' ), (int) $cfg['qty'], (string) $cfg['price_value'] );
		}
		$repeat = 'repeat' === $cfg['deal_mode'] ? __( '(repeatable)', 'moksa-coupons-for-woocommerce' ) : __( '(one time only)', 'moksa-coupons-for-woocommerce' );

		return sprintf(
			/* translators: 1: code, 2: deal desc, 3: repeat note. */
			__( 'Create a Mix & Match coupon %1$s: %2$s %3$s', 'moksa-coupons-for-woocommerce' ),
			$code,
			$deal,
			$repeat
		);
	}
}
