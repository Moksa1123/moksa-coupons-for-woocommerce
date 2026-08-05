<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\UrlCoupons;

use Moksafocou\Coupon\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end URL-coupon engine: the pretty endpoint /<endpoint>/<code> and the
 * optional ?coupon=CODE query string. Both apply a coupon to the visitor's own
 * cart and redirect.
 *
 * Security model (publicly_queryable shop_coupon is a hard boundary):
 *  - every shop_coupon main-query hit ALWAYS wp_safe_redirect()+exit — a coupon
 *    post can never render, so its code/description/_moksafocou_* meta never leak;
 *  - invalid / disabled / draft coupons all funnel through ONE generic invalid
 *    path (no existence oracle);
 *  - all redirects are wp_safe_redirect; merchant off-site targets are allow-listed
 *    transiently; request input is never reflected back onto the redirect URL.
 */
final class UrlHandler {

	/** @var bool Guards the query-string handler against re-entrancy / loops. */
	private static bool $query_done = false;

	public static function register(): void {
		// Must register synchronously (boot runs on woocommerce_init = init:0, the CPT
		// is registered at init:5) so the rewrite slug is in place.
		add_filter( 'woocommerce_register_post_type_shop_coupon', array( self::class, 'override_registration' ), 10, 1 );

		add_action( 'template_redirect', array( self::class, 'maybe_apply_pretty' ), 5 );

		if ( ShareService::query_enabled() ) {
			add_action( 'wp', array( self::class, 'maybe_apply_query_string' ) );
		}

		// Defense in depth: never let a shop_coupon single render, and keep it out of sitemaps.
		add_filter( 'template_include', array( self::class, 'block_coupon_render' ), 99 );
		add_filter( 'wp_sitemaps_post_types', array( self::class, 'hide_from_sitemap' ) );
	}

	/**
	 * @param array<string,mixed> $args shop_coupon registration args.
	 * @return array<string,mixed>
	 */
	public static function override_registration( $args ) {
		$args                       = is_array( $args ) ? $args : array();
		$args['publicly_queryable'] = true;
		$rewrite                    = isset( $args['rewrite'] ) && is_array( $args['rewrite'] ) ? $args['rewrite'] : array();
		$args['rewrite']            = array_merge(
			$rewrite,
			array(
				'slug'       => ShareService::endpoint(),
				'with_front' => false,
				'pages'      => false,
				'feeds'      => false,
			)
		);
		return $args;
	}

	/* ---------------- pretty endpoint ---------------- */

	public static function maybe_apply_pretty(): void {
		if ( ! self::is_front_get() ) {
			return;
		}

		// Detect the coupon request from the rewrite query var, NOT is_singular():
		// a slug-override URL (/coupon/<override>) sets post_type=shop_coupon but 404s
		// (no post has that post_name), so is_singular() is false there.
		global $wp_query;
		$query     = ( $wp_query instanceof \WP_Query && is_array( $wp_query->query ) ) ? $wp_query->query : array();
		$is_coupon = ( isset( $query['post_type'] ) && 'shop_coupon' === $query['post_type'] ) || is_singular( 'shop_coupon' );
		if ( ! $is_coupon ) {
			return;
		}

		// From here on this IS a shop_coupon request: we must redirect, never render.
		$slug = isset( $query['name'] ) ? (string) $query['name'] : (string) get_query_var( 'name' );
		if ( '' === $slug ) {
			$obj  = get_queried_object();
			$slug = $obj instanceof \WP_Post ? $obj->post_name : '';
		}

		$coupon = self::resolve_url_coupon( $slug );
		if ( ! $coupon instanceof \WC_Coupon ) {
			self::redirect_invalid();
			return;
		}

		$result = self::apply_to_cart( $coupon->get_code() );
		self::redirect_after_pretty( $coupon, $result['applied'] );
	}

