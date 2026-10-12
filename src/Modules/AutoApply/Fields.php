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

		// The generic note above is easy to read past. When the box IS ticked on a coupon that
		// cannot be auto-applied, say which setting is in the way, every time the screen opens,
		// until one of the two is changed.
		$coupon = $coupon instanceof \WC_Coupon ? $coupon : new \WC_Coupon( $coupon_id );
		if ( 'yes' === (string) get_post_meta( $coupon_id, Keys::AUTO_APPLY, true ) ) {
			self::render_blockers( AutoApplyMeta::blockers( $coupon ) );
		}
	}

	/**
	 * @param array<int,string> $reasons
	 */
	private static function render_blockers( array $reasons ): void {
		if ( array() === $reasons ) {
			return;
		}
		echo '<div class="moksafocou-autoapply-blocked" style="margin:4px 12px 12px 162px;padding:8px 12px;border-left:4px solid #dba617;background:#fcf9e8">';
		echo '<p style="margin:0 0 4px"><strong>' . esc_html__( 'Ticked, but this coupon will not be applied automatically:', 'moksa-coupons-for-woocommerce' ) . '</strong></p>';
		echo '<ul style="margin:0 0 4px 18px;list-style:disc">';
		foreach ( $reasons as $reason ) {
			echo '<li>' . esc_html( $reason ) . '</li>';
		}
		echo '</ul>';
		echo '<p style="margin:0">' . esc_html__( 'Customers can still enter the code by hand. To have it applied automatically, remove the setting above.', 'moksa-coupons-for-woocommerce' ) . '</p>';
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
		// Explicit bool — unchecked must delete the meta + drop the cache id (un-toggle).
		$on = isset( $_POST[ Keys::AUTO_APPLY ] );
		AutoApplyMeta::write( $post_id, $on );

		// WooCommerce has already written the coupon's own fields by the time this hook runs, so
		// $coupon reflects the limits as just saved.
		if ( $on && $coupon instanceof \WC_Coupon && array() !== AutoApplyMeta::blockers( $coupon ) ) {
			set_transient( self::notice_key(), 1, MINUTE_IN_SECONDS );
		}
	}

	private static function notice_key(): string {
		return 'moksafocou_autoapply_blocked_' . get_current_user_id();
	}

	/** One notice after the save redirect; the inline box carries the detail. */
	public static function notice(): void {
		if ( ! get_transient( self::notice_key() ) ) {
			return;
		}
		delete_transient( self::notice_key() );
		echo '<div class="notice notice-warning is-dismissible"><p>'
			. esc_html__( 'Saved — but this coupon will not be applied automatically. The reason is shown under the "Auto-apply" box.', 'moksa-coupons-for-woocommerce' )
			. '</p></div>';
	}
}
