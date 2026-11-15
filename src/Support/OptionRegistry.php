<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers every option this plugin has written, so uninstall can delete them one by one.
 *
 * WordPress has no API for "options starting with moksafocou_", and wp_load_alloptions() only
 * returns autoloaded ones. So: core fires added_option for every new option, and the name is
 * recorded when it is ours. Sites that already had options before this existed are back-filled once
 * (autoloaded ones from wp_load_alloptions(), the rest from known_unautoloaded()). Same approach as
 * mowp's Settings\OptionRegistry.
 *
 * Not recorded: transients (they expire on their own) and the registry itself.
 */
final class OptionRegistry {

	public const OPTION = 'moksafocou_option_registry';

	private const BACKFILLED = 'moksafocou_option_registry_backfilled';

	/** Current prefix, plus the pre-rename one still found on older sites. */
	private const PREFIXES = array( 'moksafocou_', 'moforcoupon_' );

	public static function boot(): void {
		add_action( 'added_option', array( self::class, 'track' ) );
		add_action( 'init', array( self::class, 'maybe_backfill' ), 20 );
	}

	/**
	 * Record a newly added option when it is ours.
	 *
	 * @param mixed $name Option name.
	 */
	public static function track( $name ): void {
		if ( is_string( $name ) && self::ours( $name ) ) {
			self::add( array( $name ) );
		}
	}

	/**
	 * Is this option name one of ours (and worth recording)?
	 *
	 * @param string $name Option name.
	 */
	public static function ours( string $name ): bool {
		if ( self::OPTION === $name || str_contains( $name, '_transient_' ) ) {
			return false;
		}
		foreach ( self::PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recorded option names.
	 *
	 * @return array<int,string>
	 */
	public static function names(): array {
		$names = get_option( self::OPTION, array() );
		return is_array( $names ) ? array_values( array_filter( $names, 'is_string' ) ) : array();
	}

	/** One-time back-fill for sites that had options before the registry existed. */
	public static function maybe_backfill(): void {
		if ( 'yes' === get_option( self::BACKFILLED ) ) {
			return;
		}
		$found = array();
		foreach ( array_keys( wp_load_alloptions() ) as $name ) {
			if ( is_string( $name ) && self::ours( $name ) ) {
				$found[] = $name;
			}
		}
		foreach ( self::known_unautoloaded() as $name ) {
			if ( null !== get_option( $name, null ) ) {
				$found[] = $name;
			}
		}
		self::add( $found );
		update_option( self::BACKFILLED, 'yes', false );
	}

	/**
	 * Options this plugin writes with autoload off (invisible to wp_load_alloptions()). Only needed
	 * for the one-time back-fill: every later write is recorded by track().
	 *
	 * @return array<int,string>
	 */
	public static function known_unautoloaded(): array {
		return array(
			'moksafocou_cards_ver',
			'moksafocou_report_ver',
			'moksafocou_url_rewrite_version',
			'moksafocou_defaults_seeded',
			'moksafocou_mcp_destructive_tools',
			'moksafocou_coupon_ix_ready',
			'moksafocou_coupon_ix_progress',
			'moksafocou_coupon_ix_last_full',
			// Written by earlier versions, no longer used.
			'moksafocou_autoapply_ids',
			'moksafocou_mcp_expose_destructive',
			'moksafocou_ai_greeting',
			'moksafocou_ai_examples',
		);
	}

	/**
	 * Merge names into the registry.
	 *
	 * @param array<int,string> $names Option names.
	 */
	private static function add( array $names ): void {
		$current = self::names();
		$merged  = array_values( array_unique( array_merge( $current, $names ) ) );
		if ( count( $merged ) !== count( $current ) ) {
			update_option( self::OPTION, $merged, false );
		}
	}

	private function __construct() {}
}
