<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Bogo;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\MetaListFields;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for reading / sanitizing / writing a coupon's BOGO config.
 * Used by BOTH the admin panel (Fields::save) and the AI ability (BogoOps::create_apply)
 * so the two write paths can never drift. Each field maps to one flat _moksafocou_bogo_*
 * meta key; empty values are deleted (not blank-written) to keep Keys::all() cleanup tidy.
 */
final class BogoMeta {

	use MetaListFields;

	public const TYPE = 'moksafocou_bogo';

	/**
	 * Normalized config for the engine / UI.
	 *
	 * @return array{trigger_product_ids:array<int,int>,trigger_category_ids:array<int,int>,trigger_qty:int,reward_product_ids:array<int,int>,reward_category_ids:array<int,int>,reward_qty:int,reward_mode:string,reward_value:float,deal_mode:string,repeat_limit:int,notice_msg:string}
	 */
	public static function read( int $coupon_id ): array {
		return array(
			'trigger_product_ids'  => self::ids( get_post_meta( $coupon_id, Keys::BOGO_TRIGGER_PRODUCT_IDS, true ) ),
			'trigger_category_ids' => self::ids( get_post_meta( $coupon_id, Keys::BOGO_TRIGGER_CATEGORY_IDS, true ) ),
			'trigger_qty'          => max( 1, (int) get_post_meta( $coupon_id, Keys::BOGO_TRIGGER_QTY, true ) ),
			'reward_product_ids'   => self::ids( get_post_meta( $coupon_id, Keys::BOGO_REWARD_PRODUCT_IDS, true ) ),
			'reward_category_ids'  => self::ids( get_post_meta( $coupon_id, Keys::BOGO_REWARD_CATEGORY_IDS, true ) ),
			'reward_qty'           => max( 1, (int) get_post_meta( $coupon_id, Keys::BOGO_REWARD_QTY, true ) ),
			'reward_mode'          => self::mode( (string) get_post_meta( $coupon_id, Keys::BOGO_REWARD_MODE, true ) ),
			'reward_value'         => (float) get_post_meta( $coupon_id, Keys::BOGO_REWARD_VALUE, true ),
			'deal_mode'            => 'repeat' === get_post_meta( $coupon_id, Keys::BOGO_DEAL_MODE, true ) ? 'repeat' : 'once',
			'repeat_limit'         => max( 0, (int) get_post_meta( $coupon_id, Keys::BOGO_REPEAT_LIMIT, true ) ),
			'notice_msg'           => (string) get_post_meta( $coupon_id, Keys::BOGO_NOTICE_MSG, true ),
		);
	}

	/**
	 * Coerce a raw input array (admin POST or ability args) into a clean config.
	 * Pure coercion — no validation errors (BogoOps validates separately).
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $raw ): array {
		return array(
			'trigger_product_ids'  => self::ids( $raw['trigger_product_ids'] ?? array() ),
			'trigger_category_ids' => self::ids( $raw['trigger_category_ids'] ?? array() ),
			'trigger_qty'          => max( 1, (int) ( $raw['trigger_qty'] ?? 1 ) ),
			'reward_product_ids'   => self::ids( $raw['reward_product_ids'] ?? array() ),
			'reward_category_ids'  => self::ids( $raw['reward_category_ids'] ?? array() ),
			'reward_qty'           => max( 1, (int) ( $raw['reward_qty'] ?? 1 ) ),
			'reward_mode'          => self::mode( (string) ( $raw['reward_mode'] ?? 'percent' ) ),
			'reward_value'         => max( 0.0, (float) ( $raw['reward_value'] ?? 0 ) ),
			'deal_mode'            => 'repeat' === ( $raw['deal_mode'] ?? 'once' ) ? 'repeat' : 'once',
			'repeat_limit'         => max( 0, (int) ( $raw['repeat_limit'] ?? 0 ) ),
			'notice_msg'           => sanitize_text_field( (string) ( $raw['notice_msg'] ?? '' ) ),
		);
	}

	/**
	 * Persist a sanitized config. Empty list / blank scalar → delete the meta.
	 *
	 * @param int                 $coupon_id Coupon post ID.
	 * @param array<string,mixed> $cfg       Output of sanitize() / read().
	 */
	public static function write( int $coupon_id, array $cfg ): void {
		self::put_ids( $coupon_id, Keys::BOGO_TRIGGER_PRODUCT_IDS, $cfg['trigger_product_ids'] ?? array() );
		self::put_ids( $coupon_id, Keys::BOGO_TRIGGER_CATEGORY_IDS, $cfg['trigger_category_ids'] ?? array() );
		update_post_meta( $coupon_id, Keys::BOGO_TRIGGER_QTY, max( 1, (int) ( $cfg['trigger_qty'] ?? 1 ) ) );
		self::put_ids( $coupon_id, Keys::BOGO_REWARD_PRODUCT_IDS, $cfg['reward_product_ids'] ?? array() );
		self::put_ids( $coupon_id, Keys::BOGO_REWARD_CATEGORY_IDS, $cfg['reward_category_ids'] ?? array() );
		update_post_meta( $coupon_id, Keys::BOGO_REWARD_QTY, max( 1, (int) ( $cfg['reward_qty'] ?? 1 ) ) );
		update_post_meta( $coupon_id, Keys::BOGO_REWARD_MODE, self::mode( (string) ( $cfg['reward_mode'] ?? 'percent' ) ) );
		update_post_meta( $coupon_id, Keys::BOGO_REWARD_VALUE, (string) (float) ( $cfg['reward_value'] ?? 0 ) );
		update_post_meta( $coupon_id, Keys::BOGO_DEAL_MODE, 'repeat' === ( $cfg['deal_mode'] ?? 'once' ) ? 'repeat' : 'once' );
		update_post_meta( $coupon_id, Keys::BOGO_REPEAT_LIMIT, max( 0, (int) ( $cfg['repeat_limit'] ?? 0 ) ) );
		self::put_text( $coupon_id, Keys::BOGO_NOTICE_MSG, (string) ( $cfg['notice_msg'] ?? '' ) );
	}

	private static function mode( string $mode ): string {
		return in_array( $mode, BogoCalc::MODES, true ) ? $mode : 'percent';
	}
}
