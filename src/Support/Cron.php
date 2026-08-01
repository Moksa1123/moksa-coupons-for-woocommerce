<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's shared WP-Cron heartbeats — one daily, one hourly. Scheduling is always-on
 * (registered by the core Plugin) so the actions always fire; individual modules attach their
 * time-based work (expiry reminders, birthday coupons, cart recovery, cleanup…) to whichever
 * cadence they need, only while they are enabled. One shared event per cadence avoids each feature
 * scheduling its own.
 */
final class Cron {

	/** Action fired once a day; modules hook this. */
	public const HOOK = 'moksafocou_cron_daily';

	/** Action fired hourly; for time-sensitive work like cart recovery. */
	public const HOURLY = 'moksafocou_cron_hourly';

	public static function register(): void {
		add_action( 'init', array( self::class, 'ensure_scheduled' ) );
	}

	/** Schedule the events if they aren't already queued. */
	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'hourly', self::HOURLY );
		}
	}

	/** Remove the scheduled events (on deactivation / uninstall). */
	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOURLY );
	}
}
