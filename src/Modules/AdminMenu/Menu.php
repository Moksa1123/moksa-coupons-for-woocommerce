<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\AdminMenu;

use Moksafocou\Plugin;
use Moksafocou\Settings\SettingsScreen;
use Moksafocou\Modules\Templates\TemplatePage;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the independent top-level 'Moksa coupon' admin menu and moves the native
 * shop_coupon CPT under it, so coupon management lives in its own place (matching
 * the Advanced Coupons UX) instead of buried under WooCommerce.
 *
 * Strategy: hide shop_coupon from WordPress's automatic menu placement
 * (show_in_menu => false via register_post_type_args) and register every submenu
 * ourselves with explicit positions. This is fully deterministic — it avoids the
 * fragile core ordering / priority races and the fact that core only auto-adds the
 * "All items" submenu (never "Add New") for a string show_in_menu. Screen
 * highlighting is pinned with parent_file / submenu_file filters.
 */
final class Menu {

	/** Top-level menu slug (also the Dashboard landing page). */
	public const TOPLEVEL = 'moksa-coupons-for-woocommerce';

	/** Capability required to see/manage the coupon menu (same as the CPT). */
	private const CAP = 'edit_shop_coupons';

	/**
	 * Pure resolver: what show_in_menu should a post type get? shop_coupon is hidden
	 * from core's automatic placement (we add its submenus by hand); everything else
	 * keeps whatever it already had.
	 *
	 * @param string $post_type Post type being registered.
	 * @param mixed  $current   The post type's current show_in_menu value.
	 * @return mixed
	 */
	public static function cpt_show_in_menu( string $post_type, $current ) {
		return 'shop_coupon' === $post_type ? false : $current;
	}

	/**
	 * register_post_type_args filter: hide shop_coupon from automatic menu placement.
	 *
	 * @param array<string,mixed>|mixed $args      Post type args.
	 * @param string                    $post_type Post type key.
	 * @return array<string,mixed>|mixed
	 */
	public static function reparent_cpt( $args, $post_type ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$args['show_in_menu'] = self::cpt_show_in_menu( (string) $post_type, $args['show_in_menu'] ?? true );
		return $args;
	}

	/**
	 * admin_menu: register the top-level page and every submenu with explicit
	 * positions so the order is deterministic regardless of core timing.
	 */
	public static function register(): void {
		add_menu_page(
			__( 'Moksa coupon', 'moksa-coupons-for-woocommerce' ),
			__( 'Coupon', 'moksa-coupons-for-woocommerce' ),
			self::CAP,
			self::TOPLEVEL,
			'',
			'dashicons-tickets-alt',
			55.6
		);

		// Dashboard landing — slug === parent so it owns the top-level click and
		// suppresses the auto-generated duplicate first item.
		add_submenu_page(
			self::TOPLEVEL,
			__( 'Dashboard', 'moksa-coupons-for-woocommerce' ),
			__( 'Dashboard', 'moksa-coupons-for-woocommerce' ),
			self::CAP,
			self::TOPLEVEL,
			array( Dashboard::class, 'render' ),
			0
		);

		// Reparented CPT screens (core no longer adds these — show_in_menu is false).
		add_submenu_page(
			self::TOPLEVEL,
			__( 'All coupons', 'moksa-coupons-for-woocommerce' ),
			__( 'All coupons', 'moksa-coupons-for-woocommerce' ),
			self::CAP,
			'edit.php?post_type=shop_coupon',
			'',
			5
		);
		add_submenu_page(
			self::TOPLEVEL,
			__( 'Add coupon', 'moksa-coupons-for-woocommerce' ),
			__( 'Add coupon', 'moksa-coupons-for-woocommerce' ),
			self::CAP,
			'post-new.php?post_type=shop_coupon',
			'',
			10
		);

		// Coupon templates page (one-click presets) when enabled.
		if ( Plugin::instance()->modules()->is_enabled( 'templates' ) ) {
			add_submenu_page(
				self::TOPLEVEL,
				__( 'Coupon template', 'moksa-coupons-for-woocommerce' ),
				__( 'Coupon template', 'moksa-coupons-for-woocommerce' ),
				self::CAP,
				TemplatePage::slug(),
				array( TemplatePage::class, 'render' ),
				12
			);
		}

		// (Coupon reports are embedded in the Dashboard landing page, not a submenu.)

		// Settings sub-pages (功能設定 / 前台與行銷) — our own card-styled pages, one submenu item
		// each so no single page has to carry every section. Specs come from SettingsScreen::pages().
		$position = 60;
		foreach ( SettingsScreen::pages() as $spec ) {
			add_submenu_page(
				self::TOPLEVEL,
				(string) $spec['label'],
				(string) $spec['menu'],
				'manage_woocommerce',
				(string) $spec['slug'],
				$spec['cb'],
				$position
			);
			++$position;
		}
	}

	/**
	 * parent_file filter: pin coupon CPT screens (list / edit / add-new) to our
	 * top-level menu so it highlights correctly even though show_in_menu is false.
	 *
	 * @param mixed $parent_file Current parent file.
	 * @return mixed
	 */
	public static function highlight_parent( $parent_file ) {
		return self::is_coupon_screen() ? self::TOPLEVEL : $parent_file;
	}

	/**
	 * submenu_file filter: highlight the right child (全部優惠券 vs 新增優惠券).
	 *
	 * @param mixed $submenu_file Current submenu file.
	 * @return mixed
	 */
	public static function highlight_submenu( $submenu_file ) {
		if ( ! self::is_coupon_screen() ) {
			return $submenu_file;
		}
		global $pagenow;
		return 'post-new.php' === $pagenow
			? 'post-new.php?post_type=shop_coupon'
			: 'edit.php?post_type=shop_coupon';
	}

	private static function is_coupon_screen(): bool {
		global $typenow;
		return 'shop_coupon' === $typenow;
	}

	/**
	 * Late admin_menu cleanup: drop WooCommerce's leftover "Coupons" pointer
	 * (admin.php?page=coupons-moved) so coupon management lives ONLY under our
	 * top-level menu — fully independent, matching the Advanced Coupons UX.
	 */
	public static function hide_legacy_coupon_menu(): void {
		remove_submenu_page( 'woocommerce', 'coupons-moved' );
	}
}
