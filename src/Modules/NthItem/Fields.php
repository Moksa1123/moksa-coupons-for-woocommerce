<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\NthItem;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Admin\FieldsSaveGuard;
use Moksafocou\Admin\FieldsHelpers;

defined( 'ABSPATH' ) || exit;

/**
 * 'Nth-item discount' coupon edit-screen panel. Relevant only when the coupon's discount type is
 * moksafocou_nth_item (nthitem-admin.js shows/hides the tab on #discount_type change). Uses its
 * OWN nonce so it coexists with the other panels. All persistence goes through NthItemMeta.
 */
final class Fields {

	use FieldsSaveGuard;

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_nthitem_nonce';

	private function action( int $id ): string {
		return 'moksafocou_save_nthitem_coupon_' . $id;
	}

	/**
	 * @return array<int,array{id:string,title:string,class:array<int,string>,render:callable}>
	 */
	public function sections(): array {
		return array(
			array(
				'id'     => 'moksafocou_nth_item',
				'title'  => __( 'Nth-item discount', 'moksa-coupons-for-woocommerce' ),
				'class'  => array( 'moksafocou_nth_item_tab' ),
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
		$cfg = NthItemMeta::read( (int) $post->ID );

		echo '<div class="options_group">';
		echo '<p class="form-field"><strong>' . esc_html__( 'Applicable products (leave empty = whole site)', 'moksa-coupons-for-woocommerce' ) . '</strong></p>';
		FieldsHelpers::product_select( Keys::NTH_PRODUCT_IDS, __( 'Specific products', 'moksa-coupons-for-woocommerce' ), $cfg['product_ids'] );
		FieldsHelpers::category_select( Keys::NTH_CATEGORY_IDS, __( 'Specific categories', 'moksa-coupons-for-woocommerce' ), $cfg['category_ids'] );
		woocommerce_wp_select(
			array(
				'id'      => Keys::NTH_GROUP_BY,
				'value'   => $cfg['group_by'],
				'label'   => __( 'Calculation method', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'cart'    => __( 'Combine the whole cart in the calculation', 'moksa-coupons-for-woocommerce' ),
					'product' => __( 'Count each product separately', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		echo '</div>';

		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::NTH_N,
				'value'             => $cfg['n'],
				'label'             => __( 'Discount one item per how many items (N)', 'moksa-coupons-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '2',
					'step' => '1',
				),
				'desc_tip'          => true,
				'description'       => __( 'Example: discount the second item → enter 2.', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => Keys::NTH_REWARD_MODE,
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
				'id'                => Keys::NTH_REWARD_VALUE,
				'value'             => $cfg['reward_value'],
				'label'             => __( 'Discount amount', 'moksa-coupons-for-woocommerce' ),
				'desc_tip'          => true,
				'description'       => __( 'Percentage is the "discount %": second item 40% off → enter 40 (40% off, pay 60%). Fixed per item: amount. Leave empty for free.', 'moksa-coupons-for-woocommerce' ),
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
				'id'      => Keys::NTH_DEAL_MODE,
				'value'   => $cfg['deal_mode'],
				'label'   => __( 'Usage count', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'repeat' => __( 'Repeatable (discount one item for every N items)', 'moksa-coupons-for-woocommerce' ),
					'once'   => __( 'Apply only once', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::NTH_REPEAT_LIMIT,
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
		woocommerce_wp_text_input(
			array(
				'id'          => Keys::NTH_NOTICE_MSG,
				'value'       => $cfg['notice_msg'],
				'label'       => __( 'Add-more prompt message', 'moksa-coupons-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'You can use {nth_n} {coupon_code}. Leave empty to use the default message.', 'moksa-coupons-for-woocommerce' ),
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
			'product_ids'  => isset( $_POST[ Keys::NTH_PRODUCT_IDS ] ) ? array_map( 'absint', (array) wp_unslash( $_POST[ Keys::NTH_PRODUCT_IDS ] ) ) : array(),
			'category_ids' => isset( $_POST[ Keys::NTH_CATEGORY_IDS ] ) ? array_map( 'absint', (array) wp_unslash( $_POST[ Keys::NTH_CATEGORY_IDS ] ) ) : array(),
			'group_by'     => isset( $_POST[ Keys::NTH_GROUP_BY ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::NTH_GROUP_BY ] ) ) : 'cart',
			'n'            => isset( $_POST[ Keys::NTH_N ] ) ? absint( wp_unslash( $_POST[ Keys::NTH_N ] ) ) : 2,
			'reward_mode'  => isset( $_POST[ Keys::NTH_REWARD_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::NTH_REWARD_MODE ] ) ) : 'percent',
			'reward_value' => isset( $_POST[ Keys::NTH_REWARD_VALUE ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::NTH_REWARD_VALUE ] ) ) : 0,
			'deal_mode'    => isset( $_POST[ Keys::NTH_DEAL_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::NTH_DEAL_MODE ] ) ) : 'repeat',
			'repeat_limit' => isset( $_POST[ Keys::NTH_REPEAT_LIMIT ] ) ? absint( wp_unslash( $_POST[ Keys::NTH_REPEAT_LIMIT ] ) ) : 0,
			'notice_msg'   => isset( $_POST[ Keys::NTH_NOTICE_MSG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::NTH_NOTICE_MSG ] ) ) : '',
		);

		NthItemMeta::write( $post_id, NthItemMeta::sanitize( $raw ) );
	}
}
