<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates coupon performance from paid orders (per-coupon order count + total
 * discount given), merged with each coupon's current meta. The pure accumulator
 * (aggregate) is unit-tested; compute() scans the order store and caches the result.
 */
final class ReportService {

	private const CACHE_KEY  = 'moksafocou_report_cache';
	private const VER_OPTION = 'moksafocou_report_ver';

	/** Cache-buster shared by overview()/by_campaign(); bumped on flush() so all variants lapse at once. */
	private static function ver(): int {
		return (int) get_option( self::VER_OPTION, 0 );
	}

	/**
	 * Pure: fold a flat list of coupon lines into per-code totals.
	 *
	 * @param array<int,array{code:string,discount:float}> $lines
	 * @return array<string,array{orders:int,discount:float}>
	 */
	public static function aggregate( array $lines ): array {
		$out = array();
		foreach ( $lines as $line ) {
			$code = strtolower( trim( (string) ( $line['code'] ?? '' ) ) );
			if ( '' === $code ) {
				continue;
			}
			if ( ! isset( $out[ $code ] ) ) {
				$out[ $code ] = array(
					'orders'   => 0,
					'discount' => 0.0,
				);
			}
			++$out[ $code ]['orders'];
			$out[ $code ]['discount'] += (float) ( $line['discount'] ?? 0 );
		}
		return $out;
	}

	/**
	 * Per-coupon performance rows, sorted by discount given (desc). Cached for an hour;
	 * pass $force = true to recompute.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function compute( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$lines = array();
		if ( function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_is_paid_statuses' ) ) {
			$orders = wc_get_orders(
				array(
					'status' => wc_get_is_paid_statuses(),
					'type'   => 'shop_order',
					'limit'  => -1,
					'return' => 'objects',
				)
			);
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				foreach ( $order->get_items( 'coupon' ) as $item ) {
					if ( $item instanceof \WC_Order_Item_Coupon ) {
						$lines[] = array(
							'code'     => (string) $item->get_code(),
							'discount' => (float) $item->get_discount(),
						);
					}
				}
			}
		}

		$aggregated = self::aggregate( $lines );

		// Resolve every code → id in ONE query and prime the meta cache for the matched
		// coupons, so decorate() reads from cache rather than issuing a query per code (N+1).
		$id_map = self::code_id_map();
		$ids    = array();
		foreach ( array_keys( $aggregated ) as $code ) {
			$id = $id_map[ strtolower( $code ) ] ?? 0;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		if ( array() !== $ids && function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $ids );
		}

		$rows = array();
		foreach ( $aggregated as $code => $stat ) {
			$rows[] = self::decorate( $code, $stat, $id_map );
		}
		usort( $rows, static fn( array $a, array $b ): int => $b['discount'] <=> $a['discount'] );

		set_transient( self::CACHE_KEY, $rows, HOUR_IN_SECONDS );
		return $rows;
	}

	/**
	 * Revenue + daily trend from coupon-bearing paid orders over the last N days. Answers
	 * "how much business did coupons drive?" — surfaced on the reports page and as an ability.
	 *
	 * @return array{days:int,coupon_orders:int,coupon_revenue:float,total_discount:float,avg_order_value:float,daily:array<int,array{date:string,orders:int,discount:float,revenue:float}>}
	 */
	public static function overview( int $days = 30 ): array {
		$days = ( $days >= 1 && $days <= 365 ) ? $days : 30;

		// Cache the full-order scan: this is exposed via an ability / MCP, so repeated calls
		// must not each fire a limit=-1 order query. Version-keyed so flush() lapses it at once.
		$cache_key = 'moksafocou_report_overview_' . self::ver() . '_' . $days;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$daily          = array();
		$coupon_orders  = 0;
		$coupon_revenue = 0.0;
		$total_discount = 0.0;

		if ( function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_is_paid_statuses' ) ) {
			$orders = wc_get_orders(
				array(
					'status'       => wc_get_is_paid_statuses(),
					'type'         => 'shop_order',
					'limit'        => -1,
					'date_created' => '>=' . ( time() - $days * DAY_IN_SECONDS ),
					'return'       => 'objects',
				)
			);
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				$coupons = $order->get_items( 'coupon' );
				if ( array() === $coupons ) {
					continue;
				}
				$discount = 0.0;
				foreach ( $coupons as $item ) {
					if ( $item instanceof \WC_Order_Item_Coupon ) {
						$discount += (float) $item->get_discount();
					}
				}
				$created = $order->get_date_created();
				$key     = $created ? $created->date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
				if ( ! isset( $daily[ $key ] ) ) {
					$daily[ $key ] = array(
						'date'     => $key,
						'orders'   => 0,
						'discount' => 0.0,
						'revenue'  => 0.0,
					);
				}
				++$daily[ $key ]['orders'];
				$daily[ $key ]['discount'] += $discount;
				$daily[ $key ]['revenue']  += (float) $order->get_total();

				++$coupon_orders;
				$coupon_revenue += (float) $order->get_total();
				$total_discount += $discount;
			}
		}
		ksort( $daily );

