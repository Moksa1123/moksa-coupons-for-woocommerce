<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers for the coupon-card urgency UI: pick the countdown target, compute the
 * remaining-redemptions badge, and decide what to show. No WordPress / WooCommerce
 * dependency — the caller resolves coupon dates to epochs and passes them in, so all the
 * decisions here are fully unit-tested.
 *
 * This layer is DISPLAY-ONLY: "limited stock" enforcement is WooCommerce's native
 * usage_limit (it already blocks redemption once exhausted, classic + Store API); we only
 * visualise `usage_limit - usage_count` and the time left.
 */
final class Urgency {

	/** @var array<int,string> Where the countdown deadline comes from. */
	public const SOURCES = array( 'expires', 'schedule' );

	/** Normalise a countdown-source string to one of SOURCES (defaults to expires). */
	public static function source( $raw ): string {
		$raw = is_string( $raw ) ? $raw : '';
		return in_array( $raw, self::SOURCES, true ) ? $raw : 'expires';
	}

	/**
	 * The countdown target epoch for a coupon, given its two candidate deadlines (both
	 * already resolved to epochs by the caller). 'schedule' uses the schedule-end epoch,
	 * anything else uses the coupon's expiry epoch. Returns null when the chosen source has
	 * no (future-or-otherwise) timestamp.
	 *
	 * @param string   $source          'expires' | 'schedule'.
	 * @param int|null $expires_ts       Coupon expiry epoch (or null).
	 * @param int|null $schedule_end_ts  Schedule-end epoch (or null).
	 */
	public static function deadline_ts( string $source, ?int $expires_ts, ?int $schedule_end_ts ): ?int {
		$ts = 'schedule' === $source ? $schedule_end_ts : $expires_ts;
		return ( null !== $ts && $ts > 0 ) ? $ts : null;
	}

	/**
	 * Remaining redemptions = max(0, limit - count). Null when usage is unlimited (a null or
	 * non-positive limit — WooCommerce stores '' / 0 for "no limit"), so the caller can choose
	 * to show nothing rather than a misleading "0 left".
	 */
	public static function remaining( ?int $usage_limit, int $usage_count ): ?int {
		if ( null === $usage_limit || $usage_limit <= 0 ) {
			return null;
		}
		return max( 0, $usage_limit - max( 0, $usage_count ) );
	}

	/**
	 * Whether to show the "N left" badge: only when there is a finite remaining count AND it is
	 * at or below the threshold. A threshold of 0 (or less) means "always show when finite".
	 */
	public static function should_show_stock( ?int $remaining, int $threshold ): bool {
		if ( null === $remaining ) {
			return false;
		}
		if ( $threshold <= 0 ) {
			return true;
		}
		return $remaining <= $threshold;
	}

	/** Whether a countdown is still live (a deadline strictly in the future). */
	public static function is_live( ?int $deadline_ts, int $now ): bool {
		return null !== $deadline_ts && $deadline_ts > $now;
	}
}
