<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Site-timezone helpers. Coupon schedule values are stored as wall-clock strings
 * in the site timezone; comparisons must use the site timezone (not the server's
 * PHP default) to avoid off-by-one-day errors. wp_timezone() covers both the
 * timezone_string and gmt_offset settings.
 */
final class SiteTime {

	public static function tz(): \DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		return new \DateTimeZone( 'UTC' );
	}

	/**
	 * Canonicalise a stored schedule value to 'Y-m-d H:i:s' wall-clock (site tz), accepting
	 * every shape the write paths produce — 'Y-m-d H:i:s', 'Y-m-d H:i' (seconds omitted),
	 * 'Y-m-dTH:i' (raw datetime-local / REST), or a bare 'Y-m-d' (→ midnight). Returns '' for
	 * empty / unparseable / impossible dates (a round-trip check rejects values createFromFormat
	 * would silently roll over, e.g. 2026-13-40). This is the single parser shared by the admin
	 * save, the REST sanitizer and to_timestamp(), so the TIME can never be silently lost.
	 */
	public static function normalize( string $value ): string {
		$value = trim( str_replace( 'T', ' ', $value ) );
		if ( '' === $value ) {
			return '';
		}
		// Date + time (seconds optional; pad when absent).
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})(:\d{2})?$/', $value, $m ) ) {
			$canonical = $m[1] . ' ' . $m[2] . ( $m[3] ?? ':00' );
			$dt        = \DateTime::createFromFormat( 'Y-m-d H:i:s', $canonical, self::tz() );
			return ( $dt instanceof \DateTime && $dt->format( 'Y-m-d H:i:s' ) === $canonical ) ? $canonical : '';
		}
		// Bare date → midnight site time.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$dt = \DateTime::createFromFormat( '!Y-m-d', $value, self::tz() );
			return ( $dt instanceof \DateTime && $dt->format( 'Y-m-d' ) === $value ) ? $value . ' 00:00:00' : '';
		}
		return '';
	}

	/**
	 * Parse a stored schedule value (any accepted shape) in the site timezone to a UTC epoch.
	 *
	 * @return int|null Epoch seconds, or null when empty / unparseable.
	 */
	public static function to_timestamp( string $value ): ?int {
		$canonical = self::normalize( $value );
		if ( '' === $canonical ) {
			return null;
		}
		$dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $canonical, self::tz() );
		return $dt instanceof \DateTime ? $dt->getTimestamp() : null;
	}

	/**
	 * Current weekday (0=Sunday … 6=Saturday) and minutes-of-day, in the site timezone.
	 * A timestamp may be injected for deterministic tests.
	 *
	 * @param int|null $timestamp UTC epoch (defaults to now).
	 * @return array{weekday:int,minutes:int}
	 */
	public static function now_parts( ?int $timestamp = null ): array {
		$ts = null === $timestamp ? time() : $timestamp;
		$dt = new \DateTime( '@' . $ts );
		$dt->setTimezone( self::tz() );
		return array(
			'weekday' => (int) $dt->format( 'w' ),
			'minutes' => ( (int) $dt->format( 'G' ) * 60 ) + (int) $dt->format( 'i' ),
		);
	}
}
