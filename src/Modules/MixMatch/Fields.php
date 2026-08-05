<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\MixMatch;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Admin\FieldsSaveGuard;
use Moksafocou\Admin\FieldsHelpers;

defined( 'ABSPATH' ) || exit;

/**
 * 'Mix & Match' coupon edit-screen panel. Relevant only when the coupon's discount type
 * is moksafocou_mixmatch (mixmatch-admin.js shows/hides the tab on #discount_type change). Uses its
 * OWN nonce. All persistence goes through MixMatchMeta.
 */
final class Fields {

	use FieldsSaveGuard;

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_mixmatch_nonce';

	private function action( int $id ): string {
		return 'moksafocou_save_mixmatch_coupon_' . $id;
	}

	/**
	 * @return array<int,array{id:string,title:string,class:array<int,string>,render:callable}>
	 */
	public function sections(): array {
		return array(
			array(
				'id'     => 'moksafocou_mixmatch',
				'title'  => __( 'Mix & Match', 'moksa-coupons-for-woocommerce' ),
				'class'  => array( 'moksafocou_mixmatch_tab' ),
				'render' => function (): void {
					$this->render_panel();
				},
			),
		);
	}

	private function render_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$cfg = MixMatchMeta::read( (int) $post->ID );

		echo '<div class="options_group">';
		echo '<p class="form-field"><strong>' . esc_html__( 'Selectable products (leave empty = whole site)', 'moksa-coupons-for-woocommerce' ) . '</strong></p>';
		FieldsHelpers::product_select( Keys::MIXMATCH_PRODUCT_IDS, __( 'Specific products', 'moksa-coupons-for-woocommerce' ), $cfg['product_ids'] );
		FieldsHelpers::category_select( Keys::MIXMATCH_CATEGORY_IDS, __( 'Specific categories', 'moksa-coupons-for-woocommerce' ), $cfg['category_ids'] );
		echo '</div>';

		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::MIXMATCH_QTY,
				'value'             => $cfg['qty'],
				'label'             => __( 'Number of items to pick (N)', 'moksa-coupons-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
				'desc_tip'          => true,
				'description'       => __( 'Pick 3 items → enter 3.', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => Keys::MIXMATCH_PRICE_MODE,
				'value'   => $cfg['price_mode'],
				'label'   => __( 'Pricing method', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'fixed_total' => __( 'Group fixed total', 'moksa-coupons-for-woocommerce' ),
					'percent'     => __( 'Group percentage discount', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::MIXMATCH_PRICE_VALUE,
				'value'             => $cfg['price_value'],
				'label'             => __( 'Price / discount amount', 'moksa-coupons-for-woocommerce' ),
				'desc_tip'          => true,
				'description'       => __( 'Fixed total: the total price for the group of N items (pick 3 for $299 → enter 299). Percentage: 0–100 (pick 5 at 25% off → enter 25).', 'moksa-coupons-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
			)
		);
		echo '</div>';

		echo '<div class="options_group">';
		woocommerce_wp_select(
			array(
				'id'      => Keys::MIXMATCH_DEAL_MODE,
				'value'   => $cfg['deal_mode'],
				'label'   => __( 'Usage count', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'repeat' => __( 'Repeatable (apply again for every N items)', 'moksa-coupons-for-woocommerce' ),
					'once'   => __( 'Apply only once', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::MIXMATCH_REPEAT_LIMIT,
				'value'             => $cfg['repeat_limit'],
				'label'             => __( 'Repeat limit', 'moksa-coupons-for-woocommerce' ),
				'desc_tip'          => true,
				'description'       => __( '0 = no limit. Applies only when "repeatable".', 'moksa-coupons-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'          => Keys::MIXMATCH_NOTICE_MSG,
				'value'       => $cfg['notice_msg'],
				'label'       => __( 'Add-more prompt message', 'moksa-coupons-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'You can use {mixmatch_qty} {coupon_code}. Leave empty to use the default message.', 'moksa-coupons-for-woocommerce' ),
			)
		);
		echo '</div>';
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

		$raw = array(
			'product_ids'  => isset( $_POST[ Keys::MIXMATCH_PRODUCT_IDS ] ) ? array_map( 'absint', (array) wp_unslash( $_POST[ Keys::MIXMATCH_PRODUCT_IDS ] ) ) : array(),
			'category_ids' => isset( $_POST[ Keys::MIXMATCH_CATEGORY_IDS ] ) ? array_map( 'absint', (array) wp_unslash( $_POST[ Keys::MIXMATCH_CATEGORY_IDS ] ) ) : array(),
			'qty'          => isset( $_POST[ Keys::MIXMATCH_QTY ] ) ? absint( wp_unslash( $_POST[ Keys::MIXMATCH_QTY ] ) ) : 1,
			'price_mode'   => isset( $_POST[ Keys::MIXMATCH_PRICE_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::MIXMATCH_PRICE_MODE ] ) ) : 'fixed_total',
			'price_value'  => isset( $_POST[ Keys::MIXMATCH_PRICE_VALUE ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::MIXMATCH_PRICE_VALUE ] ) ) : 0,
			'deal_mode'    => isset( $_POST[ Keys::MIXMATCH_DEAL_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::MIXMATCH_DEAL_MODE ] ) ) : 'repeat',
			'repeat_limit' => isset( $_POST[ Keys::MIXMATCH_REPEAT_LIMIT ] ) ? absint( wp_unslash( $_POST[ Keys::MIXMATCH_REPEAT_LIMIT ] ) ) : 0,
			'notice_msg'   => isset( $_POST[ Keys::MIXMATCH_NOTICE_MSG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::MIXMATCH_NOTICE_MSG ] ) ) : '',
		);

		MixMatchMeta::write( $post_id, MixMatchMeta::sanitize( $raw ) );
	}
}