	/**
	 * Resolve a publish + URL-enabled coupon from the request slug, or null. Draft /
	 * disabled / non-URL coupons all return null (single generic invalid funnel).
	 */
	private static function resolve_url_coupon( string $slug ): ?\WC_Coupon {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$post = get_page_by_path( $slug, OBJECT, 'shop_coupon' );
		$id   = $post instanceof \WP_Post ? (int) $post->ID : 0;

		if ( ! $id ) {
			// Fall back to a slug-override meta lookup (no raw SQL).
			$found = get_posts(
				array(
					'post_type'              => 'shop_coupon',
					'post_status'            => 'publish',
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key'               => Keys::URL_SLUG,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'             => $slug,
					'fields'                 => 'ids',
				)
			);
			$id    = ! empty( $found ) ? (int) $found[0] : 0;
		}

		if ( ! $id || 'publish' !== get_post_status( $id ) ) {
			return null;
		}
		$coupon = new \WC_Coupon( $id );
		if ( ! $coupon->get_id() || 'yes' !== $coupon->get_meta( Keys::URL_ENABLED, true ) ) {
			return null;
		}
		return $coupon;
	}

	private static function redirect_after_pretty( \WC_Coupon $coupon, bool $applied ): void {
		$target  = '';
		$message = (string) $coupon->get_meta( Keys::URL_REDIRECT, true );

		if ( '' !== trim( $message ) ) {
			$target = self::expand_placeholders( $message, $coupon, $applied );
		} elseif ( 'yes' === $coupon->get_meta( Keys::URL_REDIRECT_ORIGIN, true ) ) {
			$origin = self::safe_origin();
			$target = '' !== $origin ? $origin : '';
		}

		if ( '' === $target ) {
			$target = wc_get_cart_url();
		}

		// Surface a custom success message (apply_coupon adds its own otherwise).
		if ( $applied ) {
			$success = (string) $coupon->get_meta( Keys::URL_SUCCESS_MSG, true );
			if ( '' !== trim( $success ) ) {
				wc_clear_notices();
				wc_add_notice( wp_kses_post( $success ), 'success' );
			}
		}

		self::safe_redirect( $target );
	}

	/* ---------------- ?coupon=CODE query string ---------------- */

	public static function maybe_apply_query_string(): void {
		if ( self::$query_done || ! self::is_front_get() || ! is_main_query() ) {
			return;
		}
		// The pretty endpoint owns shop_coupon requests; don't double-fire.
		if ( is_singular( 'shop_coupon' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, idempotent apply link to the visitor's own cart; no nonce by design (mirrors a coupon link in an email/banner).
		$raw = isset( $_GET['coupon'] ) ? sanitize_text_field( wp_unslash( $_GET['coupon'] ) ) : '';
		if ( '' === $raw ) {
			return;
		}
		self::$query_done = true;

		$code = wc_format_coupon_code( $raw );
		if ( 0 === \Moksafocou\Coupon\CouponService::find_id_by_code( $code ) ) {
			return; // Unknown code → leave the page as-is (no notice spam / no oracle redirect).
		}

		$result = self::apply_to_cart( $code );

		$mode = (string) get_option( 'moksafocou_url_query_redirect', 'same_page' );
		switch ( $mode ) {
			case 'checkout':
				$target = wc_get_checkout_url();
				break;
			case 'cart':
				$target = wc_get_cart_url();
				break;
			case 'same_page':
			default:
				$here   = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				$target = remove_query_arg( 'coupon', home_url( $here ) );
				break;
		}

		if ( $result['applied'] ) {
			self::safe_redirect( $target );
		}
	}

	/* ---------------- shared apply + redirect helpers ---------------- */

	/**
	 * Load the cart (null for guests at this hook) and apply a coupon, reporting
	 * the real applied state from get_applied_coupons() — not apply_coupon()'s
	 * return, which can be true even when a Validator later rejects it.
	 *
	 * @return array{applied:bool}
	 */
	private static function apply_to_cart( string $code ): array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array( 'applied' => false );
		}
		WC()->session->set_customer_session_cookie( true );
		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! WC()->cart instanceof \WC_Cart ) {
			return array( 'applied' => false );
		}

