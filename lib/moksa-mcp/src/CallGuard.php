<?php
/**
 * Call guard for the MCP endpoint — limits an unattended channel needs.
 *
 * Moved from moksa-points (Mcp\CallGuard) and widened:
 * - a per-user cap on applied changes per hour (points' rule: a refused call is not counted);
 * - a per-user cap on all calls per minute;
 * - size caps on the request body, the batch, the arguments and their nesting;
 * - a cap on how many rows a list result may return.
 *
 * Counters live in transients. They are read-then-write, so a burst arriving in the same instant
 * can slip a few calls past the cap; that is acceptable for rate limiting, and nothing here is
 * used to protect a balance (each plugin locks its own money/points writes).
 *
 * @package Moksa\MCP
 */

declare( strict_types=1 );

namespace Moksa\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Limits and counters.
 */
final class CallGuard {

	public const MAX_BODY_BYTES = 262144;

	public const MAX_BATCH = 50;

	public const MAX_ARGS_BYTES = 65536;

	public const MAX_DEPTH = 10;

	public const MAX_ROWS = 100;

	private const DEFAULT_DESTRUCTIVE_LIMIT = 20;

	private const DEFAULT_RATE_LIMIT = 120;

	private const DEFAULT_PROPOSE_LIMIT = 60;

	/**
	 * Applied changes allowed per user per hour (0 = no limit, an explicit choice).
	 * The strictest limit any enabled plugin asks for wins; the filter has the last word.
	 */
	public static function destructive_limit(): int {
		$limit = self::DEFAULT_DESTRUCTIVE_LIMIT;
		$asked = array();
		foreach ( Catalog::enabled_providers() as $provider ) {
			if ( null !== $provider['destructive_limit'] ) {
				$asked[] = (int) $provider['destructive_limit'];
			}
		}
		if ( array() !== $asked ) {
			$positive = array_filter(
				$asked,
				static function ( int $n ): bool {
					return $n > 0;
				}
			);
			$limit    = array() === $positive ? 0 : min( $positive );
		}
		return max( 0, (int) apply_filters( 'moksa_mcp_destructive_limit', $limit ) );
	}

	/**
	 * Calls allowed per user per minute (0 = no limit).
	 */
	public static function rate_limit(): int {
		return max( 0, (int) apply_filters( 'moksa_mcp_rate_limit', self::DEFAULT_RATE_LIMIT ) );
	}

	/**
	 * Count one call against the per-minute cap. False = over the cap (and not counted).
	 *
	 * @param int $user_id User.
	 */
	public static function take_call( int $user_id ): bool {
		return self::take_calls( $user_id, 1 );
	}

	/**
	 * Count several calls (one JSON-RPC batch) at once. False = the whole batch would exceed the
	 * cap, and nothing is counted.
	 *
	 * @param int $user_id User.
	 * @param int $calls   Messages in the request.
	 */
	public static function take_calls( int $user_id, int $calls ): bool {
		return self::take( 'call', $user_id, self::rate_limit(), MINUTE_IN_SECONDS, max( 1, $calls ) );
	}

	/**
	 * Proposals allowed per user per hour (each one parks a token for 10 minutes).
	 */
	public static function propose_limit(): int {
		return max( 0, (int) apply_filters( 'moksa_mcp_propose_limit', self::DEFAULT_PROPOSE_LIMIT ) );
	}

	/**
	 * Count one proposal. False = over the hourly cap (and not counted).
	 *
	 * @param int $user_id User.
	 */
	public static function take_propose( int $user_id ): bool {
		return self::take( 'propose', $user_id, self::propose_limit(), HOUR_IN_SECONDS );
	}

	/**
	 * May this user apply one more change now? Does not count — {@see record_apply()} does, so a
	 * call refused for another reason is never counted.
	 *
	 * @param int $user_id User.
	 */
	public static function allow_apply( int $user_id ): bool {
		$limit = self::destructive_limit();
		return $limit <= 0 || $user_id <= 0 || self::count( 'apply', $user_id, HOUR_IN_SECONDS ) < $limit;
	}

	/**
	 * Count one applied change.
	 *
	 * @param int $user_id User.
	 */
	public static function record_apply( int $user_id ): void {
		self::bump( 'apply', $user_id, HOUR_IN_SECONDS );
	}

