<?php

declare( strict_types=1 );

namespace Moksafocou\Coupon\Meta;

use Moksafocou\Support\SiteTime;
use Moksafocou\Support\Tiers;
use Moksafocou\Support\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's coupon post-meta with the WordPress meta API so the values
 * that WooCommerce already round-trips through the REST `meta_data` array become
 * typed, sanitized and permission-checked.
 *
 * WHY: the `_moksafocou_*` keys are protected post-meta, but WooCommerce's coupon
 * REST controller exposes ALL coupon meta in `meta_data` (read + write) regardless of
 * the underscore prefix. That write path is otherwise UNSANITIZED. register_post_meta
 * here is pure hardening — it does NOT open any new surface (shop_coupon is not a
 * wp/v2 REST type), it just makes every write path (admin form, AI/MCP, WC REST,
 * WP-CLI) run the same sanitize_callback via sanitize_meta(), declares a typed schema
 * for discoverability, and gates edits to manage_woocommerce via auth_callback.
 *
 * The AI/MCP destructive gate (propose/apply + the expose-destructive option) is a
 * SEPARATE path and is intentionally untouched here.
 */
final class RestMeta {

	/** Capability required to edit these meta through the REST meta API. */
	private const CAP = 'manage_woocommerce';

	/**
	 * key => sanitizer "kind". Each kind maps to a REST type + a sanitize callback.
	 * Every Keys::all() entry must appear here (enforced by RestMetaTest).
	 *
	 * @return array<string,string>
	 */
	public static function definitions(): array {
		$bool = array(
			Keys::SCHEDULE_ENABLED,
			Keys::ROLE_ENABLED,
			Keys::MIN_SUBTOTAL_INCL_TAX,
			Keys::CUST_ENABLED,
			Keys::CUST_FIRST_ONLY,
			Keys::DAYTIME_ENABLED,
			Keys::URL_ENABLED,
			Keys::URL_REDIRECT_ORIGIN,
			Keys::AUTO_APPLY,
			Keys::GIFT_ENABLED,
			Keys::STACK_EXCLUDE,
			Keys::SHOW_IN_LIST,
			Keys::TIERS_ENABLED,
			Keys::SHIPREGION_ENABLED,
			Keys::PAYMENT_ENABLED,
			Keys::RULES_ENABLED,
			Keys::COUNTDOWN_ENABLED,
			Keys::STOCK_SHOW,
		);
		// Country codes (ISO alpha-2) must stay UPPERCASE; sanitize_key (key_list) would
		// lowercase them and break the WC_Customer::get_shipping_country() comparison.
		$code_list = array( Keys::SHIPREGION_COUNTRIES );
		// Advanced rule-builder tree, stored as a canonical JSON object.
		$rules = array( Keys::RULES );
		// Tiered-discount rows, stored as a canonical JSON array of { min_subtotal, min_qty,
		// percent } — its own sanitizer validates + canonicalises every write path.
		$tiers = array( Keys::TIERS );
		// Schedule start/end are wall-clock datetimes: normalised to 'Y-m-d H:i:s' on EVERY
		// write path (incl. REST/AI) so the time is never stored in a shape that drops it.
		$datetime = array(
			Keys::SCHEDULE_START,
			Keys::SCHEDULE_END,
		);
		$text     = array(
			Keys::SCHEDULE_MSG_START,
			Keys::SCHEDULE_MSG_END,
			Keys::NTH_NOTICE_MSG,
			Keys::ROLE_MSG,
			Keys::CART_MSG,
			Keys::CUST_MSG,
			Keys::PRODUCT_MSG,
			Keys::EXCL_MSG,
			Keys::DAYTIME_START,
			Keys::DAYTIME_END,
			Keys::DAYTIME_MSG,
			Keys::URL_REDIRECT,
			Keys::URL_SUCCESS_MSG,
			Keys::STACK_MSG,
			Keys::FRONT_LABEL,
			Keys::SHIPREGION_MSG,
			Keys::PAYMENT_MSG,
			Keys::RULES_MSG,
			Keys::CAMPAIGN,
		);
		$key      = array(
			Keys::ROLE_TYPE,
			Keys::REQ_PRODUCTS_MODE,
			Keys::REQ_CATEGORIES_MODE,
			Keys::BOGO_REWARD_MODE,
			Keys::BOGO_DEAL_MODE,
			Keys::GIFT_MODE,
			Keys::SHIP_MODE,
			Keys::TIERS_TARGET_MODE,
			Keys::TIERS_BASIS,
			Keys::SHIPREGION_MODE,
			Keys::PAYMENT_MODE,
			Keys::CASHBACK_MODE,
			Keys::COUNTDOWN_SOURCE,
			Keys::NTH_GROUP_BY,
			Keys::NTH_REWARD_MODE,
			Keys::NTH_DEAL_MODE,
			Keys::MIXMATCH_PRICE_MODE,
			Keys::MIXMATCH_DEAL_MODE,
		);
		$textarea = array(
			Keys::STACK_ALLOWED,
			Keys::STACK_DISALLOWED,
			Keys::BOGO_NOTICE_MSG,
			Keys::MIXMATCH_NOTICE_MSG,
		);
		$decimal  = array(
			Keys::MIN_SUBTOTAL,
			Keys::CUST_MIN_SPENT,
			Keys::CUST_MAX_SPENT,
			Keys::DISCOUNT_CAP,
			Keys::SHIP_VALUE,
			Keys::GIFT_VALUE,
			Keys::BOGO_REWARD_VALUE,
			Keys::CASHBACK_VALUE,
			Keys::NTH_REWARD_VALUE,
			Keys::MIXMATCH_PRICE_VALUE,
		);
		$int      = array(
			Keys::MIN_QTY,
			Keys::CUST_MIN_ORDERS,
			Keys::CUST_MAX_ORDERS,
			Keys::GIFT_QTY,
			Keys::GIFT_PRODUCT_ID,
			Keys::BOGO_TRIGGER_QTY,
			Keys::BOGO_REWARD_QTY,
			Keys::BOGO_REPEAT_LIMIT,
			Keys::STOCK_THRESHOLD,
			Keys::NTH_N,
			Keys::NTH_REPEAT_LIMIT,
			Keys::MIXMATCH_QTY,
			Keys::MIXMATCH_REPEAT_LIMIT,
		);
		$slug     = array( Keys::URL_SLUG );
		$int_list = array(
			Keys::REQ_PRODUCTS,
			Keys::REQ_CATEGORIES,
			Keys::EXCL_PRODUCTS,
			Keys::EXCL_CATEGORIES,
			Keys::BOGO_TRIGGER_PRODUCT_IDS,
			Keys::BOGO_TRIGGER_CATEGORY_IDS,
			Keys::BOGO_REWARD_PRODUCT_IDS,
			Keys::BOGO_REWARD_CATEGORY_IDS,
			Keys::TIERS_TARGET_PRODUCTS,
			Keys::TIERS_TARGET_CATEGORIES,
			Keys::NTH_PRODUCT_IDS,
			Keys::NTH_CATEGORY_IDS,
			Keys::MIXMATCH_PRODUCT_IDS,
			Keys::MIXMATCH_CATEGORY_IDS,
		);
		// Weekdays are 0–6 (0 = Sunday is VALID), so they need their own sanitizer —
		// the positive-id list filter would wrongly drop Sunday.
		$day_list = array( Keys::DAYTIME_DAYS );
		$key_list = array( Keys::ROLE_LIST, Keys::PAYMENT_METHODS );

		$map = array();
		$add = static function ( array $keys, string $kind ) use ( &$map ): void {
			foreach ( $keys as $k ) {
				$map[ $k ] = $kind;
			}
		};
		$add( $bool, 'bool' );
		$add( $datetime, 'datetime' );
		$add( $tiers, 'tiers' );
		$add( $rules, 'rules' );
		$add( $text, 'text' );
		$add( $key, 'key' );
		$add( $textarea, 'textarea' );
		$add( $decimal, 'decimal' );
		$add( $int, 'int' );
		$add( $slug, 'slug' );
		$add( $int_list, 'int_list' );
		$add( $day_list, 'day_list' );
		$add( $key_list, 'key_list' );
		$add( $code_list, 'code_list' );

		return $map;
	}

