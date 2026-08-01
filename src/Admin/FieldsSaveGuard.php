<?php

declare( strict_types=1 );

namespace Moksafocou\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shared capability + nonce guard for every coupon-settings tab's save() handler. Each feature
 * module previously inlined the same eight lines (cap check, nonce read, verify); centralising
 * them keeps the security check identical everywhere and gives it a single place to test.
 *
 * Consuming classes must define a `CAP` constant (the capability to require) and an
 * `action( int $id ): string` method (the per-coupon nonce action) — every Fields class already
 * does. Call it as the first thing in save():
 *
 *     if ( ! $this->verify_save( $post_id, self::NONCE ) ) {
 *         return;
 *     }
 */
trait FieldsSaveGuard {

	/**
	 * True only when the current user may edit this coupon AND the posted nonce matches the
	 * per-coupon action. Reads $_POST[ $nonce_key ] solely to verify it.
	 */
	protected function verify_save( int $post_id, string $nonce_key ): bool {
		if ( ! current_user_can( self::CAP, $post_id ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read here only to verify on the next line.
		$nonce = isset( $_POST[ $nonce_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_key ] ) ) : '';
		return '' !== $nonce && (bool) wp_verify_nonce( $nonce, $this->action( $post_id ) );
	}

	/**
	 * Emit the per-coupon save nonce as a hidden field (no referer), paired with verify_save().
	 * Rendered inside the coupon-edit metabox; a no-op outside a coupon post context. Consuming
	 * classes previously inlined this identical method — it belongs next to its verify_save() half.
	 */
	public function render_nonce(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		wp_nonce_field( $this->action( (int) $post->ID ), self::NONCE, false );
	}
}
