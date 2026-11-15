<?php
/**
 * Audit log of MCP calls.
 *
 * External MCP calls run with no person watching, so every tools/call is recorded: when, who,
 * which tool, and how it ended. Only a hash of the arguments is kept (SOP §2.1), never the
 * arguments themselves, so the log can never become the place a customer's details or a secret
 * leak from. No IP address is stored.
 *
 * Storage is one option (autoload off) used as a ring buffer — no custom table, so no direct
 * database query. Two requests writing at the same moment can drop one entry; the log is an aid
 * for the shop owner, not the ledger (each plugin keeps its own record of what changed).
 *
 * @package Moksa\MCP
 */

declare( strict_types=1 );

namespace Moksa\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Ring-buffer audit log plus the personal-data export / erase hooks for it.
 */
final class Audit {

	public const OPTION = 'moksa_mcp_audit';

	/**
	 * Destructive calls (proposals, applies, refusals) are kept in their own ring buffer, so a flood
	 * of read-only calls cannot push a change out of sight.
	 */
	public const CHANGES_OPTION = 'moksa_mcp_audit_changes';

	private const DEFAULT_MAX = 200;

	private const HARD_MAX = 1000;

	/**
	 * Hook the privacy exporter / eraser (called once from Server::init).
	 */
	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}

	/**
	 * Record one call.
	 *
	 * @param string              $tool        MCP tool name.
	 * @param bool                $destructive Whether the tool changes data.
	 * @param string              $outcome     ok, denied, rate_limited, error, exception, proposed, applied, stale, not_allowed.
	 * @param array<string,mixed> $args        Call arguments (only their hash is kept).
	 * @param string              $via         'main' or the legacy route it came in on.
	 * @param string              $args_hash   Hash to record when the arguments are not at hand
	 *                                         (an apply records the hash of what was proposed).
	 * @param bool                $change      A token was spent: the change was carried out or failed
	 *                                         while being carried out (kept in the protected buffer).
	 */
	public static function log( string $tool, bool $destructive, string $outcome, array $args = array(), string $via = 'main', string $args_hash = '', bool $change = false ): void {
		if ( '' === $args_hash && array() !== $args ) {
			$args_hash = self::args_hash( $args );
		}
		// Only what happened after a token was spent (the change was carried out, or failed while
		// being carried out) goes to the protected buffer. Proposals and refusals go to the ordinary
		// one: they cost an attacker nothing to repeat, so they must not be able to push a change out.
		$option = $change ? self::CHANGES_OPTION : self::OPTION;
		$log    = self::read( $option );
		array_unshift(
			$log,
			array(
				't'         => gmdate( 'Y-m-d H:i:s' ),
				'user'      => get_current_user_id(),
				'tool'      => $tool,
				'destr'     => $destructive,
				'outcome'   => $outcome,
				'args_hash' => $args_hash,
				'via'       => $via,
			)
		);
		update_option( $option, array_slice( $log, 0, self::max() ), false );
	}

	/**
	 * Recent calls, newest first, optionally narrowed to one plugin's tools and some outcomes.
	 *
	 * @param string   $tool_prefix Tool-name prefix, e.g. 'moksafocou-' ('' = every tool).
	 * @param string[] $outcomes    Outcomes to keep (empty = all).
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( string $tool_prefix = '', array $outcomes = array() ): array {
		$out = array();
		foreach ( self::all() as $entry ) {
			$tool = (string) ( $entry['tool'] ?? '' );
			if ( '' !== $tool_prefix && 0 !== strpos( $tool, $tool_prefix ) ) {
				continue;
			}
			if ( array() !== $outcomes && ! in_array( (string) ( $entry['outcome'] ?? '' ), $outcomes, true ) ) {
				continue;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Stable fingerprint of a call's arguments: keys sorted at every level, so the same arguments in
	 * a different key order match. Keyed with the site's salt (HMAC), so someone reading the log
	 * cannot test guesses such as a list of customer emails against it.
	 *
	 * @param array<mixed> $args Arguments.
	 */
	public static function args_hash( array $args ): string {
		$data = (string) wp_json_encode( self::canonical( $args ) );
		return function_exists( 'wp_salt' )
			? hash_hmac( 'sha256', $data, wp_salt( 'auth' ) )
			: hash( 'sha256', $data );
	}

	/**
	 * Add the exporter to the privacy tools.
	 *
	 * @param array<string,mixed> $exporters Registered exporters.
	 * @return array<string,mixed>
	 */
	public static function register_exporter( $exporters ): array {
		$exporters              = is_array( $exporters ) ? $exporters : array();
		$exporters['moksa-mcp'] = array(
			'exporter_friendly_name' => Strings::get( 'privacyGroup' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Add the eraser to the privacy tools.
	 *
	 * @param array<string,mixed> $erasers Registered erasers.
	 * @return array<string,mixed>
	 */
	public static function register_eraser( $erasers ): array {
		$erasers              = is_array( $erasers ) ? $erasers : array();
		$erasers['moksa-mcp'] = array(
			'eraser_friendly_name' => Strings::get( 'privacyGroup' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Personal-data export for one email address.
	 *
	 * @param string $email Email address.
	 * @return array{data: array<int,array<string,mixed>>, done: bool}
	 */
	public static function export( $email ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = array();
		if ( $user ) {
			foreach ( self::all() as $i => $entry ) {
				if ( (int) ( $entry['user'] ?? 0 ) !== (int) $user->ID ) {
					continue;
				}
				$data[] = array(
					'group_id'    => 'moksa-mcp',
					'group_label' => Strings::get( 'privacyGroup' ),
					'item_id'     => 'moksa-mcp-' . $i,
					'data'        => array(
						array(
							'name'  => 'time (UTC)',
							'value' => (string) ( $entry['t'] ?? '' ),
						),
						array(
							'name'  => 'tool',
							'value' => (string) ( $entry['tool'] ?? '' ),
						),
						array(
							'name'  => 'outcome',
							'value' => (string) ( $entry['outcome'] ?? '' ),
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Personal-data erase for one email address: drop that user's entries.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int,string>, done: bool}
	 */
	public static function erase( $email ): array {
		$user    = get_user_by( 'email', (string) $email );
		$removed = false;
		if ( $user ) {
			foreach ( array( self::OPTION, self::CHANGES_OPTION ) as $option ) {
				$log  = self::read( $option );
				$kept = array();
				foreach ( $log as $entry ) {
					if ( (int) ( $entry['user'] ?? 0 ) !== (int) $user->ID ) {
						$kept[] = $entry;
					}
				}
				if ( count( $kept ) !== count( $log ) ) {
					$removed = true;
					update_option( $option, $kept, false );
				}
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Both buffers, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function all(): array {
		$all = array_merge( self::read( self::CHANGES_OPTION ), self::read( self::OPTION ) );
		usort(
			$all,
			static function ( array $a, array $b ): int {
				return strcmp( (string) ( $b['t'] ?? '' ), (string) ( $a['t'] ?? '' ) );
			}
		);
		return $all;
	}

	/**
	 * One stored buffer.
	 *
	 * @param string $option Option name.
	 * @return array<int,array<string,mixed>>
	 */
	private static function read( string $option ): array {
		$log = get_option( $option, array() );
		return is_array( $log ) ? array_values( $log ) : array();
	}

	/**
	 * How many entries to keep.
	 */
	private static function max(): int {
		$max = (int) apply_filters( 'moksa_mcp_audit_max', self::DEFAULT_MAX );
		return max( 1, min( self::HARD_MAX, $max ) );
	}

	/**
	 * Recursively sort associative keys.
	 *
	 * @param mixed $value Any value.
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::canonical( $v );
		}
		return $value;
	}
}
