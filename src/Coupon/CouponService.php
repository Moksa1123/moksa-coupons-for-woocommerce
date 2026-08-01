<?php

declare( strict_types=1 );

namespace Moksafocou\Coupon;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon read / validate / write engine around WC_Coupon. Validation
 * (normalize_and_validate) is pure and unit-testable; persistence wraps the
 * WooCommerce CRUD API. Native fields only in this phase.
 */
final class CouponService {

	public const DISCOUNT_TYPES = [ 'percent', 'fixed_cart', 'fixed_product' ];

	public const BOOL_FIELDS = [ 'individual_use', 'free_shipping', 'exclude_sale_items' ];

	public const INT_FIELDS = [ 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items' ];

	public const ID_LIST_FIELDS = [
		'product_ids',
		'excluded_product_ids',
		'product_categories',
		'excluded_product_categories',
	];

	// === Validation (pure) ===

	/**
	 * Normalize and validate raw input into WC_Coupon-ready fields.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error Normalized fields, or error.
	 */
	public static function normalize_and_validate( array $input, bool $is_update = false ) {
		$fields = [];

		$code = isset( $input['code'] ) ? sanitize_text_field( (string) $input['code'] ) : '';
		$code = trim( $code );
		if ( ! $is_update && '' === $code ) {
			return new \WP_Error( 'moksafocou_invalid_code', __( 'Coupon code cannot be empty.', 'moksafocou' ) );
		}
		if ( '' !== $code ) {
			$fields['code'] = $code;
		}

		if ( isset( $input['discount_type'] ) || ! $is_update ) {
			$type = isset( $input['discount_type'] ) ? sanitize_text_field( (string) $input['discount_type'] ) : 'fixed_cart';
			if ( ! in_array( $type, self::DISCOUNT_TYPES, true ) ) {
				return new \WP_Error(
					'moksafocou_invalid_type',
					sprintf(
						/* translators: %s: comma-separated list of valid discount types. */
						__( 'Discount type must be: %s.', 'moksafocou' ),
						implode( ', ', self::DISCOUNT_TYPES )
					)
				);
			}
			$fields['discount_type'] = $type;
		}

		if ( isset( $input['amount'] ) || ! $is_update ) {
			$amount = isset( $input['amount'] ) ? $input['amount'] : 0;
			if ( ! is_numeric( $amount ) ) {
				return new \WP_Error( 'moksafocou_invalid_amount', __( 'Discount amount must be a number.', 'moksafocou' ) );
			}
			$amount = (float) $amount;
			if ( $amount < 0 ) {
				return new \WP_Error( 'moksafocou_invalid_amount', __( 'Discount amount cannot be negative.', 'moksafocou' ) );
			}
			$type_for_amount = $fields['discount_type'] ?? ( isset( $input['discount_type'] ) ? (string) $input['discount_type'] : '' );
			if ( 'percent' === $type_for_amount && $amount > 100 ) {
				return new \WP_Error( 'moksafocou_invalid_amount', __( 'Percentage discount cannot exceed 100.', 'moksafocou' ) );
			}
			$fields['amount'] = $amount;
		}

		if ( isset( $input['description'] ) ) {
			$fields['description'] = sanitize_textarea_field( (string) $input['description'] );
		}

		if ( array_key_exists( 'date_expires', $input ) ) {
			$expires = self::normalize_expiry( $input['date_expires'] );
			if ( $expires instanceof \WP_Error ) {
				return $expires;
			}
			$fields['date_expires'] = $expires; // null or 'Y-m-d'.
		}

		foreach ( self::BOOL_FIELDS as $bool_field ) {
			if ( array_key_exists( $bool_field, $input ) ) {
				$fields[ $bool_field ] = self::to_bool( $input[ $bool_field ] );
			}
		}

		foreach ( self::INT_FIELDS as $int_field ) {
			if ( array_key_exists( $int_field, $input ) ) {
				$fields[ $int_field ] = max( 0, (int) $input[ $int_field ] );
			}
		}

		foreach ( [ 'minimum_amount', 'maximum_amount' ] as $money_field ) {
			if ( array_key_exists( $money_field, $input ) && '' !== $input[ $money_field ] && null !== $input[ $money_field ] ) {
				if ( ! is_numeric( $input[ $money_field ] ) ) {
					return new \WP_Error( 'moksafocou_invalid_amount', __( 'Minimum / maximum spend must be a number.', 'moksafocou' ) );
				}
				$fields[ $money_field ] = (string) (float) $input[ $money_field ];
			}
		}

		foreach ( self::ID_LIST_FIELDS as $list_field ) {
			if ( array_key_exists( $list_field, $input ) ) {
				$fields[ $list_field ] = self::normalize_id_list( $input[ $list_field ] );
			}
		}

		if ( array_key_exists( 'email_restrictions', $input ) ) {
			$fields['email_restrictions'] = self::normalize_emails( $input['email_restrictions'] );
		}

		return $fields;
	}

	/**
	 * @param mixed $value
	 * @return string|null|\WP_Error 'Y-m-d', null (clear), or error.
	 */
	private static function normalize_expiry( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$raw = sanitize_text_field( (string) $value );
		$ts  = strtotime( $raw );
		if ( false === $ts ) {
			return new \WP_Error( 'moksafocou_invalid_date', __( 'Invalid expiry date format; use YYYY-MM-DD.', 'moksafocou' ) );
		}
		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * @param mixed $value
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'on' ], true );
		}
		return (bool) $value;
	}

	/**
	 * @param mixed $value
	 * @return array<int,int>
	 */
	private static function normalize_id_list( $value ): array {
		$value = is_array( $value ) ? $value : ( '' === $value || null === $value ? [] : [ $value ] );
		$ids   = array_map( static fn( $item ): int => max( 0, (int) $item ), $value );
		$ids   = array_values( array_unique( array_filter( $ids ) ) );
		return $ids;
	}

	/**
	 * @param mixed $value
	 * @return array<int,string>
	 */
	private static function normalize_emails( $value ): array {
		$value  = is_array( $value ) ? $value : ( '' === $value || null === $value ? [] : [ $value ] );
		$emails = [];
		foreach ( $value as $item ) {
			$item = strtolower( trim( (string) $item ) );
			// Allow wildcard restrictions such as *@example.com.
			if ( '' !== $item && ( false !== strpos( $item, '*' ) || false !== filter_var( $item, FILTER_VALIDATE_EMAIL ) ) ) {
				$emails[] = $item;
			}
		}
		return array_values( array_unique( $emails ) );
	}

	/**
	 * Human-readable one-line summary of normalized fields (for confirm UI).
	 *
	 * @param array<string,mixed> $fields
	 */
	public static function build_summary( array $fields, string $verb = '' ): string {
		$code = $fields['code'] ?? '';
		$type = $fields['discount_type'] ?? 'fixed_cart';
		$amt  = isset( $fields['amount'] ) ? (float) $fields['amount'] : 0.0;

		$type_label = \Moksafocou\Support\CouponType::label( (string) $type );

		// Format with a decimal first so rtrim only strips post-decimal zeros
		// (otherwise whole numbers like 10 lose their trailing zero → "1").
		$amt_str     = rtrim( rtrim( number_format( $amt, 2, '.', '' ), '0' ), '.' );
		$amount_text = 'percent' === $type ? $amt_str . '%' : $amt_str;

		$parts = [
			sprintf(
				/* translators: 1: verb, 2: coupon code, 3: type label, 4: amount. */
				__( '%1$sCoupon %2$s: %3$s %4$s', 'moksafocou' ),
				$verb,
				$code,
				$type_label,
				$amount_text
			),
		];

		if ( ! empty( $fields['free_shipping'] ) ) {
			$parts[] = __( 'Includes free shipping', 'moksafocou' );
		}
		if ( ! empty( $fields['date_expires'] ) ) {
			/* translators: %s: expiry date. */
			$parts[] = sprintf( __( 'Expires %s', 'moksafocou' ), $fields['date_expires'] );
		}
		if ( ! empty( $fields['usage_limit'] ) ) {
			/* translators: %d: usage limit. */
			$parts[] = sprintf( __( 'Limited to %d use(s)', 'moksafocou' ), (int) $fields['usage_limit'] );
		}
		if ( ! empty( $fields['minimum_amount'] ) ) {
			/* translators: %s: minimum spend. */
			$parts[] = sprintf( __( 'Minimum spend %s', 'moksafocou' ), $fields['minimum_amount'] );
		}

		return implode( '、', $parts );
	}

	/**
	 * Deterministically convert Taiwan "N 折" discount phrases in a message into an
	 * explicit percent-amount hint, so the AI does not have to do the (error-prone)
	 * arithmetic. "9 折" = pay 90% = 10% off → amount 10; "85 折" = 15% off → amount 15.
	 *
	 * @return string Hint to append to the message, or '' when no 折 phrase is found.
	 */
	public static function zhe_to_percent_hint( string $message ): string {
		if ( ! preg_match_all( '/(\d+(?:\.\d+)?)\s*折/u', $message, $matches ) ) {
			return '';
		}
		$parts = [];
		foreach ( array_unique( $matches[1] ) as $raw ) {
			$value = (float) $raw;
			if ( $value <= 0 ) {
				continue;
			}
			$payment = $value < 10 ? $value * 10 : $value; // 個位數=成數(9 折→90%),兩位數=百分比(85 折→85%).
			$amount  = 100 - $payment;
			if ( $amount <= 0 || $amount >= 100 ) {
				continue;
			}
			$amount_str = rtrim( rtrim( sprintf( '%.2f', $amount ), '0' ), '.' );
			/* translators: 1: the 折 number as written, 2: the percent discount amount. */
			$parts[] = sprintf( __( 'The percent amount for "%1$s off" is %2$s', 'moksafocou' ), $raw, $amount_str );
		}
		if ( [] === $parts ) {
			return '';
		}
		return ' (' . __( 'Discount conversion', 'moksafocou' ) . ':' . implode( '、', $parts ) . ')';
	}

	/**
	 * The percent discount amount implied by the first "N 折" phrase, or null.
	 * "9 折" → 10.0; "85 折" → 15.0. Used to deterministically override the AI.
	 */
	public static function zhe_first_amount( string $message ): ?float {
		if ( ! preg_match( '/(\d+(?:\.\d+)?)\s*折/u', $message, $matches ) ) {
			return null;
		}
		$value   = (float) $matches[1];
		$payment = $value < 10 ? $value * 10 : $value;
		$amount  = 100 - $payment;
		return ( $amount > 0 && $amount < 100 ) ? $amount : null;
	}

	// === Read ===

	public static function find_id_by_code( string $code ): int {
		$code = trim( $code );
		if ( '' === $code || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return 0;
		}
		return (int) wc_get_coupon_id_by_code( $code );
	}

	/**
	 * @param mixed $code_or_id
	 */
	public static function resolve_id( $code_or_id ): int {
		$ref = is_scalar( $code_or_id ) ? trim( (string) $code_or_id ) : '';
		if ( '' === $ref ) {
			return 0;
		}
		if ( ctype_digit( $ref ) && 'shop_coupon' === get_post_type( (int) $ref ) ) {
			return (int) $ref;
		}
		return self::find_id_by_code( $ref );
	}

	/**
	 * @param mixed $code_or_id
	 * @return array<string,mixed>|null
	 */
	public static function get( $code_or_id ): ?array {
		$id = self::resolve_id( $code_or_id );
		if ( ! $id ) {
			return null;
		}
		$coupon = new \WC_Coupon( $id );
		if ( ! $coupon->get_id() ) {
			return null;
		}
		return self::to_array( $coupon );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function to_array( \WC_Coupon $coupon ): array {
		$expires = $coupon->get_date_expires();
		return [
			'id'                   => $coupon->get_id(),
			'code'                 => $coupon->get_code(),
			'status'               => get_post_status( $coupon->get_id() ) ?: 'publish',
			'discount_type'        => $coupon->get_discount_type(),
			'amount'               => $coupon->get_amount(),
			'description'          => $coupon->get_description(),
			'free_shipping'        => $coupon->get_free_shipping(),
			'individual_use'       => $coupon->get_individual_use(),
			'date_expires'         => $expires ? $expires->date( 'Y-m-d' ) : '',
			'usage_count'          => $coupon->get_usage_count(),
			'usage_limit'          => $coupon->get_usage_limit(),
			'usage_limit_per_user' => $coupon->get_usage_limit_per_user(),
			'minimum_amount'       => $coupon->get_minimum_amount(),
			'maximum_amount'       => $coupon->get_maximum_amount(),
			'exclude_sale_items'   => $coupon->get_exclude_sale_items(),
			'product_ids'          => $coupon->get_product_ids(),
			'product_categories'   => $coupon->get_product_categories(),
		];
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array{count:int,coupons:array<int,array<string,mixed>>}
	 */
	public static function list( array $args = [] ): array {
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 20;
		$limit  = max( 1, min( 50, $limit ) );
		$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : 'any';
		$search = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';

		$query_args = [
			'post_type'      => 'shop_coupon',
			'post_status'    => in_array( $status, [ 'publish', 'draft' ], true ) ? $status : 'any',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];
		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}
		// Allow filtering by ANY discount type (incl. custom types like moksafocou_bogo),
		// not just the three native ones — otherwise "list BOGO coupons" silently returns
		// the unfiltered set.
		$discount_type = isset( $args['discount_type'] ) ? sanitize_text_field( (string) $args['discount_type'] ) : '';
		if ( '' !== $discount_type ) {
			$query_args['meta_key']   = 'discount_type'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query_args['meta_value'] = $discount_type; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		$posts   = get_posts( $query_args );
		$coupons = [];
		foreach ( $posts as $post ) {
			$coupon = new \WC_Coupon( $post->ID );
			if ( $coupon->get_id() ) {
				$coupons[] = self::to_array( $coupon );
			}
		}
		return [
			'count'   => count( $coupons ),
			'coupons' => $coupons,
		];
	}

	// === Write ===

	/**
	 * Persist normalized fields onto a coupon (new or existing).
	 *
	 * @param array<string,mixed> $fields Output of normalize_and_validate().
	 * @return \WC_Coupon|\WP_Error
	 */
	public static function save( array $fields, int $existing_id = 0 ) {
		$coupon = new \WC_Coupon( $existing_id ?: 0 );

		// Optional post status (e.g. 'draft') — WC writes it as the coupon's post_status
		// on both create and update, so a coupon can be created straight as a draft.
		if ( isset( $fields['status'] ) && is_string( $fields['status'] ) ) {
			$coupon->set_status( $fields['status'] );
		}
		if ( isset( $fields['code'] ) ) {
			$coupon->set_code( $fields['code'] );
		}
		if ( isset( $fields['discount_type'] ) ) {
			$coupon->set_discount_type( $fields['discount_type'] );
		}
		if ( isset( $fields['amount'] ) ) {
			$coupon->set_amount( (string) $fields['amount'] );
		}
		if ( isset( $fields['description'] ) ) {
			$coupon->set_description( $fields['description'] );
		}
		if ( array_key_exists( 'date_expires', $fields ) ) {
			$coupon->set_date_expires( $fields['date_expires'] ? strtotime( $fields['date_expires'] ) : null );
		}
		foreach ( self::BOOL_FIELDS as $bool_field ) {
			if ( isset( $fields[ $bool_field ] ) ) {
				$coupon->{"set_$bool_field"}( (bool) $fields[ $bool_field ] );
			}
		}
		foreach ( self::INT_FIELDS as $int_field ) {
			if ( isset( $fields[ $int_field ] ) ) {
				$coupon->{"set_$int_field"}( (int) $fields[ $int_field ] );
			}
		}
		foreach ( [ 'minimum_amount', 'maximum_amount' ] as $money_field ) {
			if ( isset( $fields[ $money_field ] ) ) {
				$coupon->{"set_$money_field"}( (string) $fields[ $money_field ] );
			}
		}
		foreach ( self::ID_LIST_FIELDS as $list_field ) {
			if ( isset( $fields[ $list_field ] ) ) {
				$coupon->{"set_$list_field"}( $fields[ $list_field ] );
			}
		}
		if ( isset( $fields['email_restrictions'] ) ) {
			$coupon->set_email_restrictions( $fields['email_restrictions'] );
		}

		$id = $coupon->save();
		if ( ! $id ) {
			return new \WP_Error( 'moksafocou_save_failed', __( 'Failed to save the coupon.', 'moksafocou' ) );
		}
		/**
		 * Fires after a coupon is created or updated through the plugin's service (admin form,
		 * REST, AI/MCP). Lets integrations sync coupons to external systems.
		 *
		 * @param int                 $id     Saved coupon id.
		 * @param array<string,mixed> $fields The fields written.
		 * @param bool                $is_new Whether this created a new coupon.
		 */
		do_action( 'moksafocou_coupon_saved', (int) $id, $fields, 0 === $existing_id );
		return new \WC_Coupon( $id );
	}

	public static function set_status( int $id, bool $enable ): bool {
		if ( ! $id ) {
			return false;
		}
		$result = wp_update_post(
			[
				'ID'          => $id,
				'post_status' => $enable ? 'publish' : 'draft',
			],
			true
		);
		return ! is_wp_error( $result ) && 0 !== $result;
	}

	public static function delete( int $id, bool $force = false ): bool {
		if ( ! $id ) {
			return false;
		}
		$coupon = new \WC_Coupon( $id );
		if ( ! $coupon->get_id() ) {
			return false;
		}
		return (bool) $coupon->delete( $force );
	}

	/**
	 * Native WC_Coupon props copied object-to-object on duplicate. Deliberately the COMPLETE
	 * restriction set — going through to_array() would drop excluded-product, email-restriction
	 * and per-user-limit fields and silently widen the duplicate's scope.
	 *
	 * @var array<int,string>
	 */
	private const DUP_PROPS = [
		'discount_type',
		'amount',
		'description',
		'free_shipping',
		'individual_use',
		'exclude_sale_items',
		'date_expires',
		'minimum_amount',
		'maximum_amount',
		'usage_limit',
		'usage_limit_per_user',
		'limit_usage_to_x_items',
		'product_ids',
		'excluded_product_ids',
		'product_categories',
		'excluded_product_categories',
		'email_restrictions',
	];

	/**
	 * A short unique coupon code with the given prefix, or '' if none could be found in 5 tries.
	 */
	public static function unique_code( string $prefix ): string {
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$suffix = strtoupper( wp_generate_password( 8, false, false ) );
			$code   = $prefix . $suffix;
			if ( 0 === self::find_id_by_code( $code ) ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * Clone a coupon (all native props + our custom condition / tier / url meta, minus the
	 * per-coupon-unique slug) under a new code, usage count reset to zero. The single source of
	 * truth for duplication — used by the duplicate-coupon ability and the remarketing trigger.
	 *
	 * @return int|\WP_Error New coupon id, or an error.
	 */
	public static function duplicate( int $source_id, string $new_code, bool $publish ) {
		$src = new \WC_Coupon( $source_id );
		if ( ! $src->get_id() ) {
			return new \WP_Error( 'moksafocou_not_found', __( 'Coupon not found.', 'moksafocou' ) );
		}
		if ( '' === $new_code ) {
			return new \WP_Error( 'moksafocou_duplicate_failed', __( 'Could not generate a unique new code.', 'moksafocou' ) );
		}

		$new = new \WC_Coupon( 0 );
		foreach ( self::DUP_PROPS as $prop ) {
			$new->{"set_$prop"}( $src->{"get_$prop"}() );
		}
		$new->set_code( $new_code );
		$new->set_usage_count( 0 );
		$new_id = $new->save();
		if ( ! $new_id ) {
			return new \WP_Error( 'moksafocou_duplicate_failed', __( 'Copy failed.', 'moksafocou' ) );
		}

		self::set_status( (int) $new_id, $publish );

		foreach ( \Moksafocou\Coupon\Meta\Keys::copyable() as $key ) {
			$value = get_post_meta( $source_id, $key, true );
			if ( '' !== $value && [] !== $value && null !== $value ) {
				update_post_meta( (int) $new_id, $key, $value );
			}
		}

		return (int) $new_id;
	}
}
