<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\ShippingOverride;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Admin\FieldsSaveGuard;

defined( 'ABSPATH' ) || exit;

/**
 * 'Shipping override' coupon edit-screen tab: when applied, rewrite shipping rates to free /
 * percent off / fixed off. Dedicated nonce. Distinct from WC core's free_shipping flag.
 */
final class Fields {

	use FieldsSaveGuard;

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_shipping_nonce';

	private function action( int $id ): string {
		return 'moksafocou_save_shipping_coupon_' . $id;
	}

	/**
	 * Coupon-settings sections for the CouponSections coordinator (rendered as a
	 * coupon-data tab by default, or as a standalone metabox when enabled).
	 *
	 * @return array<int,array{id:string,title:string,render:callable}>
	 */
	public function sections(): array {
		return array(
			array(
				'id'     => 'moksafocou_shipping',
				'title'  => __( 'Shipping override', 'moksa-coupons-for-woocommerce' ),
				'render' => function (): void {
					$this->render_shipping_panel();
				},
			),
		);
	}

	private function render_shipping_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$cfg = ShipConfig::read( new \WC_Coupon( (int) $post->ID ) );

		echo '<p class="description" style="margin:8px 12px;">' . esc_html__( 'Overrides the shipping cost of all shipping methods when this coupon is applied. Different from WooCommerce\'s native "Allow free shipping" (which only enables dedicated free-shipping methods).', 'moksa-coupons-for-woocommerce' ) . '</p>';
		woocommerce_wp_select(
			array(
				'id'      => Keys::SHIP_MODE,
				'value'   => $cfg['mode'],
				'label'   => __( 'Shipping override type', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'none'    => __( 'No override', 'moksa-coupons-for-woocommerce' ),
					'free'    => __( 'Free shipping', 'moksa-coupons-for-woocommerce' ),
					'percent' => __( 'Shipping percentage discount', 'moksa-coupons-for-woocommerce' ),
					'fixed'   => __( 'Shipping fixed discount', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::SHIP_VALUE,
				'value'             => $cfg['value'],
				'label'             => __( 'Discount amount', 'moksa-coupons-for-woocommerce' ),
				'desc_tip'          => true,
				'description'       => __( 'Percentage: 0–100; Fixed: discount amount. Not required when "Free shipping" or "No override" is selected.', 'moksa-coupons-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
			)
		);
	}

	/**
	 * @param int        $post_id
	 * @param \WC_Coupon $coupon
	 */
	public function save( $post_id, $coupon ): void {
		$post_id = (int) $post_id;
		if ( ! current_user_can( self::CAP, $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), $this->action( $post_id ) ) ) {
			return;
		}

		$mode = isset( $_POST[ Keys::SHIP_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::SHIP_MODE ] ) ) : 'none';
		update_post_meta( $post_id, Keys::SHIP_MODE, ShipConfig::mode( $mode ) );

		$value = isset( $_POST[ Keys::SHIP_VALUE ] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ Keys::SHIP_VALUE ] ) ) ) : 0.0;
		update_post_meta( $post_id, Keys::SHIP_VALUE, (string) max( 0.0, $value ) );
	}
}