		$formatted = wc_format_coupon_code( $code );
		if ( WC()->cart->has_discount( $formatted ) ) {
			return array( 'applied' => true ); // Already on the cart.
		}

		WC()->cart->apply_coupon( $formatted );
		return array( 'applied' => WC()->cart->has_discount( $formatted ) );
	}

	private static function redirect_invalid(): void {
		wc_clear_notices();
		wc_add_notice( __( 'This coupon is invalid or unavailable.', 'moksa-coupons-for-woocommerce' ), 'error' );
		self::safe_redirect( wc_get_cart_url() );
	}

	/**
	 * Replace the merchant-redirect placeholders with URL-safe values.
	 */
	private static function expand_placeholders( string $url, \WC_Coupon $coupon, bool $applied ): string {
		$error = '';
		if ( ! $applied ) {
			$notices = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : array();
			$last    = is_array( $notices ) && ! empty( $notices ) ? end( $notices ) : '';
			$error   = is_array( $last ) ? (string) ( $last['notice'] ?? '' ) : (string) $last;
		}
		return str_replace(
			array( '{coupon_code}', '{coupon_applied}', '{coupon_error}' ),
			array(
				rawurlencode( $coupon->get_code() ),
				$applied ? 'true' : 'false',
				rawurlencode( wp_strip_all_tags( $error ) ),
			),
			$url
		);
	}

	/**
	 * The HTTP referer, only if it is same-host and not our own coupon endpoint
	 * (prevents an apply→referer→apply loop); else ''.
	 */
	private static function safe_origin(): string {
		if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
			return '';
		}
		$referer = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		$r_host  = (string) wp_parse_url( $referer, PHP_URL_HOST );
		$h_host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $r_host || strtolower( $r_host ) !== strtolower( $h_host ) ) {
			return '';
		}
		$path = (string) wp_parse_url( $referer, PHP_URL_PATH );
		if ( false !== strpos( $path, '/' . ShareService::endpoint() . '/' ) ) {
			return '';
		}
		return $referer;
	}

	/**
	 * wp_safe_redirect + exit. An off-host target (a deliberate merchant redirect)
	 * is allow-listed transiently so wp_safe_redirect honours it without becoming a
	 * general open redirect.
	 */
	private static function safe_redirect( string $target ): void {
		if ( '' === $target ) {
			$target = wc_get_cart_url();
		}
		nocache_headers();

		$host = (string) wp_parse_url( $target, PHP_URL_HOST );
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$add  = null;
		if ( '' !== $host && strtolower( $host ) !== strtolower( $home ) ) {
			$add = static function ( $hosts ) use ( $host ) {
				$hosts[] = $host;
				return $hosts;
			};
			add_filter( 'allowed_redirect_hosts', $add );
			// Off-site redirect → don't carry our notices to another domain.
			wc_clear_notices();
		}

		// try/finally so a thrown error can never leave the host allow-listed for a
		// later request served by the same FPM worker (a latent open-redirect).
		try {
			wp_safe_redirect( $target );
		} finally {
			if ( null !== $add ) {
				remove_filter( 'allowed_redirect_hosts', $add );
			}
		}
		exit;
	}

	/* ---------------- defense in depth ---------------- */

	/**
	 * @param string $template
	 * @return string
	 */
	public static function block_coupon_render( $template ) {
		if ( is_singular( 'shop_coupon' ) ) {
			self::safe_redirect( wc_get_cart_url() );
		}
		return $template;
	}

	/**
	 * @param array<string,mixed> $post_types
	 * @return array<string,mixed>
	 */
	public static function hide_from_sitemap( $post_types ) {
		unset( $post_types['shop_coupon'] );
		return $post_types;
	}

	private static function is_front_get(): bool {
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		return 'GET' === $method;
	}
}
