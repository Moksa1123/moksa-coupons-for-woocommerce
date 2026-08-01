<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\UrlCoupons;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Admin\FieldsSaveGuard;

defined( 'ABSPATH' ) || exit;

/**
 * 'Coupon URL' coupon edit-screen tab/panel. Uses its OWN nonce field name + action
 * (NOT the conditions module's) so both panels can coexist without duplicate DOM
 * ids or a dropped save when both modules are enabled. The QR preview is mounted
 * client-side (qr-admin.js fetches the inline SVG over the gated REST route).
 */
final class Fields {

	use FieldsSaveGuard;

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_url_nonce';

	private function action( int $id ): string {
		return 'moksafocou_save_url_coupon_' . $id;
	}

	/**
	 * @return array<int,array{id:string,title:string,render:callable}>
	 */
	public function sections(): array {
		return array(
			array(
				'id'     => 'moksafocou_url',
				'title'  => __( 'Coupon URL', 'moksafocou' ),
				'render' => function (): void {
					$this->render_url_panel();
				},
			),
		);
	}

	private function render_url_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id = (int) $post->ID;

		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::URL_ENABLED,
				'value'       => get_post_meta( $id, Keys::URL_ENABLED, true ),
				'label'       => __( 'Enable coupon URL', 'moksafocou' ),
				'description' => __( 'Generate a dedicated URL / QR code that customers can click or scan to automatically apply this coupon to the cart.', 'moksafocou' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => Keys::URL_SLUG,
				'value'       => get_post_meta( $id, Keys::URL_SLUG, true ),
				'label'       => __( 'Custom URL slug', 'moksafocou' ),
				'desc_tip'    => true,
				'description' => __( 'Optional. Leave blank to use the coupon code. Must be unique per coupon.', 'moksafocou' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => Keys::URL_REDIRECT,
				'value'       => get_post_meta( $id, Keys::URL_REDIRECT, true ),
				'label'       => __( 'Redirect URL after applying', 'moksafocou' ),
				'desc_tip'    => true,
				'description' => __( 'Optional. You can use the variables {coupon_code} {coupon_applied} {coupon_error}. Leave blank to redirect to the cart.', 'moksafocou' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::URL_SUCCESS_MSG,
				'value' => get_post_meta( $id, Keys::URL_SUCCESS_MSG, true ),
				'label' => __( 'Success message', 'moksafocou' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::URL_REDIRECT_ORIGIN,
				'value'       => get_post_meta( $id, Keys::URL_REDIRECT_ORIGIN, true ),
				'label'       => __( 'Return to the source page after applying', 'moksafocou' ),
				'description' => __( 'When checked, the customer is returned to the page they were originally on after the coupon is applied (ideal for buttons placed in posts / banners).', 'moksafocou' ),
			)
		);

		// QR + share-link preview mount (filled by qr-admin.js for saved, URL-enabled coupons).
		echo '<div class="options_group">';
		echo '<div id="moksafocou-url-share" data-coupon="' . esc_attr( (string) $id ) . '" style="padding:9px 12px;">';
		echo '<p class="description">' . esc_html__( 'After saving, the share link and QR code will appear here.', 'moksafocou' ) . '</p>';
		echo '</div></div>';
	}

	/**
	 * @param int        $post_id
	 * @param \WC_Coupon $coupon
	 */
	public function save( $post_id, $coupon ): void {
		$post_id = (int) $post_id;
		if ( ! $this->verify_save( $post_id, self::NONCE ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
		update_post_meta( $post_id, Keys::URL_ENABLED, isset( $_POST[ Keys::URL_ENABLED ] ) ? 'yes' : '' );
		update_post_meta( $post_id, Keys::URL_REDIRECT_ORIGIN, isset( $_POST[ Keys::URL_REDIRECT_ORIGIN ] ) ? 'yes' : '' );

		// Slug override: sanitize_title; reject collision with another coupon's slug/code.
		$slug = isset( $_POST[ Keys::URL_SLUG ] ) ? sanitize_title( wp_unslash( $_POST[ Keys::URL_SLUG ] ) ) : '';
		if ( '' === $slug || self::slug_is_unique( $slug, $post_id ) ) {
			if ( '' === $slug ) {
				delete_post_meta( $post_id, Keys::URL_SLUG );
			} else {
				update_post_meta( $post_id, Keys::URL_SLUG, $slug );
			}
		}

		// Redirect URL: keep {placeholder} tokens intact (esc_url_raw would encode the
		// braces to %7B/%7D and break str_replace at apply time), so sanitize as text
		// and only allow http(s). The final per-request URL is run through
		// wp_safe_redirect (which sanitizes) after placeholder expansion.
		$redirect = isset( $_POST[ Keys::URL_REDIRECT ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ Keys::URL_REDIRECT ] ) ) ) : '';
		if ( '' === $redirect || ! preg_match( '#^https?://#i', $redirect ) ) {
			delete_post_meta( $post_id, Keys::URL_REDIRECT );
		} else {
			update_post_meta( $post_id, Keys::URL_REDIRECT, $redirect );
		}

		$msg = isset( $_POST[ Keys::URL_SUCCESS_MSG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::URL_SUCCESS_MSG ] ) ) : '';
		if ( '' === $msg ) {
			delete_post_meta( $post_id, Keys::URL_SUCCESS_MSG );
		} else {
			update_post_meta( $post_id, Keys::URL_SUCCESS_MSG, $msg );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/** True if no OTHER shop_coupon already owns this slug (as post_name or URL_SLUG). */
	private static function slug_is_unique( string $slug, int $self_id ): bool {
		$by_name = get_page_by_path( $slug, OBJECT, 'shop_coupon' );
		if ( $by_name instanceof \WP_Post && (int) $by_name->ID !== $self_id ) {
			return false;
		}
		$found = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'any',
				'posts_per_page' => 2,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'       => Keys::URL_SLUG,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'     => $slug,
				'fields'         => 'ids',
			)
		);
		// Unique when no coupon OTHER than this one owns the slug. Fetch up to two ids and
		// drop self in PHP, rather than an exclusionary query param the VIP perf sniff flags.
		return array() === array_diff( array_map( 'intval', $found ), array( $self_id ) );
	}
}