		$result = array(
			'days'            => $days,
			'coupon_orders'   => $coupon_orders,
			'coupon_revenue'  => round( $coupon_revenue, 2 ),
			'total_discount'  => round( $total_discount, 2 ),
			'avg_order_value' => $coupon_orders > 0 ? round( $coupon_revenue / $coupon_orders, 2 ) : 0.0,
			'daily'           => array_values( $daily ),
		);
		set_transient( $cache_key, $result, HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Per-campaign rollup of coupon performance, for coupons tagged with a campaign. An order is
	 * counted once per campaign it touches; discount sums only that campaign's coupon lines.
	 *
	 * @return array<int,array{campaign:string,coupons:int,orders:int,discount:float,revenue:float}>
	 */
	public static function by_campaign(): array {
		$code_campaign = self::code_campaign_map();
		if ( array() === $code_campaign ) {
			return array();
		}

		// Cache (version-keyed, exposed via ability/MCP) + bound the scan to a recent window so an
		// unbounded order table can't be walked on every call.
		$window_days = max( 1, (int) apply_filters( 'moksafocou_campaign_report_days', 365 ) );
		$cache_key   = 'moksafocou_report_campaign_' . self::ver() . '_' . $window_days;
		$cached      = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$camps = array();
		if ( function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_is_paid_statuses' ) ) {
			$orders = wc_get_orders(
				array(
					'status'       => wc_get_is_paid_statuses(),
					'type'         => 'shop_order',
					'limit'        => -1,
					'date_created' => '>=' . ( time() - $window_days * DAY_IN_SECONDS ),
					'return'       => 'objects',
				)
			);
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				$order_total = (float) $order->get_total();
				$seen        = array();
				foreach ( $order->get_items( 'coupon' ) as $item ) {
					if ( ! $item instanceof \WC_Order_Item_Coupon ) {
						continue;
					}
					$campaign = $code_campaign[ strtolower( (string) $item->get_code() ) ] ?? '';
					if ( '' === $campaign ) {
						continue;
					}
					if ( ! isset( $camps[ $campaign ] ) ) {
						$camps[ $campaign ] = array(
							'campaign' => $campaign,
							'coupons'  => array(),
							'orders'   => 0,
							'discount' => 0.0,
							'revenue'  => 0.0,
						);
					}
					$camps[ $campaign ]['coupons'][ strtolower( (string) $item->get_code() ) ] = true;
					$camps[ $campaign ]['discount'] += (float) $item->get_discount();
					if ( ! isset( $seen[ $campaign ] ) ) {
						++$camps[ $campaign ]['orders'];
						$camps[ $campaign ]['revenue'] += $order_total;
						$seen[ $campaign ]              = true;
					}
				}
			}
		}

		$rows = array();
		foreach ( $camps as $c ) {
			$rows[] = array(
				'campaign' => $c['campaign'],
				'coupons'  => count( $c['coupons'] ),
				'orders'   => $c['orders'],
				'discount' => round( $c['discount'], 2 ),
				'revenue'  => round( $c['revenue'], 2 ),
			);
		}
		usort( $rows, static fn( array $a, array $b ): int => $b['discount'] <=> $a['discount'] );
		set_transient( $cache_key, $rows, HOUR_IN_SECONDS );
		return $rows;
	}