	/** Register every plugin coupon-meta key with the WP meta API. Idempotent. */
	public static function register(): void {
		foreach ( self::definitions() as $meta_key => $kind ) {
			register_post_meta( 'shop_coupon', $meta_key, self::args( $kind ) );
		}
	}

	/**
	 * Build the register_post_meta args for a sanitizer kind.
	 *
	 * @return array<string,mixed>
	 */
	private static function args( string $kind ): array {
		$base = array(
			'single'        => true,
			'auth_callback' => array( self::class, 'auth' ),
		);

		switch ( $kind ) {
			case 'int':
				return $base + array(
					'type'              => 'integer',
					'description'       => __( 'Moksa coupon settings (integer)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_int' ),
				);
			case 'int_list':
				return $base + array(
					'type'              => 'array',
					'description'       => __( 'Moksa coupon settings (ID list)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
					'sanitize_callback' => array( self::class, 'sanitize_int_list' ),
				);
			case 'day_list':
				return $base + array(
					'type'              => 'array',
					'description'       => __( 'Moksa coupon settings (weekday 0–6)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
					'sanitize_callback' => array( self::class, 'sanitize_day_list' ),
				);
			case 'key_list':
				return $base + array(
					'type'              => 'array',
					'description'       => __( 'Moksa coupon settings (code list)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'sanitize_callback' => array( self::class, 'sanitize_key_list' ),
				);
			case 'code_list':
				return $base + array(
					'type'              => 'array',
					'description'       => __( 'Moksa coupon settings (country code list, uppercase)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'sanitize_callback' => array( self::class, 'sanitize_code_list' ),
				);
			case 'bool':
				return $base + array(
					'type'              => 'string',
					'description'       => __( 'Moksa coupon settings (enable flag yes/empty)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_bool' ),
				);
			case 'key':
				return $base + array(
					'type'              => 'string',
					'description'       => __( 'Moksa coupon settings (mode code)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_key_value' ),
				);
			case 'textarea':
				return $base + array(
					'type'              => 'string',
					'description'       => __( 'Moksa coupon settings (multi-line text)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_textarea' ),
				);
			case 'slug':
				return $base + array(
					'type'              => 'string',
					'description'       => __( 'Moksa coupon settings (URL slug)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_slug' ),
				);
			case 'decimal':
				return $base + array(
					'type'              => 'string',
					'description'       => __( 'Moksa coupon settings (amount / number)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_text' ),
				);
			case 'datetime':
				return $base + array(
					'type'              => 'string',
					'description'       => __( 'Moksa coupon settings (schedule time, site time zone)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_datetime' ),
				);
			case 'tiers':
				return $base + array(
					'type'              => 'string',
					'description'       => __( 'Moksa coupon settings (tiered discount, JSON)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_tiers' ),
				);
			case 'rules':
				return $base + array(
					'type'              => 'string',
					'description'       => __( 'Moksa coupon settings (advanced rules, JSON)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_rules' ),
				);
			case 'text':
			default:
				return $base + array(
					'type'              => 'string',
					'description'       => __( 'Moksa coupon settings (text)', 'moksa-coupons-for-woocommerce' ),
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_text' ),
				);
		}
	}

	/** Edits via the REST meta API require shop-manager capability. */
	public static function auth(): bool {
		return current_user_can( self::CAP );
	}

	/* ---------------- sanitizers (run on EVERY write path) ---------------- */

	/**
	 * @param mixed $value
	 */
	public static function sanitize_bool( $value ): string {
		return 'yes' === $value ? 'yes' : '';
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_text( $value ): string {
		return sanitize_text_field( (string) $value );
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_key_value( $value ): string {
		return sanitize_key( (string) $value );
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_textarea( $value ): string {
		return sanitize_textarea_field( (string) $value );
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_slug( $value ): string {
		return sanitize_title( (string) $value );
	}

	/**
	 * Canonicalise a schedule datetime to 'Y-m-d H:i:s' wall-clock (site tz); '' when invalid.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_datetime( $value ): string {
		return SiteTime::normalize( (string) $value );
	}

	/**
	 * Canonicalise tiered-discount rows (array or JSON string) to a validated JSON string;
	 * '' when there are no valid rows.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_tiers( $value ): string {
		return Tiers::canonical_json( $value );
	}

	/**
	 * Canonicalise an advanced rule-builder tree (array or JSON string) to a validated JSON
	 * string; '' when there are no usable groups.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_rules( $value ): string {
		return Rules::canonical_json( $value );
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_int( $value ): int {
		return max( 0, (int) $value );
	}

	/**
	 * @param mixed $value
	 * @return array<int,int>
	 */
	public static function sanitize_int_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ints = array_map( static fn( $v ): int => max( 0, (int) $v ), $value );
		return array_values( array_filter( $ints, static fn( int $v ): bool => $v > 0 ) );
	}

	/**
	 * Weekday list: keep only 0–6 (0 = Sunday), de-duplicated and sorted.
	 *
	 * @param mixed $value
	 * @return array<int,int>
	 */
	public static function sanitize_day_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$days = array();
		foreach ( $value as $v ) {
			$n = (int) $v;
			if ( $n >= 0 && $n <= 6 && ! in_array( $n, $days, true ) ) {
				$days[] = $n;
			}
		}
		sort( $days );
		return $days;
	}

	/**
	 * @param mixed $value
	 * @return array<int,string>
	 */
	public static function sanitize_key_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$keys = array_map( static fn( $v ): string => sanitize_key( (string) $v ), $value );
		return array_values( array_filter( $keys, static fn( string $v ): bool => '' !== $v ) );
	}

	/**
	 * Uppercase ISO-3166-1 alpha-2 country codes, de-duplicated. Keeps case (unlike
	 * sanitize_key) so the codes match WC_Customer::get_shipping_country().
	 *
	 * @param mixed $value
	 * @return array<int,string>
	 */
	public static function sanitize_code_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$codes = array();
		foreach ( $value as $v ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $v ) ?? '' );
			if ( 2 === strlen( $code ) && ! in_array( $code, $codes, true ) ) {
				$codes[] = $code;
			}
		}
		return $codes;
	}
}
