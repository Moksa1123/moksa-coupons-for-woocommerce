<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Frontend;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Admin\FieldsSaveGuard;

defined( 'ABSPATH' ) || exit;

/**
 * 'Front-end display' coupon edit-screen tab: opt the coupon into the public
 * [moksafocou_coupons] card list and set its marketing label. Dedicated nonce.
 */
final class Fields {

	use FieldsSaveGuard;

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_frontend_nonce';

	private function action( int $id ): string {
		return 'moksafocou_save_frontend_coupon_' . $id;
	}

	/**
	 * @return array<int,array{id:string,title:string,render:callable}>
	 */
	public function sections(): array {
		return array(
			array(
				'id'     => 'moksafocou_frontend',
				'title'  => __( 'Front-end display', 'moksa-coupons-for-woocommerce' ),
				'render' => function (): void {
					$this->render_frontend_panel();
				},
			),
		);
	}

	private function render_frontend_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id = (int) $post->ID;

		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::SHOW_IN_LIST,
				'value'       => get_post_meta( $id, Keys::SHOW_IN_LIST, true ),
				'label'       => __( 'Show in the front-end coupon list', 'moksa-coupons-for-woocommerce' ),
				'description' => __( 'When checked, this coupon appears in the card list of the [moksafocou_coupons] shortcode.', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => Keys::FRONT_LABEL,
				'value'       => get_post_meta( $id, Keys::FRONT_LABEL, true ),
				'label'       => __( 'Front-end description text', 'moksa-coupons-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Marketing description shown on the card (leave empty to use the coupon description).', 'moksa-coupons-for-woocommerce' ),
			)
		);

		echo '<p class="form-field"><strong>' . esc_html__( 'Urgency (limited time / limited quantity)', 'moksa-coupons-for-woocommerce' ) . '</strong></p>';
		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::COUNTDOWN_ENABLED,
				'value'       => get_post_meta( $id, Keys::COUNTDOWN_ENABLED, true ),
				'label'       => __( 'Show countdown timer', 'moksa-coupons-for-woocommerce' ),
				'description' => __( 'Show a countdown-to-expiry timer on the card (requires an expiry date or a scheduled end time).', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => Keys::COUNTDOWN_SOURCE,
				'value'   => get_post_meta( $id, Keys::COUNTDOWN_SOURCE, true ),
				'label'   => __( 'Countdown based on', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'expires'  => __( 'Coupon expiry date', 'moksa-coupons-for-woocommerce' ),
					'schedule' => __( 'Scheduled end time', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::STOCK_SHOW,
				'value'       => get_post_meta( $id, Keys::STOCK_SHOW, true ),
				'label'       => __( 'Show remaining quantity', 'moksa-coupons-for-woocommerce' ),
				'description' => __( 'Show "Only N left" based on the usage limit (requires a usage limit to be set first).', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::STOCK_THRESHOLD,
				'value'             => get_post_meta( $id, Keys::STOCK_THRESHOLD, true ),
				'label'             => __( 'Remaining-quantity hint threshold', 'moksa-coupons-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => true,
				'description'       => __( 'Show only when the remaining quantity is at or below this number (0 or empty = always show).', 'moksa-coupons-for-woocommerce' ),
			)
		);
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
		update_post_meta( $post_id, Keys::SHOW_IN_LIST, isset( $_POST[ Keys::SHOW_IN_LIST ] ) ? 'yes' : '' );
		$label = isset( $_POST[ Keys::FRONT_LABEL ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::FRONT_LABEL ] ) ) : '';
		if ( '' === $label ) {
			delete_post_meta( $post_id, Keys::FRONT_LABEL );
		} else {
			update_post_meta( $post_id, Keys::FRONT_LABEL, $label );
		}

		update_post_meta( $post_id, Keys::COUNTDOWN_ENABLED, isset( $_POST[ Keys::COUNTDOWN_ENABLED ] ) ? 'yes' : '' );
		update_post_meta( $post_id, Keys::STOCK_SHOW, isset( $_POST[ Keys::STOCK_SHOW ] ) ? 'yes' : '' );

		$source = isset( $_POST[ Keys::COUNTDOWN_SOURCE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::COUNTDOWN_SOURCE ] ) ) : '';
		if ( in_array( $source, array( 'expires', 'schedule' ), true ) ) {
			update_post_meta( $post_id, Keys::COUNTDOWN_SOURCE, $source );
		} else {
			delete_post_meta( $post_id, Keys::COUNTDOWN_SOURCE );
		}

		$threshold = isset( $_POST[ Keys::STOCK_THRESHOLD ] ) ? absint( wp_unslash( $_POST[ Keys::STOCK_THRESHOLD ] ) ) : 0;
		if ( $threshold > 0 ) {
			update_post_meta( $post_id, Keys::STOCK_THRESHOLD, $threshold );
		} else {
			delete_post_meta( $post_id, Keys::STOCK_THRESHOLD );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
