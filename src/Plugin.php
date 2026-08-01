<?php

declare( strict_types=1 );

namespace Moksafocou;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap (singleton). Wires the settings tab and the module registry
 * once WooCommerce is ready, after a hard requirements check.
 */
final class Plugin {

	private static ?self $instance = null;

	private ModuleRegistry $modules;

	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->modules = new ModuleRegistry();
	}

	public function __clone() {
		throw new \LogicException( 'Plugin is a singleton.' );
	}

	public function __wakeup(): void {
		throw new \LogicException( 'Plugin is a singleton.' );
	}

	public function modules(): ModuleRegistry {
		return $this->modules;
	}

	public static function version(): string {
		return MOKSAFOCOU_VERSION;
	}

	public static function path( string $relative = '' ): string {
		return MOKSAFOCOU_PLUGIN_DIR . ltrim( $relative, '/' );
	}

	public static function url( string $relative = '' ): string {
		return MOKSAFOCOU_PLUGIN_URL . ltrim( $relative, '/' );
	}

	public static function file(): string {
		return MOKSAFOCOU_PLUGIN_FILE;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( ! Compatibility\Requirements::met() ) {
			Compatibility\Requirements::register_admin_notice();
			return;
		}

		add_action( 'woocommerce_init', [ $this, 'on_woocommerce_init' ] );
		add_filter( 'plugin_action_links_' . MOKSAFOCOU_PLUGIN_BASENAME, [ $this, 'plugin_action_links' ] );
	}

	/**
	 * @param array<int,string> $links
	 * @return array<int,string>
	 */
	public function plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Settings\SettingsScreen::url() ),
			esc_html__( 'Settings', 'moksa-coupons-for-woocommerce' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	public function on_woocommerce_init(): void {
		// Our own settings screen (always-on). Register the save handler unconditionally,
		// and — when the independent AdminMenu is off — a fallback menu under WooCommerce
		// so the toggles stay reachable (you need them to turn the independent menu on).
		add_action( 'admin_post_' . Settings\SettingsScreen::ACTION, array( Settings\SettingsScreen::class, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( Settings\SettingsScreen::class, 'enqueue_admin' ) );
		if ( ! $this->modules->is_enabled( 'adminmenu' ) ) {
			add_action( 'admin_menu', array( Settings\SettingsScreen::class, 'register_fallback' ) );
		}
		Modules\CouponCore\Module::boot();
		// URL-coupon rewrite lifecycle is always-on (must run even when the module is
		// off, so toggling it off can remove the now-stale /coupon/ rewrite rule).
		Modules\UrlCoupons\Lifecycle::register();
		// Always-on: type + sanitize + auth for the coupon meta WooCommerce already
		// round-trips through REST `meta_data`. Pure hardening, opens no new surface.
		Coupon\Meta\RestMeta::register();
		// Always-on: a clean grouped `moksafocou` field on the coupon REST resource so
		// external callers read/write our settings without digging through meta_data.
		Coupon\Meta\CouponSettings::register();
		// Always-on: record a private order note for special-effect coupons (BOGO /
		// shipping override / free gift) whose saving WooCommerce itself does not show.
		Coupon\OrderCouponNote::register();
		// Always-on integration points for sibling Moksa plugins (points / membership /
		// group-buy commission). moksafocou only announces / gates — it stores no value,
		// computes no commission, defines no tier. See platform-plan/README.md §3.5.
		Coupon\CouponRedeemed::register();
		Coupon\CouponGate::register();
		// Always-on (admin): show applied coupons in the order-list quick-preview, which
		// WooCommerce core omits.
		if ( is_admin() ) {
			Admin\OrderCouponPreview::register();
		}
		// Always-on: the shared daily cron heartbeat. Modules attach time-based work to it.
		Support\Cron::register();
		$this->modules->boot();
	}
}
