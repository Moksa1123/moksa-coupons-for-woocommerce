<?php
/**
 * Messages the shared MCP layer sends back to clients.
 *
 * The shared layer carries no translatable strings (every plugin bundles a byte-identical copy,
 * and a text domain belongs to one plugin). The defaults are plain English — the reader is an AI
 * client, not a shopper — and a plugin may swap any of them for its own translation through the
 * `moksa_mcp_strings` filter.
 *
 * @package Moksa\MCP
 */

declare( strict_types=1 );

namespace Moksa\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * English defaults plus a filter.
 */
final class Strings {

	/**
	 * Cached merged table for this request.
	 *
	 * @var array<string,string>|null
	 */
	private static $cache = null;

	/**
	 * All messages, defaults merged with the filter.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$defaults = array(
			'noPermission'       => 'You do not have permission to do this.',
			'toolFailed'         => 'The tool failed to run. Please try again later.',
			'rateLimited'        => 'Too many requests. Please wait a minute and try again.',
			'destructiveLimited' => 'This account has reached its limit of %d changes per hour over MCP. Please try again later.',
			'tooLarge'           => 'The request is too large.',
			'notAllowed'         => 'This tool is not enabled for MCP on this site.',
			'notPrepared'        => 'The change could not be prepared.',
			'proposed'           => 'Nothing has been changed yet. Review the summary, then call moksa-apply with this token within 10 minutes to carry out the change.',
			'stale'              => 'That token has expired, has already been used, or belongs to someone else. Please propose the change again.',
			'applied'            => 'Done. The change has been made.',
			'truncated'          => 'Only the first %d results are included.',
			'applyTitle'         => 'Apply a proposed change',
			'applyDescription'   => 'Carry out a change that an earlier tool call proposed. Pass the token that call returned. A token works once, for the same user, within 10 minutes.',
			'applyToken'         => 'The token returned by the tool that proposed the change.',
			'privacyGroup'       => 'Moksa MCP activity',
		);
		$filtered = apply_filters( 'moksa_mcp_strings', $defaults );
		$out      = $defaults;
		if ( is_array( $filtered ) ) {
			foreach ( $defaults as $key => $value ) {
				if ( isset( $filtered[ $key ] ) && is_string( $filtered[ $key ] ) && '' !== $filtered[ $key ] ) {
					$out[ $key ] = $filtered[ $key ];
				}
			}
		}
		self::$cache = $out;
		return $out;
	}

	/**
	 * One message by key ('' for an unknown key).
	 *
	 * @param string $key Message key.
	 */
	public static function get( string $key ): string {
		$all = self::all();
		return $all[ $key ] ?? '';
	}
}
