<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\AdminMenu;

use Moksafocou\Plugin;
use Moksafocou\Settings\SettingsScreen;
use Moksafocou\Settings\SettingsUi;
use Moksafocou\Modules\Reports\ReportsPage;
use Moksafocou\Modules\Templates\TemplatePage;

defined( 'ABSPATH' ) || exit;

/**
 * Landing page for the top-level 'Moksa coupon' menu — a lightweight management hub
 * with at-a-glance coupon counts and quick links. Seeds the future full dashboard.
 */
final class Dashboard {

	private const CAP = 'edit_shop_coupons';

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$counts    = wp_count_posts( 'shop_coupon' );
		$published = isset( $counts->publish ) ? (int) $counts->publish : 0;
		$draft     = isset( $counts->draft ) ? (int) $counts->draft : 0;
		$pending   = isset( $counts->pending ) ? (int) $counts->pending : 0;
		$total     = $published + $draft + $pending;

		echo '<div class="wrap"><div class="mowp-shell" data-ns="moksa-coupons-for-woocommerce">';
		echo '<div class="mowp-intro"><h1>' . esc_html__( 'Moksa coupon management', 'moksa-coupons-for-woocommerce' ) . '</h1>';
		echo '<p>' . esc_html__( 'Manage all coupons, view reports, and adjust settings here in one place.', 'moksa-coupons-for-woocommerce' ) . '</p>';
		echo '<p><a href="' . esc_url( admin_url( 'post-new.php?post_type=shop_coupon' ) ) . '" class="button button-primary">'
			. esc_html__( 'Add coupon', 'moksa-coupons-for-woocommerce' ) . '</a></p></div>';

		// Stat tiles.
		echo '<div class="mowp-tiles">';
		self::stat_tile( __( 'Total coupons', 'moksa-coupons-for-woocommerce' ), (string) $total );
		self::stat_tile( __( 'Active', 'moksa-coupons-for-woocommerce' ), (string) $published );
		self::stat_tile( __( 'Draft / pending', 'moksa-coupons-for-woocommerce' ), (string) ( $draft + $pending ) );
		echo '</div>';

		// Quick links.
		echo '<div class="mowp-linkcards">';
		self::link_card(
			admin_url( 'edit.php?post_type=shop_coupon' ),
			__( 'All coupons', 'moksa-coupons-for-woocommerce' ),
			__( 'View, edit, and search all coupons.', 'moksa-coupons-for-woocommerce' )
		);
		self::link_card(
			admin_url( 'post-new.php?post_type=shop_coupon' ),
			__( 'Add coupon', 'moksa-coupons-for-woocommerce' ),
			__( 'Create a new coupon.', 'moksa-coupons-for-woocommerce' )
		);
		if ( Plugin::instance()->modules()->is_enabled( 'templates' ) ) {
			self::link_card(
				admin_url( 'admin.php?page=' . TemplatePage::slug() ),
				__( 'Coupon template', 'moksa-coupons-for-woocommerce' ),
				__( 'Pick a template to create a draft coupon in one click.', 'moksa-coupons-for-woocommerce' )
			);
		}
		self::link_card(
			SettingsScreen::url(),
			__( 'Coupon settings', 'moksa-coupons-for-woocommerce' ),
			__( 'Enable / disable each feature module.', 'moksa-coupons-for-woocommerce' )
		);
		echo '</div>';

		// Coupon reports now live here on the dashboard (instead of a separate page).
		if ( Plugin::instance()->modules()->is_enabled( 'reports' ) && current_user_can( 'manage_woocommerce' ) ) {
			echo '<h2 class="mowp-h2">' . esc_html__( 'Coupon report', 'moksa-coupons-for-woocommerce' ) . '</h2>';
			ReportsPage::render_table( admin_url( 'admin.php?page=' . Menu::TOPLEVEL ) );
		}

		echo '</div></div>';
	}

	/** Enqueue the shared mowp design-system CSS inline on the core admin 'common' handle (no raw <style>). */
	public static function enqueue_admin( string $hook = '' ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, Menu::TOPLEVEL ) ) {
			wp_add_inline_style( 'common', SettingsUi::css() );
		}
	}

	private static function stat_tile( string $label, string $value ): void {
		echo '<div class="mowp-tile"><div class="mowp-tile__num">' . esc_html( $value ) . '</div>'
			. '<div class="mowp-tile__label">' . esc_html( $label ) . '</div></div>';
	}

	private static function link_card( string $url, string $title, string $desc ): void {
		echo '<a class="mowp-linkcard" href="' . esc_url( $url ) . '">'
			. '<span class="mowp-linkcard__t">' . esc_html( $title ) . '</span>'
			. '<span class="mowp-linkcard__d">' . esc_html( $desc ) . '</span></a>';
	}
}
