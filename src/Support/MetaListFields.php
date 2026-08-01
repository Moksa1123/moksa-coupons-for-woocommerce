<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Shared list-field meta helpers for the special-price *Meta value objects (BogoMeta,
 * MixMatchMeta, NthItemMeta), which each mapped an identical trio: normalise a raw value into a
 * de-duplicated positive-int ID list, and write an ID list / a text value to post meta (empty →
 * delete, never blank-write, to keep Keys::all() cleanup tidy). The three copies were byte-for-byte
 * identical; centralising them keeps the sanitise + write semantics the same for every module.
 */
trait MetaListFields {

	/**
	 * Normalise a raw meta value into a de-duplicated list of positive integer IDs.
	 *
	 * @param mixed $value Raw stored/posted value (array, scalar, '' or null).
	 * @return array<int,int>
	 */
	public static function ids( $value ): array {
		$value = is_array( $value ) ? $value : ( '' === $value || null === $value ? array() : array( $value ) );
		$ids   = array_map( static fn( $v ): int => max( 0, (int) $v ), $value );
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Write an ID list to post meta; an empty list deletes the key rather than storing '[]'.
	 *
	 * @param int            $coupon_id
	 * @param string         $key
	 * @param array<int,int> $ids
	 */
	private static function put_ids( int $coupon_id, string $key, array $ids ): void {
		if ( array() === $ids ) {
			delete_post_meta( $coupon_id, $key );
		} else {
			update_post_meta( $coupon_id, $key, array_values( $ids ) );
		}
	}

	/** Write a trimmed text value to post meta; an empty string deletes the key. */
	private static function put_text( int $coupon_id, string $key, string $value ): void {
		$value = trim( $value );
		if ( '' === $value ) {
			delete_post_meta( $coupon_id, $key );
		} else {
			update_post_meta( $coupon_id, $key, $value );
		}
	}
}
