<?php

declare( strict_types=1 );

namespace Moksafocou\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Hard environment check. Core coupon features require PHP / WP / WooCommerce
 * floors; AI / Abilities / MCP are gated separately via function_exists().
 */
final class Requirements {

	/** @var array<int,string> */
	private static array $messages = [];

	public static function met(): bool {
		self::$messages = [];

		if ( version_compare( PHP_VERSION, MOKSAFOCOU_MIN_PHP, '<' ) ) {
			self::$messages[] = sprintf(
				/* translators: 1: required PHP, 2: current PHP. */
				__( 'Moksa Coupons requires PHP %1$s or higher. You are running %2$s.', 'moksa-coupons-for-woocommerce' ),
				MOKSAFOCOU_MIN_PHP,
				PHP_VERSION
			);
		}

		global $wp_version;
		if ( version_compare( (string) $wp_version, MOKSAFOCOU_MIN_WP, '<' ) ) {
			self::$messages[] = sprintf(
				/* translators: 1: required WP, 2: current WP. */
				__( 'Moksa Coupons requires WordPress %1$s or higher. You are running %2$s.', 'moksa-coupons-for-woocommerce' ),
				MOKSAFOCOU_MIN_WP,
				(string) $wp_version
			);
		}

		if ( ! self::woocommerce_active() ) {
			self::$messages[] = __( 'Moksa Coupons requires WooCommerce to be active.', 'moksa-coupons-for-woocommerce' );
		} elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, MOKSAFOCOU_MIN_WC, '<' ) ) {
			self::$messages[] = sprintf(
				/* translators: 1: required WC, 2: current WC. */
				__( 'Moksa Coupons requires WooCommerce %1$s or higher. You are running %2$s.', 'moksa-coupons-for-woocommerce' ),
				MOKSAFOCOU_MIN_WC,
				WC_VERSION
			);
		}

		return self::$messages === [];
	}

	public static function register_admin_notice(): void {
		$messages = self::$messages;
		if ( $messages === [] ) {
			return;
		}
		add_action(
			'admin_notices',
			static function () use ( $messages ): void {
				echo '<div class="notice notice-error"><p><strong>Moksa Coupons</strong></p><ul style="list-style:disc;margin-left:1.5em;">';
				foreach ( $messages as $msg ) {
					echo '<li>' . esc_html( $msg ) . '</li>';
				}
				echo '</ul></div>';
			}
		);
	}

	private static function woocommerce_active(): bool {
		if ( class_exists( \WooCommerce::class ) ) {
			return true;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core filter name; reading the active-plugins list to check whether WooCommerce is active.
		$active = (array) apply_filters( 'active_plugins', get_option( 'active_plugins', [] ) );
		return in_array( 'woocommerce/woocommerce.php', $active, true );
	}
}
