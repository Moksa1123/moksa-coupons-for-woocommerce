<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponCore;

use Moksafocou\Modules\Reports\ReportService;
use Moksafocou\Support\CouponType;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the store's own data into concrete coupon advice — the "AI-first" payoff. suggestions()
 * proposes coupons grounded in real numbers (average order value, slow-moving stock, the current
 * best performer, coupon activity); audit() flags coupons that need attention (expiring unused,
 * expired but still live, over-discounting). Both return machine-readable params so the assistant
 * can act via the create-* abilities.
 */
final class Advisor {

	/**
	 * @return array<int,array{title:string,detail:string,suggest:array<string,mixed>}>
	 */
	public static function suggestions(): array {
		$out = array();
		$ov  = ReportService::overview( 30 );

		// 1) Lift average order value with a spend-threshold coupon just above the current AOV.
		$aov = (float) $ov['avg_order_value'];
		if ( $aov > 0.0 ) {
			$threshold = max( 100, (int) ( ceil( ( $aov * 1.25 ) / 100 ) * 100 ) );
			$reward    = max( 1, (int) round( $threshold * 0.1 ) );
			$out[]     = array(
				'title'   => __( 'Raise average order value with a spend-threshold coupon', 'moksa-coupons-for-woocommerce' ),
				'detail'  => sprintf(
					/* translators: 1: AOV, 2: threshold, 3: reward. */
					__( 'Coupon-backed average order value over the last 30 days is about %1$s. Set "spend %2$s, get %3$s off" to push most orders up a tier.', 'moksa-coupons-for-woocommerce' ),
					self::money( $aov ),
					self::money( (float) $threshold ),
					self::money( (float) $reward )
				),
				'suggest' => array(
					'discount_type'  => 'fixed_cart',
					'amount'         => $reward,
					'minimum_amount' => $threshold,
				),
			);
		}

		// 2) Duplicate / extend the current best performer.
		$rows = ReportService::compute();
		if ( array() !== $rows && (float) $rows[0]['discount'] > 0.0 ) {
			$top   = $rows[0];
			$out[] = array(
				'title'   => __( 'Extend / copy the currently most effective coupon', 'moksa-coupons-for-woocommerce' ),
				'detail'  => sprintf(
					/* translators: 1: code, 2: type, 3: orders, 4: discount. */
					__( '"%1$s" (%2$s) has driven %3$d order(s) and %4$s in discounts, and is currently the most effective coupon. You can extend its expiry or copy it into a new campaign.', 'moksa-coupons-for-woocommerce' ),
					(string) $top['code'],
					CouponType::label( (string) $top['type'] ),
					(int) $top['orders'],
					self::money( (float) $top['discount'] )
				),
				'suggest' => array( 'duplicate_code' => (string) $top['code'] ),
			);
		}

		// 3) Move slow stock with a targeted coupon.
		$slow = self::slow_movers( 5 );
		if ( array() !== $slow ) {
			$names = array();
			foreach ( $slow as $p ) {
				$names[] = $p['name'];
			}
			$out[] = array(
				'title'   => __( 'Clear out slow-moving products', 'moksa-coupons-for-woocommerce' ),
				'detail'  => sprintf(
					/* translators: %s: comma-separated product names. */
					__( 'These products have had no sales in the last 60 days: %s. You could run "20% off specific products" or "buy one get one" for them to drive sales.', 'moksa-coupons-for-woocommerce' ),
					implode( '、', array_slice( $names, 0, 5 ) )
				),
				'suggest' => array(
					'discount_type' => 'percent',
					'amount'        => 20,
					'product_ids'   => array_map( static fn( array $p ): int => (int) $p['id'], $slow ),
				),
			);
		}

		// 4) Acquisition push when coupon activity is low.
		if ( (int) $ov['coupon_orders'] < 3 ) {
			$out[] = array(
				'title'   => __( 'Launch a new-customer first-purchase coupon', 'moksa-coupons-for-woocommerce' ),
				'detail'  => __( 'Coupon-backed orders in the last 30 days are on the low side. Issue a new-customer first-purchase coupon (e.g. "10% off first purchase") together with the front-end coupon wall / auto-apply to attract new customers.', 'moksa-coupons-for-woocommerce' ),
				'suggest' => array(
					'discount_type' => 'percent',
					'amount'        => 10,
				),
			);
		}

		return $out;
	}

	/**
	 * @return array<int,array{code:string,issue:string,detail:string}>
	 */
	public static function audit(): array {
		$issues = array();
		$now    = time();
		$ids    = get_posts(
			array(
				'post_type'   => 'shop_coupon',
				'post_status' => array( 'publish', 'draft' ),
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- bounded one-off admin audit; caps the scan, never unbounded.
				'numberposts' => 300,
				'fields'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'DESC',
			)
		);
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$coupon  = new \WC_Coupon( $id );
			$code    = $coupon->get_code();
			$expires = $coupon->get_date_expires();
			$exp_ts  = $expires ? $expires->getTimestamp() : 0;
			$used    = (int) $coupon->get_usage_count();

			if ( $exp_ts > 0 && $exp_ts < $now && 'publish' === get_post_status( $id ) ) {
				$issues[] = array(
					'code'   => $code,
					'issue'  => 'expired_live',
					'detail' => __( 'Expired but still enabled; consider disabling or cleaning up.', 'moksa-coupons-for-woocommerce' ),
				);
				continue;
			}
			if ( $exp_ts > $now && $exp_ts < $now + 7 * DAY_IN_SECONDS && 0 === $used ) {
				$issues[] = array(
					'code'   => $code,
					'issue'  => 'expiring_unused',
					'detail' => __( 'Expires within 7 days but has never been used; consider boosting exposure or adjusting the threshold.', 'moksa-coupons-for-woocommerce' ),
				);
				continue;
			}
			if ( 'percent' === $coupon->get_discount_type() && (float) $coupon->get_amount() >= 50.0 ) {
				$issues[] = array(
					'code'   => $code,
					'issue'  => 'over_discount',
					'detail' => sprintf(
						/* translators: %s: percent. */
						__( 'The percentage discount is as high as %s%%; confirm your margin can absorb it.', 'moksa-coupons-for-woocommerce' ),
						rtrim( rtrim( number_format( (float) $coupon->get_amount(), 2, '.', '' ), '0' ), '.' )
					),
				);
			}
		}
		return $issues;
	}

	/**
	 * Published, in-stock products with no sales in the last 60 days (paid orders), capped.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private static function slow_movers( int $limit ): array {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$sold   = array();
		$orders = wc_get_orders(
			array(
				'status'       => array( 'completed', 'processing' ),
				'type'         => 'shop_order',
				'limit'        => 300,
				'date_created' => '>=' . ( time() - 60 * DAY_IN_SECONDS ),
				'return'       => 'objects',
			)
		);
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product ) {
					$sold[ (int) $item->get_product_id() ] = true;
				}
			}
		}

		$products = wc_get_products(
			array(
				'status'       => 'publish',
				'stock_status' => 'instock',
				'limit'        => 50,
				'orderby'      => 'date',
				'order'        => 'DESC',
			)
		);
		$out      = array();
		foreach ( $products as $p ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( ! isset( $sold[ $p->get_id() ] ) ) {
				$out[] = array(
					'id'   => (int) $p->get_id(),
					'name' => (string) $p->get_name(),
				);
			}
		}
		return $out;
	}

	private static function money( float $amount ): string {
		return function_exists( 'wc_price' )
			? html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' )
			: (string) $amount;
	}
}
