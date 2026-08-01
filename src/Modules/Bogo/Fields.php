<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Bogo;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Admin\FieldsSaveGuard;
use Moksafocou\Admin\FieldsHelpers;

defined( 'ABSPATH' ) || exit;

/**
 * 'Buy X Get Y (BOGO)' coupon edit-screen panel. Relevant only when the coupon's
 * discount type is moksafocou_bogo (bogo-admin.js shows/hides the tab on
 * #discount_type change). Uses its OWN nonce (moksafocou_bogo_nonce) so it coexists
 * with the conditions and url panels without duplicate DOM ids or a dropped save.
 * All persistence goes through BogoMeta so the admin and AI write paths cannot drift.
 */
final class Fields {

	use FieldsSaveGuard;

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_bogo_nonce';

	private function action( int $id ): string {
		return 'moksafocou_save_bogo_coupon_' . $id;
	}

	/**
	 * The BOGO panel only applies to the moksafocou_bogo discount type — bogo-admin.js
	 * shows/hides it on #discount_type change (the tab in tab mode, the postbox in
	 * metabox mode). The moksafocou_bogo_tab class is the JS hook for tab mode.
	 *
	 * @return array<int,array{id:string,title:string,class:array<int,string>,render:callable}>
	 */
	public function sections(): array {
		return array(
			array(
				'id'     => 'moksafocou_bogo',
				'title'  => __( 'Buy X Get Y', 'moksa-coupons-for-woocommerce' ),
				'class'  => array( 'moksafocou_bogo_tab' ),
				'render' => function (): void {
					$this->render_bogo_panel();
				},
			),
		);
	}

	private function render_bogo_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$cfg = BogoMeta::read( (int) $post->ID );

		echo '<div class="options_group">';
		echo '<p class="form-field"><strong>' . esc_html__( 'Purchase condition (Buy X)', 'moksa-coupons-for-woocommerce' ) . '</strong></p>';
		FieldsHelpers::product_select( Keys::BOGO_TRIGGER_PRODUCT_IDS, __( 'Specific products', 'moksa-coupons-for-woocommerce' ), $cfg['trigger_product_ids'] );
		FieldsHelpers::category_select( Keys::BOGO_TRIGGER_CATEGORY_IDS, __( 'Specific categories', 'moksa-coupons-for-woocommerce' ), $cfg['trigger_category_ids'] );
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::BOGO_TRIGGER_QTY,
				'value'             => $cfg['trigger_qty'],
				'label'             => __( 'Required purchase quantity', 'moksa-coupons-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
			)
		);
		echo '</div>';

		echo '<div class="options_group">';
		echo '<p class="form-field"><strong>' . esc_html__( 'Gift / discount (Get Y)', 'moksa-coupons-for-woocommerce' ) . '</strong></p>';
		FieldsHelpers::product_select( Keys::BOGO_REWARD_PRODUCT_IDS, __( 'Gift product', 'moksa-coupons-for-woocommerce' ), $cfg['reward_product_ids'] );
		FieldsHelpers::category_select( Keys::BOGO_REWARD_CATEGORY_IDS, __( 'Gift product category', 'moksa-coupons-for-woocommerce' ), $cfg['reward_category_ids'] );
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::BOGO_REWARD_QTY,
				'value'             => $cfg['reward_qty'],
				'label'             => __( 'Gift / discount quantity', 'moksa-coupons-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => Keys::BOGO_REWARD_MODE,
				'value'   => $cfg['reward_mode'],
				'label'   => __( 'Discount type', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'free'           => __( 'Free (100% off)', 'moksa-coupons-for-woocommerce' ),
					'percent'        => __( 'Percentage discount', 'moksa-coupons-for-woocommerce' ),
					'fixed_per_item' => __( 'Fixed discount per item', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::BOGO_REWARD_VALUE,
				'value'             => $cfg['reward_value'],
				'label'             => __( 'Discount amount', 'moksa-coupons-for-woocommerce' ),
				'desc_tip'          => true,
				'description'       => __( 'Percentage: 0–100; fixed per item: an amount. Leave empty when "Free" is selected.', 'moksa-coupons-for-woocommerce' ),
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
				'id'      => Keys::BOGO_DEAL_MODE,
				'value'   => $cfg['deal_mode'],
				'label'   => __( 'Usage count', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'once'   => __( 'Apply only once', 'moksa-coupons-for-woocommerce' ),
					'repeat' => __( 'Repeatable (give one set for each qualifying set in the cart)', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::BOGO_REPEAT_LIMIT,
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
				'id'          => Keys::BOGO_NOTICE_MSG,
				'value'       => $cfg['notice_msg'],
				'label'       => __( 'Prompt message (when the gift is not added)', 'moksa-coupons-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'You can use {bogo_qty} {coupon_code}. Leave empty to use the default message.', 'moksa-coupons-for-woocommerce' ),
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
		if ( ! $this->verify_save( $post_id, self::NONCE ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; every value is sanitized in BogoMeta::sanitize (absint id-lists, int/float scalars, sanitize_text_field text).
		$raw = array(
			'trigger_product_ids'  => isset( $_POST[ Keys::BOGO_TRIGGER_PRODUCT_IDS ] ) ? wp_unslash( $_POST[ Keys::BOGO_TRIGGER_PRODUCT_IDS ] ) : array(),
			'trigger_category_ids' => isset( $_POST[ Keys::BOGO_TRIGGER_CATEGORY_IDS ] ) ? wp_unslash( $_POST[ Keys::BOGO_TRIGGER_CATEGORY_IDS ] ) : array(),
			'trigger_qty'          => isset( $_POST[ Keys::BOGO_TRIGGER_QTY ] ) ? wp_unslash( $_POST[ Keys::BOGO_TRIGGER_QTY ] ) : 1,
			'reward_product_ids'   => isset( $_POST[ Keys::BOGO_REWARD_PRODUCT_IDS ] ) ? wp_unslash( $_POST[ Keys::BOGO_REWARD_PRODUCT_IDS ] ) : array(),
			'reward_category_ids'  => isset( $_POST[ Keys::BOGO_REWARD_CATEGORY_IDS ] ) ? wp_unslash( $_POST[ Keys::BOGO_REWARD_CATEGORY_IDS ] ) : array(),
			'reward_qty'           => isset( $_POST[ Keys::BOGO_REWARD_QTY ] ) ? wp_unslash( $_POST[ Keys::BOGO_REWARD_QTY ] ) : 1,
			'reward_mode'          => isset( $_POST[ Keys::BOGO_REWARD_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::BOGO_REWARD_MODE ] ) ) : 'percent',
			'reward_value'         => isset( $_POST[ Keys::BOGO_REWARD_VALUE ] ) ? wp_unslash( $_POST[ Keys::BOGO_REWARD_VALUE ] ) : 0,
			'deal_mode'            => isset( $_POST[ Keys::BOGO_DEAL_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::BOGO_DEAL_MODE ] ) ) : 'once',
			'repeat_limit'         => isset( $_POST[ Keys::BOGO_REPEAT_LIMIT ] ) ? wp_unslash( $_POST[ Keys::BOGO_REPEAT_LIMIT ] ) : 0,
			'notice_msg'           => isset( $_POST[ Keys::BOGO_NOTICE_MSG ] ) ? wp_unslash( $_POST[ Keys::BOGO_NOTICE_MSG ] ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		BogoMeta::write( $post_id, BogoMeta::sanitize( $raw ) );
	}
}
