<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\NthItem;

use Moksafocou\Coupon\CouponService;
use Moksafocou\Support\GuardedOps;

defined( 'ABSPATH' ) || exit;

/**
 * Destructive create-nth-item-coupon op as a propose/apply pair, mirroring BogoOps.
 * create_prepare proposes only (no writes); create_apply runs solely after a human confirmation.
 * Both ends re-check the capability. The Nth-item config is written via the shared NthItemMeta so
 * the AI and admin-panel paths can never diverge.
 */
final class NthItemOps {

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
			return new \WP_Error( 'moksafocou_invalid_code', __( 'Coupon code cannot be empty.', 'moksafocou' ) );
		}
		if ( CouponService::find_id_by_code( $fields['code'] ) > 0 ) {
			return new \WP_Error(
				'moksafocou_duplicate',
				/* translators: %s: coupon code. */
				sprintf( __( 'Coupon code %s already exists; please use another.', 'moksafocou' ), $fields['code'] )
			);
		}
		$fields['discount_type'] = NthItemMeta::TYPE;
		$fields['amount']        = 0;

		$nth = self::normalize_nth( $input );
		if ( $nth instanceof \WP_Error ) {
			return $nth;
		}

		return array(
			'fields'  => $fields,
			'nth'     => $nth,
			'summary' => self::build_summary( (string) $fields['code'], $nth ),
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
		$nth    = isset( $params['nth'] ) && is_array( $params['nth'] ) ? $params['nth'] : array();

		$coupon = CouponService::save( $fields );
		if ( $coupon instanceof \WP_Error ) {
			return $coupon;
		}
		NthItemMeta::write( $coupon->get_id(), $nth );

		return array(
			'id'    => $coupon->get_id(),
			'reply' => sprintf(
				/* translators: %s: coupon code. */
				__( 'Created Nth-item discount coupon %s.', 'moksafocou' ),
				$coupon->get_code()
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function normalize_nth( array $input ) {
		// Validate the RAW value before sanitize clamps it, so an out-of-range request returns an
		// explicit error to the AI/MCP caller instead of being silently rewritten to N=2.
		if ( (int) ( $input['n'] ?? 0 ) < 2 ) {
			return new \WP_Error( 'moksafocou_nth_bad_n', __( 'N must be at least 2 (from the second item).', 'moksafocou' ) );
		}
		$cfg = NthItemMeta::sanitize( $input );
		if ( 'percent' === $cfg['reward_mode'] && $cfg['reward_value'] > 100 ) {
			return new \WP_Error( 'moksafocou_nth_bad_value', __( 'Percentage discount cannot exceed 100.', 'moksafocou' ) );
		}
		if ( 'fixed_per_item' === $cfg['reward_mode'] && $cfg['reward_value'] <= 0 ) {
			return new \WP_Error( 'moksafocou_nth_bad_value', __( 'Fixed discount per item must be greater than 0.', 'moksafocou' ) );
		}
		return $cfg;
	}

	/**
	 * @param string              $code Coupon code.
	 * @param array<string,mixed> $cfg  Normalized Nth-item config.
	 */
	private static function build_summary( string $code, array $cfg ): string {
		$mode = (string) $cfg['reward_mode'];
		if ( 'free' === $mode ) {
			$reward = __( 'Free', 'moksafocou' );
		} elseif ( 'fixed_per_item' === $mode ) {
			/* translators: %s: per-item discount amount. */
			$reward = sprintf( __( '%s off per item', 'moksafocou' ), (string) $cfg['reward_value'] );
		} else {
			/* translators: %s: discount percent. */
			$reward = sprintf( __( '%s%% off', 'moksafocou' ), (string) $cfg['reward_value'] );
		}
		$repeat = 'repeat' === $cfg['deal_mode'] ? __( '(repeatable)', 'moksafocou' ) : __( '(one time only)', 'moksafocou' );

		return sprintf(
			/* translators: 1: code, 2: N, 3: reward desc, 4: repeat note. */
			__( 'Create an Nth-item discount coupon %1$s: for every %2$d items, the Nth item %3$s %4$s', 'moksafocou' ),
			$code,
			(int) $cfg['n'],
			$reward,
			$repeat
		);
	}
}
