<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\StackingControl;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Admin\FieldsSaveGuard;

defined( 'ABSPATH' ) || exit;

/**
 * 'Stacking control' coupon edit-screen tab: exclude other coupons, plus allow / disallow
 * lists of coupon codes. Dedicated nonce. Codes are stored normalized (lowercased,
 * comma-separated) via StackConfig::parse_codes.
 */
final class Fields {

	use FieldsSaveGuard;

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_stacking_nonce';

	private function action( int $id ): string {
		return 'moksafocou_save_stacking_coupon_' . $id;
	}

	/**
	 * @return array<int,array{id:string,title:string,render:callable}>
	 */
	public function sections(): array {
		return array(
			array(
				'id'     => 'moksafocou_stacking',
				'title'  => __( 'Stacking control', 'moksa-coupons-for-woocommerce' ),
				'render' => function (): void {
					$this->render_stacking_panel();
				},
			),
		);
	}

	private function render_stacking_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id = (int) $post->ID;

		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::STACK_EXCLUDE,
				'value'       => get_post_meta( $id, Keys::STACK_EXCLUDE, true ),
				'label'       => __( 'Cannot be combined with other coupons', 'moksa-coupons-for-woocommerce' ),
				'description' => __( 'When checked, this coupon cannot be applied if the cart already has other coupons (and vice versa); the "Allow combining" list below is the exception.', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'          => Keys::STACK_ALLOWED,
				'value'       => get_post_meta( $id, Keys::STACK_ALLOWED, true ),
				'label'       => __( 'Allowed combinable coupon codes', 'moksa-coupons-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Only effective when "Cannot be combined" above is checked: coupon codes in the list can still be used together with this coupon. Separate with commas or line breaks.', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'          => Keys::STACK_DISALLOWED,
				'value'       => get_post_meta( $id, Keys::STACK_DISALLOWED, true ),
				'label'       => __( 'Disallowed combinable coupon codes', 'moksa-coupons-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'These coupon codes cannot be used together with this coupon (regardless of whether "Cannot be combined" is checked). Separate with commas or line breaks.', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::STACK_MSG,
				'value' => get_post_meta( $id, Keys::STACK_MSG, true ),
				'label' => __( 'Message shown on conflict', 'moksa-coupons-for-woocommerce' ),
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
		update_post_meta( $post_id, Keys::STACK_EXCLUDE, isset( $_POST[ Keys::STACK_EXCLUDE ] ) ? 'yes' : '' );
		self::save_codes( $post_id, Keys::STACK_ALLOWED );
		self::save_codes( $post_id, Keys::STACK_DISALLOWED );

		$msg = isset( $_POST[ Keys::STACK_MSG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::STACK_MSG ] ) ) : '';
		if ( '' === $msg ) {
			delete_post_meta( $post_id, Keys::STACK_MSG );
		} else {
			update_post_meta( $post_id, Keys::STACK_MSG, $msg );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private static function save_codes( int $post_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in save().
		$raw   = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		$codes = StackConfig::parse_codes( $raw );
		if ( array() === $codes ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, implode( ', ', $codes ) );
		}
	}
}
