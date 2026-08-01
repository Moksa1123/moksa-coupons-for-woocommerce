<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\NthItem;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\MetaListFields;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for reading / sanitizing / writing a coupon's Nth-item config. Used by
 * BOTH the admin panel (Fields::save) and the AI ability (NthItemOps) so the two write paths can
 * never drift. Each field maps to one flat _moksafocou_nth_* meta key; empty values are deleted.
 */
final class NthItemMeta {

	use MetaListFields;

	public const TYPE = 'moksafocou_nth_item';

	/**
	 * @return array{product_ids:array<int,int>,category_ids:array<int,int>,group_by:string,n:int,reward_mode:string,reward_value:float,deal_mode:string,repeat_limit:int,notice_msg:string}
	 */
	public static function read( int $coupon_id ): array {
		return array(
			'product_ids'  => self::ids( get_post_meta( $coupon_id, Keys::NTH_PRODUCT_IDS, true ) ),
			'category_ids' => self::ids( get_post_meta( $coupon_id, Keys::NTH_CATEGORY_IDS, true ) ),
			'group_by'     => self::group_by( (string) get_post_meta( $coupon_id, Keys::NTH_GROUP_BY, true ) ),
			'n'            => max( 2, (int) get_post_meta( $coupon_id, Keys::NTH_N, true ) ),
			'reward_mode'  => self::mode( (string) get_post_meta( $coupon_id, Keys::NTH_REWARD_MODE, true ) ),
			'reward_value' => (float) get_post_meta( $coupon_id, Keys::NTH_REWARD_VALUE, true ),
			'deal_mode'    => 'once' === get_post_meta( $coupon_id, Keys::NTH_DEAL_MODE, true ) ? 'once' : 'repeat',
			'repeat_limit' => max( 0, (int) get_post_meta( $coupon_id, Keys::NTH_REPEAT_LIMIT, true ) ),
			'notice_msg'   => (string) get_post_meta( $coupon_id, Keys::NTH_NOTICE_MSG, true ),
		);
	}

	/**
	 * Coerce a raw input array (admin POST or ability args) into a clean config. Pure coercion —
	 * NthItemOps validates separately.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $raw ): array {
		return array(
			'product_ids'  => self::ids( $raw['product_ids'] ?? array() ),
			'category_ids' => self::ids( $raw['category_ids'] ?? array() ),
			'group_by'     => self::group_by( (string) ( $raw['group_by'] ?? 'cart' ) ),
			'n'            => max( 2, (int) ( $raw['n'] ?? 2 ) ),
			'reward_mode'  => self::mode( (string) ( $raw['reward_mode'] ?? 'percent' ) ),
			'reward_value' => max( 0.0, (float) ( $raw['reward_value'] ?? 0 ) ),
			'deal_mode'    => 'once' === ( $raw['deal_mode'] ?? 'repeat' ) ? 'once' : 'repeat',
			'repeat_limit' => max( 0, (int) ( $raw['repeat_limit'] ?? 0 ) ),
			'notice_msg'   => sanitize_text_field( (string) ( $raw['notice_msg'] ?? '' ) ),
		);
	}

	/**
	 * Persist a sanitized config. Empty list / blank scalar → delete the meta.
	 *
	 * @param int                 $coupon_id Coupon post ID.
	 * @param array<string,mixed> $cfg       Output of sanitize() / read().
	 */
	public static function write( int $coupon_id, array $cfg ): void {
		self::put_ids( $coupon_id, Keys::NTH_PRODUCT_IDS, $cfg['product_ids'] ?? array() );
		self::put_ids( $coupon_id, Keys::NTH_CATEGORY_IDS, $cfg['category_ids'] ?? array() );
		update_post_meta( $coupon_id, Keys::NTH_GROUP_BY, self::group_by( (string) ( $cfg['group_by'] ?? 'cart' ) ) );
		update_post_meta( $coupon_id, Keys::NTH_N, max( 2, (int) ( $cfg['n'] ?? 2 ) ) );
		update_post_meta( $coupon_id, Keys::NTH_REWARD_MODE, self::mode( (string) ( $cfg['reward_mode'] ?? 'percent' ) ) );
		update_post_meta( $coupon_id, Keys::NTH_REWARD_VALUE, (string) (float) ( $cfg['reward_value'] ?? 0 ) );
		update_post_meta( $coupon_id, Keys::NTH_DEAL_MODE, 'once' === ( $cfg['deal_mode'] ?? 'repeat' ) ? 'once' : 'repeat' );
		update_post_meta( $coupon_id, Keys::NTH_REPEAT_LIMIT, max( 0, (int) ( $cfg['repeat_limit'] ?? 0 ) ) );
		self::put_text( $coupon_id, Keys::NTH_NOTICE_MSG, (string) ( $cfg['notice_msg'] ?? '' ) );
	}

	private static function mode( string $mode ): string {
		return in_array( $mode, NthItemCalc::MODES, true ) ? $mode : 'percent';
	}

	private static function group_by( string $group_by ): string {
		return 'product' === $group_by ? 'product' : 'cart';
	}
}
