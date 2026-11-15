<?php
/**
 * Two-step confirm for destructive tools over MCP: propose, then apply.
 *
 * - propose: calling a destructive tool runs its prepare handler (validate + describe, change
 *   nothing) and hands back a one-time token with the summary.
 * - apply: the shared `moksa-apply` tool takes the token and carries the change out.
 *
 * The token is bound to the user and to the prepared parameters, which never leave the server —
 * the client only ever holds the token, so it cannot swap the parameters in between. Tokens last
 * ten minutes and work once: the transient is deleted before anything runs, and only the request
 * whose delete succeeds goes on (two simultaneous applies → one change).
 *
 * At apply time everything is checked again: the tool must still be open on this site, and the
 * user must still hold the tool's own capability and pass its permission callback.
 *
 * @package Moksa\MCP
 */

declare( strict_types=1 );

namespace Moksa\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Propose / apply.
 */
final class Confirm {

	private const PREFIX = 'moksa_mcp_tok_';

	public const TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Propose a destructive change.
	 *
	 * @param array<string,mixed> $entry Catalog entry of the tool.
	 * @param array<string,mixed> $args  Call arguments.
	 * @return array<string,mixed>|\WP_Error Result payload for the client.
	 */
	public static function propose( array $entry, array $args ) {
		$providers = Catalog::providers();
		$provider  = $providers[ $entry['provider'] ] ?? null;
		$handlers  = null === $provider ? array() : Catalog::handlers( $provider );
		$name      = (string) $entry['name'];
		if ( ! isset( $handlers[ $name ] ) ) {
			return new \WP_Error( 'moksa_mcp_not_allowed', Strings::get( 'notAllowed' ) );
		}

		// Each proposal parks a token (with its parameters) for 10 minutes: cap how many one user
		// can make per hour, so proposing without ever applying cannot pile up stored data.
		if ( ! CallGuard::take_propose( get_current_user_id() ) ) {
			return new \WP_Error( 'moksa_mcp_rate_limited', Strings::get( 'rateLimited' ) );
		}

		$prepared = call_user_func( $handlers[ $name ]['prepare'], $args );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( ! is_array( $prepared ) || empty( $prepared['summary'] ) ) {
			return new \WP_Error( 'moksa_mcp_not_prepared', Strings::get( 'notPrepared' ) );
		}

		$token = wp_generate_password( 32, false, false );
		set_transient(
			self::key( $token ),
			array(
				'user'      => get_current_user_id(),
				'ability'   => $name,
				'tool'      => Catalog::tool_name( $name ),
				'provider'  => (string) $entry['provider'],
				'args'      => $args,
				'args_hash' => Audit::args_hash( $args ),
				'params'    => $prepared,
			),
			self::TTL
		);

		return array(
			'status'     => 'proposed',
			'changed'    => false,
			'summary'    => (string) $prepared['summary'],
			'token'      => $token,
			'expires_in' => self::TTL,
			'apply_with' => Catalog::APPLY_TOOL,
			'note'       => Strings::get( 'proposed' ),
		);
	}

	/**
	 * Apply a proposed change.
	 *
	 * @param string $token Token from propose.
	 * @param string $scope Provider key the request came in on ('' = main endpoint).
	 * @return array{outcome:string, tool:string, args_hash:string, result:mixed} Outcome for the
	 *         audit log plus either the payload or a WP_Error in `result`.
	 */
	public static function apply( string $token, string $scope ): array {
		$fail = static function ( string $outcome, string $tool, string $hash, string $message_key ): array {
			return array(
				'outcome'   => $outcome,
				'tool'      => $tool,
				'args_hash' => $hash,
				'result'    => new \WP_Error( 'moksa_mcp_' . $outcome, Strings::get( $message_key ) ),
			);
		};

		$key  = self::key( $token );
		$data = '' === $token ? false : get_transient( $key );
		if ( ! is_array( $data ) || (int) ( $data['user'] ?? 0 ) !== get_current_user_id() || get_current_user_id() <= 0 ) {
			return $fail( 'stale', Catalog::APPLY_TOOL, '', 'stale' );
		}
		$tool = (string) ( $data['tool'] ?? '' );
		$hash = (string) ( $data['args_hash'] ?? '' );

		// A token from one plugin's legacy endpoint cannot be spent on another's.
		if ( '' !== $scope && (string) ( $data['provider'] ?? '' ) !== $scope ) {
			return $fail( 'stale', $tool, $hash, 'stale' );
		}

		// Still open, still allowed: re-read the catalog rather than trust what propose saw.
		$entries = Catalog::entries( $scope );
		$entry   = $entries[ $tool ] ?? null;
		if ( null === $entry || ! $entry['destructive'] ) {
			return $fail( 'not_allowed', $tool, $hash, 'notAllowed' );
		}
		$args = isset( $data['args'] ) && is_array( $data['args'] ) ? $data['args'] : array();
		if ( ! Catalog::user_can( $entry ) || true !== self::permitted( $entry['ability'], $args ) ) {
			return $fail( 'denied', $tool, $hash, 'noPermission' );
		}

		$user = get_current_user_id();
		if ( ! CallGuard::allow_apply( $user ) ) {
			return array(
				'outcome'   => 'rate_limited',
				'tool'      => $tool,
				'args_hash' => $hash,
				'result'    => new \WP_Error( 'moksa_mcp_rate_limited', sprintf( Strings::get( 'destructiveLimited' ), CallGuard::destructive_limit() ) ),
			);
		}

		// Only the request whose delete succeeds may run (a read-then-delete would let two run).
		if ( ! delete_transient( $key ) ) {
			return $fail( 'stale', $tool, $hash, 'stale' );
		}
		CallGuard::record_apply( $user );

		$providers = Catalog::providers();
		$handlers  = Catalog::handlers( $providers[ $entry['provider'] ] );
		$name      = (string) $entry['name'];
		if ( ! isset( $handlers[ $name ] ) ) {
			return $fail( 'not_allowed', $tool, $hash, 'notAllowed' );
		}

		try {
			$result = call_user_func( $handlers[ $name ]['apply'], (array) ( $data['params'] ?? array() ) );
		} catch ( \Throwable $e ) {
			return array(
				'outcome'   => 'exception',
				'tool'      => $tool,
				'args_hash' => $hash,
				'result'    => new \WP_Error( 'moksa_mcp_exception', ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? $e->getMessage() : Strings::get( 'toolFailed' ) ),
			);
		}
		if ( is_wp_error( $result ) ) {
			return array(
				'outcome'   => 'error',
				'tool'      => $tool,
				'args_hash' => $hash,
				'result'    => $result,
			);
		}

		return array(
			'outcome'   => 'applied',
			'tool'      => $tool,
			'args_hash' => $hash,
			'result'    => array(
				'status'  => 'applied',
				'changed' => true,
				'message' => is_string( $result ) && '' !== $result ? $result : Strings::get( 'applied' ),
				'result'  => is_array( $result ) ? $result : null,
			),
		);
	}

	/**
	 * Run the ability's own permission callback.
	 *
	 * @param object              $ability WP_Ability.
	 * @param array<string,mixed> $args    Arguments.
	 * @return bool True only when explicitly allowed.
	 */
	public static function permitted( $ability, array $args ): bool {
		$perm = $ability->check_permissions( $args );
		return true === $perm;
	}

	/**
	 * Transient key for a token (the token itself is never stored as a key).
	 *
	 * @param string $token Token.
	 */
	private static function key( string $token ): string {
		return self::PREFIX . substr( hash( 'sha256', $token ), 0, 40 );
	}
}
