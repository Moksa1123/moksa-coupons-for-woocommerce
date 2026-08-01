<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\MixMatch;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\MetaListFields;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for reading / sanitizing / writing a coupon's mix & match config. Used by
 * BOTH the admin panel (Fields::save) and the AI ability (MixMatchOps) so the two write paths can
 * never drift. Each field maps to one flat _moksafocou_mixmatch_* meta key; empty values deleted.
 */
final class MixMatchMeta {

	use MetaListFields;

	public const TYPE = 'moksafocou_mixmatch';

	/**
	 * @return array{product_ids:array<int,int>,category_ids:array<int,int>,qty:int,price_mode:string,price_value:float,deal_mode:string,repeat_limit:int,notice_msg:string}
	 */
	public static function read( int $coupon_id ): array {
		return array(
			'product_ids'  => self::ids( get_post_meta( $coupon_id, Keys::MIXMATCH_PRODUCT_IDS, true ) ),
			'category_ids' => self::ids( get_post_meta( $coupon_id, Keys::MIXMATCH_CATEGORY_IDS, true ) ),
			'qty'          => max( 1, (int) get_post_meta( $coupon_id, Keys::MIXMATCH_QTY, true ) ),
			'price_mode'   => self::mode( (string) get_post_meta( $coupon_id, Keys::MIXMATCH_PRICE_MODE, true ) ),
			'price_value'  => (float) get_post_meta( $coupon_id, Keys::MIXMATCH_PRICE_VALUE, true ),
			'deal_mode'    => 'once' === get_post_meta( $coupon_id, Keys::MIXMATCH_DEAL_MODE, true ) ? 'once' : 'repeat',
			'repeat_limit' => max( 0, (int) get_post_meta( $coupon_id, Keys::MIXMATCH_REPEAT_LIMIT, true ) ),
			'notice_msg'   => (string) get_post_meta( $coupon_id, Keys::MIXMATCH_NOTICE_MSG, true ),
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $raw ): array {
		return array(
			'product_ids'  => self::ids( $raw['product_ids'] ?? array() ),
			'category_ids' => self::ids( $raw['category_ids'] ?? array() ),
			'qty'          => max( 1, (int) ( $raw['qty'] ?? 1 ) ),
			'price_mode'   => self::mode( (string) ( $raw['price_mode'] ?? 'fixed_total' ) ),
			'price_value'  => max( 0.0, (float) ( $raw['price_value'] ?? 0 ) ),
			'deal_mode'    => 'once' === ( $raw['deal_mode'] ?? 'repeat' ) ? 'once' : 'repeat',
			'repeat_limit' => max( 0, (int) ( $raw['repeat_limit'] ?? 0 ) ),
			'notice_msg'   => sanitize_textarea_field( (string) ( $raw['notice_msg'] ?? '' ) ),
		);
	}

	/**
	 * @param int                 $coupon_id Coupon post ID.
	 * @param array<string,mixed> $cfg       Output of sanitize() / read().
	 */
	public static function write( int $coupon_id, array $cfg ): void {
		self::put_ids( $coupon_id, Keys::MIXMATCH_PRODUCT_IDS, $cfg['product_ids'] ?? array() );
		self::put_ids( $coupon_id, Keys::MIXMATCH_CATEGORY_IDS, $cfg['category_ids'] ?? array() );
		update_post_meta( $coupon_id, Keys::MIXMATCH_QTY, max( 1, (int) ( $cfg['qty'] ?? 1 ) ) );
		update_post_meta( $coupon_id, Keys::MIXMATCH_PRICE_MODE, self::mode( (string) ( $cfg['price_mode'] ?? 'fixed_total' ) ) );
		update_post_meta( $coupon_id, Keys::MIXMATCH_PRICE_VALUE, (string) (float) ( $cfg['price_value'] ?? 0 ) );
		update_post_meta( $coupon_id, Keys::MIXMATCH_DEAL_MODE, 'once' === ( $cfg['deal_mode'] ?? 'repeat' ) ? 'once' : 'repeat' );
		update_post_meta( $coupon_id, Keys::MIXMATCH_REPEAT_LIMIT, max( 0, (int) ( $cfg['repeat_limit'] ?? 0 ) ) );
		self::put_text( $coupon_id, Keys::MIXMATCH_NOTICE_MSG, (string) ( $cfg['notice_msg'] ?? '' ) );
	}

	private static function mode( string $mode ): string {
		return in_array( $mode, MixMatchCalc::PRICE_MODES, true ) ? $mode : 'fixed_total';
	}
}
