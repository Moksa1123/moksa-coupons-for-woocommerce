<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\FreeGift;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Admin\FieldsSaveGuard;

defined( 'ABSPATH' ) || exit;

/**
 * 'Gift' coupon edit-screen tab: pick one product to auto-add when the coupon is
 * applied, its quantity, and its price (free / percent / fixed). Dedicated nonce.
 */
final class Fields {

	use FieldsSaveGuard;

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_gift_nonce';

	private function action( int $id ): string {
		return 'moksafocou_save_gift_coupon_' . $id;
	}

	/**
	 * @return array<int,array{id:string,title:string,render:callable}>
	 */
	public function sections(): array {
		return array(
			array(
				'id'     => 'moksafocou_gift',
				'title'  => __( 'Gift', 'moksa-coupons-for-woocommerce' ),
				'render' => function (): void {
					$this->render_gift_panel();
				},
			),
		);
	}

	private function render_gift_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$cfg = GiftConfig::read( (int) $post->ID );

		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::GIFT_ENABLED,
				'value'       => $cfg['enabled'] ? 'yes' : '',
				'label'       => __( 'Enable gift', 'moksa-coupons-for-woocommerce' ),
				'description' => __( 'When this coupon is applied, automatically add the gift below to the cart (customers cannot change the quantity or remove it; it is withdrawn automatically when the coupon is removed).', 'moksa-coupons-for-woocommerce' ),
			)
		);
		$this->product_field( Keys::GIFT_PRODUCT_ID, __( 'Gift product', 'moksa-coupons-for-woocommerce' ), $cfg['product_id'] );
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::GIFT_QTY,
				'value'             => $cfg['qty'],
				'label'             => __( 'Quantity', 'moksa-coupons-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => Keys::GIFT_MODE,
				'value'   => $cfg['mode'],
				'label'   => __( 'Gift pricing', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'free'    => __( 'Free', 'moksa-coupons-for-woocommerce' ),
					'percent' => __( 'Percentage discount', 'moksa-coupons-for-woocommerce' ),
					'fixed'   => __( 'Fixed discount per item', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::GIFT_VALUE,
				'value'             => $cfg['value'],
				'label'             => __( 'Discount amount', 'moksa-coupons-for-woocommerce' ),
				'desc_tip'          => true,
				'description'       => __( 'Percentage: 0–100; fixed per item: discount amount. Leave empty when "Free" is selected.', 'moksa-coupons-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
			)
		);
	}

	private function product_field( string $id, string $label, int $selected ): void {
		echo '<p class="form-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<select class="wc-product-search" style="width:50%;" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" data-placeholder="' . esc_attr__( 'Search products…', 'moksa-coupons-for-woocommerce' ) . '" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">';
		if ( $selected > 0 ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $selected ) : null;
			if ( $product ) {
				echo '<option value="' . esc_attr( (string) $selected ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
			}
		}
		echo '</select></p>';
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
		update_post_meta( $post_id, Keys::GIFT_ENABLED, isset( $_POST[ Keys::GIFT_ENABLED ] ) ? 'yes' : '' );

		$pid = isset( $_POST[ Keys::GIFT_PRODUCT_ID ] ) ? absint( wp_unslash( $_POST[ Keys::GIFT_PRODUCT_ID ] ) ) : 0;
		if ( $pid > 0 ) {
			update_post_meta( $post_id, Keys::GIFT_PRODUCT_ID, $pid );
		} else {
			delete_post_meta( $post_id, Keys::GIFT_PRODUCT_ID );
		}

		update_post_meta( $post_id, Keys::GIFT_QTY, isset( $_POST[ Keys::GIFT_QTY ] ) ? max( 1, absint( wp_unslash( $_POST[ Keys::GIFT_QTY ] ) ) ) : 1 );

		$mode = isset( $_POST[ Keys::GIFT_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::GIFT_MODE ] ) ) : 'free';
		update_post_meta( $post_id, Keys::GIFT_MODE, GiftConfig::mode( $mode ) );

		$value = isset( $_POST[ Keys::GIFT_VALUE ] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ Keys::GIFT_VALUE ] ) ) ) : 0.0;
		update_post_meta( $post_id, Keys::GIFT_VALUE, (string) max( 0.0, $value ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
