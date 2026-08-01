<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * First-run defaults. Every module is opt-in (off) for wp.org compliance, which means a brand-new
 * install lands on a blank settings screen with nothing happening. On the very first activation we
 * seed a small set of SAFE, additive modules so the plugin is immediately useful: the management
 * hub, the template gallery, the consolidated editor, type icons, reports, the coupon-card renderer
 * and cart/role/schedule conditions. None of these change checkout behaviour on their own, collect
 * personal data, or call an external service.
 *
 * Seeding uses add_option() (never overwrites an option a user already set) and is gated by a
 * one-time sentinel, so re-activating or upgrading never resurrects a module the user turned off.
 */
final class Activation {

	private const SENTINEL = 'moksafocou_defaults_seeded';

	/** @var array<int,string> Module keys enabled on first activation. */
	private const DEFAULTS = array(
		'adminmenu',
		'templates',
		'metaboxes',
		'tabicons',
		'reports',
		'frontend',
		'conditions',
	);

	public static function on_activate(): void {
		if ( 'yes' === get_option( self::SENTINEL ) ) {
			return;
		}

		/**
		 * Filter the modules enabled on first activation.
		 *
		 * @param array<int,string> $defaults Module keys.
		 */
		$defaults = (array) apply_filters( 'moksafocou_default_modules', self::DEFAULTS );
		foreach ( $defaults as $key ) {
			if ( is_string( $key ) && '' !== $key ) {
				// add_option is a no-op when the option already exists → respects a prior choice.
				add_option( 'moksafocou_' . $key . '_enabled', 'yes' );
			}
		}

		update_option( self::SENTINEL, 'yes', false );
	}
}
