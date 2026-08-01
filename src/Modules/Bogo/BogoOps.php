<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Bogo;

use Moksafocou\Coupon\CouponService;
use Moksafocou\Support\GuardedOps;

defined( 'ABSPATH' ) || exit;

/**
 * Destructive create-bogo-coupon op as a propose/apply pair, mirroring CouponOps.
 * create_prepare proposes only (no writes); create_apply runs solely after a human
 * confirmation (AI confirm flow / REST). Both ends re-check the capability. Native
 * fields reuse CouponService; the BOGO config is written via the shared BogoMeta so
 * the AI and admin-panel paths can never diverge.
 */
final class BogoOps {

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

		// Native fields: validate with a placeholder type/amount, then force the BOGO type.
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
		$fields['discount_type'] = BogoMeta::TYPE;
		$fields['amount']        = 0;

		$bogo = self::normalize_bogo( $input );
		if ( $bogo instanceof \WP_Error ) {
			return $bogo;
		}

		return array(
			'fields'  => $fields,
			'bogo'    => $bogo,
			'summary' => self::build_summary( (string) $fields['code'], $bogo ),
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
		$fields = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : array();
		$bogo   = isset( $params['bogo'] ) && is_array( $params['bogo'] ) ? $params['bogo'] : array();

		$coupon = CouponService::save( $fields );
		if ( $coupon instanceof \WP_Error ) {
			return $coupon;
		}
		BogoMeta::write( $coupon->get_id(), $bogo );

		return array(
			'id'    => $coupon->get_id(),
			'reply' => sprintf(
				/* translators: %s: coupon code. */
				__( 'Buy X Get Y coupon %s created.', 'moksa-coupons-for-woocommerce' ),
				$coupon->get_code()
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function normalize_bogo( array $input ) {
		$cfg         = BogoMeta::sanitize( $input );
		$has_trigger = array() !== $cfg['trigger_product_ids'] || array() !== $cfg['trigger_category_ids'];
		$has_reward  = array() !== $cfg['reward_product_ids'] || array() !== $cfg['reward_category_ids'];

		if ( ! $has_trigger ) {
			return new \WP_Error( 'moksafocou_bogo_no_trigger', __( 'Please specify the purchase-condition products or categories.', 'moksa-coupons-for-woocommerce' ) );
		}
		if ( ! $has_reward ) {
			return new \WP_Error( 'moksafocou_bogo_no_reward', __( 'Please specify the gift products or categories.', 'moksa-coupons-for-woocommerce' ) );
		}
		if ( 'percent' === $cfg['reward_mode'] && $cfg['reward_value'] > 100 ) {
			return new \WP_Error( 'moksafocou_bogo_bad_value', __( 'Percentage discount cannot exceed 100.', 'moksa-coupons-for-woocommerce' ) );
		}
		if ( 'fixed_per_item' === $cfg['reward_mode'] && $cfg['reward_value'] <= 0 ) {
			return new \WP_Error( 'moksafocou_bogo_bad_value', __( 'Fixed discount per item must be greater than 0.', 'moksa-coupons-for-woocommerce' ) );
		}
		return $cfg;
	}

	/**
	 * @param string              $code Coupon code.
	 * @param array<string,mixed> $cfg  Normalized BOGO config.
	 */
	private static function build_summary( string $code, array $cfg ): string {
		$mode = (string) $cfg['reward_mode'];
		if ( 'free' === $mode ) {
			$reward = __( 'Free', 'moksa-coupons-for-woocommerce' );
		} elseif ( 'fixed_per_item' === $mode ) {
			/* translators: %s: per-item discount amount. */
			$reward = sprintf( __( '%s off per item', 'moksa-coupons-for-woocommerce' ), (string) $cfg['reward_value'] );
		} else {
			/* translators: %s: percent discount. */
			$reward = sprintf( __( '%s%% off', 'moksa-coupons-for-woocommerce' ), (string) $cfg['reward_value'] );
		}
		$repeat = 'repeat' === $cfg['deal_mode'] ? __( '(repeatable)', 'moksa-coupons-for-woocommerce' ) : __( '(one time only)', 'moksa-coupons-for-woocommerce' );

		return sprintf(
			/* translators: 1: code, 2: trigger qty, 3: reward qty, 4: reward desc, 5: repeat note. */
			__( 'Create Buy X Get Y coupon %1$s: for every %2$d of the specified items → %3$d gift item(s) %4$s %5$s', 'moksa-coupons-for-woocommerce' ),
			$code,
			(int) $cfg['trigger_qty'],
			(int) $cfg['reward_qty'],
			$reward,
			$repeat
		);
	}
}