	/**
	 * Is the raw request body within the size cap?
	 *
	 * @param string $body Raw body.
	 */
	public static function body_ok( string $body ): bool {
		return strlen( $body ) <= self::MAX_BODY_BYTES;
	}

	/**
	 * Are the call arguments within the size and nesting caps?
	 *
	 * @param array<mixed> $args Arguments.
	 */
	public static function args_ok( array $args ): bool {
		return strlen( (string) wp_json_encode( $args ) ) <= self::MAX_ARGS_BYTES
			&& self::depth( $args ) <= self::MAX_DEPTH;
	}

	/**
	 * Trim a list result to MAX_ROWS. Looks at the result itself and at the first list-valued key
	 * of an object result (the usual { items: [...], total: n } shape).
	 *
	 * @param mixed $result    Tool result.
	 * @param bool  $truncated Set true when something was cut.
	 * @return mixed
	 */
	public static function cap_rows( $result, bool &$truncated ) {
		$truncated = false;
		if ( ! is_array( $result ) ) {
			return $result;
		}
		if ( array_is_list( $result ) ) {
			if ( count( $result ) > self::MAX_ROWS ) {
				$truncated = true;
				return array_slice( $result, 0, self::MAX_ROWS );
			}
			return $result;
		}
		foreach ( $result as $key => $value ) {
			if ( is_array( $value ) && array_is_list( $value ) && count( $value ) > self::MAX_ROWS ) {
				$result[ $key ] = array_slice( $value, 0, self::MAX_ROWS );
				$truncated      = true;
			}
		}
		return $result;
	}

	/**
	 * Count and allow in one step (for caps where a refused call should not count).
	 *
	 * @param string $kind    Counter name.
	 * @param int    $user_id User.
	 * @param int    $limit   Cap (0 = none).
	 * @param int    $window  Window in seconds.
	 * @param int    $amount  How many to count.
	 */
	private static function take( string $kind, int $user_id, int $limit, int $window, int $amount = 1 ): bool {
		if ( $limit <= 0 || $user_id <= 0 ) {
			return true;
		}
		if ( self::count( $kind, $user_id, $window ) + $amount > $limit ) {
			return false;
		}
		self::bump( $kind, $user_id, $window, $amount );
		return true;
	}

	/**
	 * Current count inside the window.
	 *
	 * @param string $kind    Counter name.
	 * @param int    $user_id User.
	 * @param int    $window  Window in seconds.
	 */
	private static function count( string $kind, int $user_id, int $window ): int {
		$bucket = get_transient( self::key( $kind, $user_id ) );
		if ( ! is_array( $bucket ) || ( time() - (int) ( $bucket['start'] ?? 0 ) ) >= $window ) {
			return 0;
		}
		return (int) ( $bucket['n'] ?? 0 );
	}

	/**
	 * Add one to the counter. The window is fixed from the first call in it (it does not slide
	 * forward on every call, which would keep a busy user locked out indefinitely).
	 *
	 * @param string $kind    Counter name.
	 * @param int    $user_id User.
	 * @param int    $window  Window in seconds.
	 * @param int    $amount  How many to add.
	 */
	private static function bump( string $kind, int $user_id, int $window, int $amount = 1 ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$key    = self::key( $kind, $user_id );
		$bucket = get_transient( $key );
		$now    = time();
		if ( ! is_array( $bucket ) || ( $now - (int) ( $bucket['start'] ?? 0 ) ) >= $window ) {
			$bucket = array(
				'start' => $now,
				'n'     => 0,
			);
		}
		$bucket['n'] = (int) $bucket['n'] + $amount;
		set_transient( $key, $bucket, max( 1, $window - ( $now - (int) $bucket['start'] ) ) );
	}

	/**
	 * Transient key for a counter.
	 *
	 * @param string $kind    Counter name.
	 * @param int    $user_id User.
	 */
	private static function key( string $kind, int $user_id ): string {
		return 'moksa_mcp_rl_' . $kind . '_' . $user_id;
	}

	/**
	 * Nesting depth of a value.
	 *
	 * @param mixed $value Any value.
	 */
	private static function depth( $value ): int {
		if ( ! is_array( $value ) || array() === $value ) {
			return 0;
		}
		$max = 0;
		foreach ( $value as $child ) {
			$max = max( $max, self::depth( $child ) );
			if ( $max > self::MAX_DEPTH ) {
				break;
			}
		}
		return $max + 1;
	}
}
