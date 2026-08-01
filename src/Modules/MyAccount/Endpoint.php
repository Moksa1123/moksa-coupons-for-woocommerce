<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\MyAccount;

use Moksafocou\Modules\Frontend\CouponCard;

defined( 'ABSPATH' ) || exit;

/**
 * The 'My coupons' WooCommerce My Account endpoint: registers the rewrite endpoint + menu item and
 * renders the logged-in customer's own coupons as cards (reusing the front-end coupon-card markup
 * and copy/apply behaviour).
 */
final class Endpoint {

	/** Endpoint + menu-item key (also the URL segment under /my-account/). */
	public const SLUG = 'my-coupons';

	/** Shared asset handle (also registered by the front-end shortcode). */
	private const HANDLE = 'moksafocou-coupon-cards';

	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::SLUG, EP_ROOT | EP_PAGES );
	}

	/**
	 * Insert 'My coupons' just before the logout link.
	 *
	 * @param array<string,string> $items
	 * @return array<string,string>
	 */
	public static function add_menu_item( array $items ): array {
		$out = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key && ! isset( $out[ self::SLUG ] ) ) {
				$out[ self::SLUG ] = __( 'My coupons', 'moksafocou' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ self::SLUG ] ) ) {
			$out[ self::SLUG ] = __( 'My coupons', 'moksafocou' );
		}
		return $out;
	}

	/** Render the customer's coupon list inside the account content area. */
	public static function render(): void {
		$user = wp_get_current_user();
		if ( ! $user || 0 === (int) $user->ID ) {
			echo '<p>' . esc_html__( 'Please log in to view your coupons.', 'moksafocou' ) . '</p>';
			return;
		}

		$now   = time();
		$cards = '';
		foreach ( CouponQuery::owned_ids( (int) $user->ID, (string) $user->user_email ) as $id ) {
			$coupon  = new \WC_Coupon( $id );
			$expires = $coupon->get_date_expires();
			$ok      = CouponQuery::should_display(
				(string) get_post_status( $id ),
				$expires ? $expires->getTimestamp() + DAY_IN_SECONDS : null,
				(int) $coupon->get_usage_count(),
				(int) $coupon->get_usage_limit(),
				$now
			);
			if ( $ok ) {
				$cards .= CouponCard::render( $coupon );
			}
		}

		if ( '' === $cards ) {
			echo '<p class="moksafocou-myaccount-empty">' . esc_html__( 'You currently have no exclusive coupons.', 'moksafocou' ) . '</p>';
			return;
		}

		echo '<p class="moksafocou-myaccount-intro">' . esc_html__( 'Here are the coupons exclusive to you; you can copy the code or apply it in one click:', 'moksafocou' ) . '</p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CouponCard::render() escapes every dynamic value; the surrounding markup is static.
		echo '<div class="moksafocou-coupons moksafocou-myaccount-coupons">' . $cards . '</div>';
	}

	/** Load the shared coupon-card CSS/JS on the account page (copy button + layout). */
	public static function enqueue(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		$css = 'src/Modules/Frontend/assets/css/coupon-cards.css';
		$js  = 'src/Modules/Frontend/assets/js/coupon-cards.js';
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_register_style( self::HANDLE, MOKSAFOCOU_PLUGIN_URL . $css, array(), self::ver( $css ) );
		}
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_register_script( self::HANDLE, MOKSAFOCOU_PLUGIN_URL . $js, array(), self::ver( $js ), true );
		}
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	private static function ver( string $rel ): string {
		$path = MOKSAFOCOU_PLUGIN_DIR . $rel;
		return file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOCOU_VERSION;
	}
}