	/**
	 * code (lowercased) => campaign tag, for every coupon that carries a non-empty campaign.
	 *
	 * @return array<string,string>
	 */
	private static function code_campaign_map(): array {
		$map = array();
		$ids = get_posts(
			array(
				'post_type'   => 'shop_coupon',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin report, runs behind the hourly cache.
				'meta_query'  => array(
					array(
						'key'     => \Moksafocou\Coupon\Meta\Keys::CAMPAIGN,
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$code     = (string) get_the_title( $id );
			$campaign = trim( (string) get_post_meta( $id, \Moksafocou\Coupon\Meta\Keys::CAMPAIGN, true ) );
			if ( '' !== $code && '' !== $campaign ) {
				$map[ strtolower( $code ) ] = $campaign;
			}
		}
		return $map;
	}

	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
		// Bump the shared version so every per-days overview()/by_campaign() transient lapses
		// at once (we can't enumerate the per-window keys to delete them individually).
		update_option( self::VER_OPTION, self::ver() + 1, false );
	}

	/**
	 * code (lowercased) => coupon id, built with a single query that loads no meta or terms.
	 * Replaces per-code wc_get_coupon_id_by_code() calls in the decorate loop.
	 *
	 * @return array<string,int>
	 */
	private static function code_id_map(): array {
		$map = array();
		if ( ! class_exists( '\WP_Query' ) ) {
			return $map;
		}
		$query = new \WP_Query(
			array(
				'post_type'              => 'shop_coupon',
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$map[ strtolower( (string) $post->post_title ) ] = (int) $post->ID;
			}
		}
		return $map;
	}

	/**
	 * Join the aggregated stat with the coupon's live meta (type, amount, status,
	 * usage, expiry). A used-then-deleted coupon still shows its stats (id 0).
	 *
	 * @param string                           $code   Coupon code.
	 * @param array{orders:int,discount:float} $stat   Aggregated stat.
	 * @param array<string,int>                $id_map Pre-resolved code (lowercased) => id map.
	 * @return array<string,mixed>
	 */
	private static function decorate( string $code, array $stat, array $id_map ): array {
		$row = array(
			'code'        => $code,
			'orders'      => (int) $stat['orders'],
			'discount'    => (float) $stat['discount'],
			'type'        => '',
			'amount'      => '',
			'status'      => 'deleted',
			'usage_count' => 0,
			'usage_limit' => 0,
			'expires'     => '',
		);
		$id  = $id_map[ strtolower( $code ) ] ?? 0;
		if ( $id > 0 ) {
			$coupon             = new \WC_Coupon( $id );
			$row['type']        = (string) $coupon->get_discount_type();
			$row['amount']      = (string) $coupon->get_amount();
			$row['status']      = (string) get_post_status( $id );
			$row['usage_count'] = (int) $coupon->get_usage_count();
			$row['usage_limit'] = (int) $coupon->get_usage_limit();
			$expires            = $coupon->get_date_expires();
			$row['expires']     = $expires ? $expires->date( 'Y-m-d' ) : '';
		}
		/**
		 * Filter a single coupon performance row, e.g. to attach custom metrics.
		 *
		 * @param array<string,mixed> $row  The report row.
		 * @param string              $code Coupon code.
		 * @param int                 $id   Coupon post id (0 if deleted).
		 */
		return (array) apply_filters( 'moksafocou_report_row', $row, $code, $id );
	}
}
