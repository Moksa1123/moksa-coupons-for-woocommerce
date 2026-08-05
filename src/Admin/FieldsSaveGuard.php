<?php

declare( strict_types=1 );

namespace Moksafocou\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Emits the per-coupon save nonce field for every coupon-settings tab. The verification half is
 * inlined in each save() handler — an explicit current_user_can() plus wp_verify_nonce() against
 * $this->action( $post_id ) — so static analysis can see the nonce check directly in the method
 * that reads $_POST. This trait only outputs the matching hidden field.
 *
 * Consuming classes must define a `CAP` constant, a `NONCE` constant, and an
 * `action( int $id ): string` method (the per-coupon nonce action).
 */
trait FieldsSaveGuard {

	/**
	 * Emit the per-coupon save nonce as a hidden field. Rendered inside the coupon-edit metabox;
	 * a no-op outside a coupon post context. Paired with the inline wp_verify_nonce() in save().
	 */
	public function render_nonce(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		wp_nonce_field( $this->action( (int) $post->ID ), self::NONCE, false );
	}
}
