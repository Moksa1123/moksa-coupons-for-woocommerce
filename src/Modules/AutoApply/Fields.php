<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\AutoApply;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Admin\FieldsSaveGuard;

defined( 'ABSPATH' ) || exit;

/**
 * One "auto-apply" checkbox rendered inside the native General coupon options
 * (a whole tab would be overkill for a single boolean). Uses its OWN nonce so it
 * never piggybacks the core _wpnonce (which other plugins clobber). All persistence
 * goes through AutoApplyMeta::write, the single writer shared with the AI path.
 */
final class Fields {

	use FieldsSaveGuard;

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_autoapply_nonce';

	private function action( int $id ): string {
		return 'moksafocou_save_autoapply_coupon_' . $id;
	}

	/**
	 * @param mixed $coupon_id
	 * @param mixed $coupon
	 */
	public function render( $coupon_id = 0, $coupon = null ): void {
		$coupon_id = (int) $coupon_id;
		if ( ! $coupon_id ) {
			global $post;
			$coupon_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		}
		if ( ! $coupon_id ) {
			return;
		}
		wp_nonce_field( $this->action( $coupon_id ), self::NONCE, false );
		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::AUTO_APPLY,
				'value'       => get_post_meta( $coupon_id, Keys::AUTO_APPLY, true ),
				'label'       => __( 'Auto-apply', 'moksa-coupons-for-woocommerce' ),
				'description' => __( 'Automatically add this coupon when the customer opens the cart (it still applies only if the conditions are met). Note: auto-apply is not possible when "usage limit / usage limit per user / email restriction" is set.', 'moksa-coupons-for-woocommerce' ),
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
		// Explicit bool — unchecked must delete the meta + drop the cache id (un-toggle).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		AutoApplyMeta::write( $post_id, isset( $_POST[ Keys::AUTO_APPLY ] ) );
	}
}
