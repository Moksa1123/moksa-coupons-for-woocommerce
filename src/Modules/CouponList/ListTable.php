<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponList;

use Moksafocou\Coupon\CouponService;
use Moksafocou\Modules\CouponCore\CouponOps;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into the WooCommerce coupon (shop_coupon) list table: a status column, bulk
 * 啟用/停用 actions and a 複製 row action. Every state change is capability- and
 * nonce-checked (the duplicate link via check_admin_referer, bulk via WP's own bulk nonce).
 */
final class ListTable {

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_dup_coupon';

	public static function boot(): void {
		// WooCommerce sets the coupon columns via the screen-id filter and returns its own
		// array; hook the same filter at a later priority so our column is appended, not lost.
		add_filter( 'manage_edit-shop_coupon_columns', array( self::class, 'columns' ), 20 );
		add_action( 'manage_shop_coupon_posts_custom_column', array( self::class, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-shop_coupon', array( self::class, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_coupon', array( self::class, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_post_moksafocou_dup_coupon', array( self::class, 'handle_duplicate' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
	}

	/**
	 * @param array<string,string> $cols
	 * @return array<string,string>
	 */
	public static function columns( $cols ): array {
		$cols   = is_array( $cols ) ? $cols : array();
		$out    = array();
		$placed = false;
		foreach ( $cols as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'coupon_amount' === $key ) {
				$out['moksafocou_status'] = __( 'Status', 'moksafocou' );
				$placed                   = true;
			}
		}
		if ( ! $placed ) {
			$out['moksafocou_status'] = __( 'Status', 'moksafocou' );
		}
		return $out;
	}

	/**
	 * @param string $column
	 * @param int    $post_id
	 */
	public static function render_column( $column, $post_id ): void {
		if ( 'moksafocou_status' !== $column ) {
			return;
		}
		$enabled = 'publish' === get_post_status( (int) $post_id );
		$style   = $enabled
			? 'background:#e6f4ea;color:#137333;'
			: 'background:#f1f1f1;color:#6b6b6b;';
		printf(
			'<span class="moksafocou-status" style="display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;%1$s">%2$s</span>',
			esc_attr( $style ),
			esc_html( $enabled ? __( 'Enable', 'moksafocou' ) : __( 'Disable', 'moksafocou' ) )
		);
	}

	/**
	 * @param array<string,string> $actions
	 * @param mixed                $post
	 * @return array<string,string>
	 */
	public static function row_actions( $actions, $post ): array {
		$actions = is_array( $actions ) ? $actions : array();
		if ( $post instanceof \WP_Post && 'shop_coupon' === $post->post_type && current_user_can( self::CAP ) ) {
			$url                       = wp_nonce_url(
				admin_url( 'admin-post.php?action=moksafocou_dup_coupon&id=' . (int) $post->ID ),
				self::NONCE
			);
			$actions['moksafocou_dup'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Copy', 'moksafocou' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public static function bulk_actions( $actions ): array {
		$actions                       = is_array( $actions ) ? $actions : array();
		$actions['moksafocou_enable']  = __( 'Enable', 'moksafocou' );
		$actions['moksafocou_disable'] = __( 'Disable', 'moksafocou' );
		return $actions;
	}

	/**
	 * @param string         $redirect WP-supplied redirect URL (already nonce-validated by core).
	 * @param string         $action   Bulk action key.
	 * @param array<int,int> $ids      Selected post ids.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $ids ): string {
		$redirect = (string) $redirect;
		if ( ! in_array( $action, array( 'moksafocou_enable', 'moksafocou_disable' ), true ) || ! current_user_can( self::CAP ) ) {
			return $redirect;
		}
		$enable = 'moksafocou_enable' === $action;
		$count  = 0;
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( 'shop_coupon' !== get_post_type( $id ) ) {
				continue;
			}
			CouponService::set_status( $id, $enable );
			++$count;
		}
		return add_query_arg( 'moksafocou_bulk', ( $enable ? 'on-' : 'off-' ) . $count, $redirect );
	}

	public static function handle_duplicate(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksafocou' ) );
		}
		check_admin_referer( self::NONCE );
		$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'edit.php?post_type=shop_coupon' );
		}

		$prepared = CouponOps::duplicate_prepare( array( 'code_or_id' => (string) $id ) );
		if ( $prepared instanceof \WP_Error ) {
			wp_safe_redirect( add_query_arg( 'moksafocou_dup', 'err', $back ) );
			exit;
		}
		$result = CouponOps::duplicate_apply( $prepared );
		if ( $result instanceof \WP_Error ) {
			wp_safe_redirect( add_query_arg( 'moksafocou_dup', 'err', $back ) );
			exit;
		}
		// Open the new draft for review.
		wp_safe_redirect( add_query_arg( 'moksafocou_dup', 'ok', admin_url( 'post.php?post=' . (int) $result['id'] . '&action=edit' ) ) );
		exit;
	}

	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'shop_coupon' !== $screen->post_type ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only result flags from a redirect, no state change.
		if ( isset( $_GET['moksafocou_dup'] ) ) {
			$dup = sanitize_key( wp_unslash( $_GET['moksafocou_dup'] ) );
			if ( 'ok' === $dup ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Copied as a draft coupon with usage count reset to zero; you can review it before enabling.', 'moksafocou' ) . '</p></div>';
			} elseif ( 'err' === $dup ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to copy the coupon.', 'moksafocou' ) . '</p></div>';
			}
		}
		if ( isset( $_GET['moksafocou_bulk'] ) ) {
			$parts = explode( '-', sanitize_key( wp_unslash( $_GET['moksafocou_bulk'] ) ) );
			$count = isset( $parts[1] ) ? (int) $parts[1] : 0;
			$label = ( 'on' === ( $parts[0] ?? '' ) ) ? __( 'Enable', 'moksafocou' ) : __( 'Disable', 'moksafocou' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: 1: number of coupons, 2: 啟用/停用 label. */
					__( '%2$s %1$d coupon(s).', 'moksafocou' ),
					$count,
					$label
				)
			) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
