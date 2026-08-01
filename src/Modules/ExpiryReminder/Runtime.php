<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\ExpiryReminder;

use Moksafocou\Modules\MyAccount\CouponQuery;
use Moksafocou\Support\CouponPresenter;

defined( 'ABSPATH' ) || exit;

/**
 * Daily pass: find each customer's personal coupon that expires within the configured window, email
 * the customer a reminder, and stamp the coupon so it's reminded only once.
 */
final class Runtime {

	private const REMINDED_META = '_moksafocou_expiry_reminded';

	/** How many days ahead to look (clamped to a sane range). */
	private static function window_days(): int {
		$days = (int) get_option( 'moksafocou_expiry_days', 3 );
		return ( $days >= 1 && $days <= 60 ) ? $days : 3;
	}

	public static function run(): void {
		$now = time();
		$end = $now + self::window_days() * DAY_IN_SECONDS;

		$ids = get_posts(
			array(
				'post_type'        => 'shop_coupon',
				'post_status'      => 'publish',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- bounded daily cron; caps the reminder scan, never unbounded.
				'numberposts'      => 200,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily cron, bounded result set.
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'     => 'date_expires',
						'value'   => array( $now, $end ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => self::REMINDED_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$coupon  = new \WC_Coupon( $id );
			$expires = $coupon->get_date_expires();
			if ( ! $expires ) {
				continue;
			}
			$email = self::recipient_email( $id, $coupon );
			if ( '' === $email ) {
				continue; // not a personal coupon — nobody specific to remind.
			}
			self::send_reminder( $coupon, $email, $expires->getTimestamp() );
			update_post_meta( $id, self::REMINDED_META, $now );
		}
	}

	/** The customer this coupon belongs to: owner user's email, else its email restriction. */
	private static function recipient_email( int $id, \WC_Coupon $coupon ): string {
		$owner = (int) get_post_meta( $id, CouponQuery::OWNER_META, true );
		if ( $owner > 0 ) {
			$user = get_user_by( 'id', $owner );
			if ( $user && is_email( $user->user_email ) ) {
				return (string) $user->user_email;
			}
		}
		foreach ( (array) $coupon->get_email_restrictions() as $restricted ) {
			if ( is_email( (string) $restricted ) ) {
				return (string) $restricted;
			}
		}
		return '';
	}

	private static function send_reminder( \WC_Coupon $coupon, string $email, int $expires_ts ): bool {
		$code = $coupon->get_code();
		$date = wp_date( (string) get_option( 'date_format' ), $expires_ts );
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$summary = CouponPresenter::summary( $coupon );
		$apply   = CouponPresenter::apply_url( $coupon );

		/* translators: %s: coupon code. */
		$subject = sprintf( __( 'Your coupon "%s" is about to expire', 'moksafocou' ), $code );

		$parts   = array();
		$parts[] = '<p>' . esc_html(
			sprintf(
				/* translators: 1: site name, 2: expiry date. */
				__( 'Reminder: %1$s\'s exclusive coupon expires on %2$s, don\'t forget to use it before the deadline!', 'moksafocou' ),
				$site,
				$date
			)
		) . '</p>';
		/* translators: %s: coupon code. */
		$parts[] = '<p><strong>' . esc_html( sprintf( __( 'Coupon code: %s', 'moksafocou' ), $code ) ) . '</strong></p>';
		if ( '' !== trim( $summary ) ) {
			$parts[] = '<p>' . esc_html( $summary ) . '</p>';
		}
		if ( '' !== $apply ) {
			$parts[] = '<p><a href="' . esc_url( $apply ) . '">' . esc_html__( 'Click here to use it now', 'moksafocou' ) . '</a></p>';
		}

		return (bool) wp_mail(
			$email,
			$subject,
			implode( "\n", $parts ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}
}
