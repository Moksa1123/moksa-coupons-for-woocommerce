<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Frontend;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\DiscountTypeRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the public "available coupons" card list. The advertisability test
 * (is_advertisable) and percent formatting (percent_display) are pure and
 * unit-tested; query() / cards_html() touch WooCommerce + WordPress.
 */
final class Catalog {

	/**
	 * Whether a coupon may be advertised on the public list: published, opted-in, and
	 * not past its (exclusive) validity end.
	 *
	 * @param string   $status      Post status.
	 * @param string   $show        Opt-in meta ('yes' to advertise).
	 * @param int|null $valid_until Epoch the coupon stays valid until (exclusive), or null.
	 * @param int      $now         Current epoch.
	 */
	public static function is_advertisable( string $status, string $show, ?int $valid_until, int $now ): bool {
		if ( 'publish' !== $status || 'yes' !== $show ) {
			return false;
		}
		return null === $valid_until || $now < $valid_until;
	}

	/** Format a percent amount without trailing zeros (15.00 → "15", 12.50 → "12.5"). */
	public static function percent_display( float $amount ): string {
		$value = number_format( $amount, 2, '.', '' );
		$value = rtrim( rtrim( $value, '0' ), '.' );
		return '' === $value ? '0' : $value;
	}

	/** Stable type key (for badge class + icon), independent of locale. */
	public static function type_key( string $type ): string {
		return DiscountTypeRegistry::type_key( $type );
	}

	/**
	 * Published, opted-in coupons for the public list (newest first).
	 *
	 * @return array<int,\WC_Coupon>
	 */
	public static function query( int $limit = 20 ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 50, $limit ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded admin-curated list.
					array(
						'key'   => Keys::SHOW_IN_LIST,
						'value' => 'yes',
					),
				),
			)
		);

		$now    = time();
		$result = array();
		foreach ( $ids as $id ) {
			$coupon      = new \WC_Coupon( (int) $id );
			$expires     = $coupon->get_date_expires();
			$valid_until = $expires ? $expires->getTimestamp() + DAY_IN_SECONDS : null;
			if ( self::is_advertisable( get_post_status( (int) $id ) ?: '', 'yes', $valid_until, $now ) ) {
				$result[] = $coupon;
			}
		}
		return $result;
	}
}
