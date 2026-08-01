<?php

declare( strict_types=1 );

namespace Moksafocou\Coupon\Meta;

use Moksafocou\Support\Tiers;
use Moksafocou\Support\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * A clean, grouped view of the plugin's coupon settings — the nested shape external
 * callers actually want, instead of digging the flat `_moksafocou_*` keys out of the
 * WooCommerce REST `meta_data` array.
 *
 * It is the single source of truth for grouping + (de)serialization, consumed by:
 *  - the `moksafocou` REST field on /wc/v3/coupons (read + write, here), and
 *  - the Abilities API create/update schema (so the AI/MCP can configure a coupon in
 *    one call).
 *
 * Field kinds (and therefore sanitisation on write) are inherited from RestMeta, so
 * the two never drift. Writes go through update_post_meta, so the RestMeta
 * sanitize_callbacks run automatically here too.
 */
final class CouponSettings {

	/** REST field / ability property name. */
	public const FIELD = 'moksafocou';

	private const CAP = 'manage_woocommerce';

	/**
	 * group => ( public field name => meta key ). The union of all keys equals
	 * Keys::all() (enforced by CouponSettingsTest).
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function groups(): array {
		return array(
			'schedule'        => array(
				'enabled'       => Keys::SCHEDULE_ENABLED,
				'start'         => Keys::SCHEDULE_START,
				'end'           => Keys::SCHEDULE_END,
				'start_message' => Keys::SCHEDULE_MSG_START,
				'end_message'   => Keys::SCHEDULE_MSG_END,
			),
			'roles'           => array(
				'enabled' => Keys::ROLE_ENABLED,
				'type'    => Keys::ROLE_TYPE,
				'list'    => Keys::ROLE_LIST,
				'message' => Keys::ROLE_MSG,
			),
			'cart'            => array(
				'minimum_subtotal'      => Keys::MIN_SUBTOTAL,
				'subtotal_includes_tax' => Keys::MIN_SUBTOTAL_INCL_TAX,
				'minimum_quantity'      => Keys::MIN_QTY,
				'message'               => Keys::CART_MSG,
			),
			'customer'        => array(
				'enabled'             => Keys::CUST_ENABLED,
				'first_purchase_only' => Keys::CUST_FIRST_ONLY,
				'minimum_orders'      => Keys::CUST_MIN_ORDERS,
				'maximum_orders'      => Keys::CUST_MAX_ORDERS,
				'minimum_spent'       => Keys::CUST_MIN_SPENT,
				'maximum_spent'       => Keys::CUST_MAX_SPENT,
				'message'             => Keys::CUST_MSG,
			),
			'products'        => array(
				'required'                  => Keys::REQ_PRODUCTS,
				'required_match'            => Keys::REQ_PRODUCTS_MODE,
				'required_categories'       => Keys::REQ_CATEGORIES,
				'required_categories_match' => Keys::REQ_CATEGORIES_MODE,
				'message'                   => Keys::PRODUCT_MSG,
				'excluded'                  => Keys::EXCL_PRODUCTS,
				'excluded_categories'       => Keys::EXCL_CATEGORIES,
				'excluded_message'          => Keys::EXCL_MSG,
			),
			'daytime'         => array(
				'enabled' => Keys::DAYTIME_ENABLED,
				'days'    => Keys::DAYTIME_DAYS,
				'start'   => Keys::DAYTIME_START,
				'end'     => Keys::DAYTIME_END,
				'message' => Keys::DAYTIME_MSG,
			),
			'shipping_region' => array(
				'enabled'   => Keys::SHIPREGION_ENABLED,
				'mode'      => Keys::SHIPREGION_MODE,
				'countries' => Keys::SHIPREGION_COUNTRIES,
				'message'   => Keys::SHIPREGION_MSG,
			),
			'payment'         => array(
				'enabled' => Keys::PAYMENT_ENABLED,
				'mode'    => Keys::PAYMENT_MODE,
				'methods' => Keys::PAYMENT_METHODS,
				'message' => Keys::PAYMENT_MSG,
			),
			'advanced_rules'  => array(
				'enabled' => Keys::RULES_ENABLED,
				'rules'   => Keys::RULES,
				'message' => Keys::RULES_MSG,
			),
			'url'             => array(
				'enabled'            => Keys::URL_ENABLED,
				'slug'               => Keys::URL_SLUG,
				'redirect'           => Keys::URL_REDIRECT,
				'success_message'    => Keys::URL_SUCCESS_MSG,
				'redirect_to_origin' => Keys::URL_REDIRECT_ORIGIN,
			),
			'bogo'            => array(
				'trigger_product_ids'  => Keys::BOGO_TRIGGER_PRODUCT_IDS,
				'trigger_category_ids' => Keys::BOGO_TRIGGER_CATEGORY_IDS,
				'trigger_quantity'     => Keys::BOGO_TRIGGER_QTY,
				'reward_product_ids'   => Keys::BOGO_REWARD_PRODUCT_IDS,
				'reward_category_ids'  => Keys::BOGO_REWARD_CATEGORY_IDS,
				'reward_quantity'      => Keys::BOGO_REWARD_QTY,
				'reward_mode'          => Keys::BOGO_REWARD_MODE,
				'reward_value'         => Keys::BOGO_REWARD_VALUE,
				'deal_mode'            => Keys::BOGO_DEAL_MODE,
				'repeat_limit'         => Keys::BOGO_REPEAT_LIMIT,
				'notice_message'       => Keys::BOGO_NOTICE_MSG,
			),
			'nth_item'        => array(
				'product_ids'    => Keys::NTH_PRODUCT_IDS,
				'category_ids'   => Keys::NTH_CATEGORY_IDS,
				'group_by'       => Keys::NTH_GROUP_BY,
				'n'              => Keys::NTH_N,
				'reward_mode'    => Keys::NTH_REWARD_MODE,
				'reward_value'   => Keys::NTH_REWARD_VALUE,
				'deal_mode'      => Keys::NTH_DEAL_MODE,
				'repeat_limit'   => Keys::NTH_REPEAT_LIMIT,
				'notice_message' => Keys::NTH_NOTICE_MSG,
			),
			'mixmatch'        => array(
				'product_ids'    => Keys::MIXMATCH_PRODUCT_IDS,
				'category_ids'   => Keys::MIXMATCH_CATEGORY_IDS,
				'quantity'       => Keys::MIXMATCH_QTY,
				'price_mode'     => Keys::MIXMATCH_PRICE_MODE,
				'price_value'    => Keys::MIXMATCH_PRICE_VALUE,
				'deal_mode'      => Keys::MIXMATCH_DEAL_MODE,
				'repeat_limit'   => Keys::MIXMATCH_REPEAT_LIMIT,
				'notice_message' => Keys::MIXMATCH_NOTICE_MSG,
			),
			'gift'            => array(
				'enabled'    => Keys::GIFT_ENABLED,
				'product_id' => Keys::GIFT_PRODUCT_ID,
				'quantity'   => Keys::GIFT_QTY,
				'mode'       => Keys::GIFT_MODE,
				'value'      => Keys::GIFT_VALUE,
			),
			'stacking'        => array(
				'exclude_others' => Keys::STACK_EXCLUDE,
				'allowed'        => Keys::STACK_ALLOWED,
				'disallowed'     => Keys::STACK_DISALLOWED,
				'message'        => Keys::STACK_MSG,
			),
			'shipping'        => array(
				'mode'  => Keys::SHIP_MODE,
				'value' => Keys::SHIP_VALUE,
			),
			'frontend'        => array(
				'show_in_list' => Keys::SHOW_IN_LIST,
				'label'        => Keys::FRONT_LABEL,
			),
			'urgency'         => array(
				'countdown_enabled' => Keys::COUNTDOWN_ENABLED,
				'countdown_source'  => Keys::COUNTDOWN_SOURCE,
				'stock_show'        => Keys::STOCK_SHOW,
				'stock_threshold'   => Keys::STOCK_THRESHOLD,
			),
			'campaign'        => array(
				'tag' => Keys::CAMPAIGN,
			),
			'cashback'        => array(
				'mode'  => Keys::CASHBACK_MODE,
				'value' => Keys::CASHBACK_VALUE,
			),
			'discount_cap'    => array(
				'amount' => Keys::DISCOUNT_CAP,
			),
			'tiers'           => array(
				'enabled'           => Keys::TIERS_ENABLED,
				'rows'              => Keys::TIERS,
				'basis'             => Keys::TIERS_BASIS,
				'target_mode'       => Keys::TIERS_TARGET_MODE,
				'target_products'   => Keys::TIERS_TARGET_PRODUCTS,
				'target_categories' => Keys::TIERS_TARGET_CATEGORIES,
			),
			'auto_apply'      => array(
				'enabled' => Keys::AUTO_APPLY,
			),
		);
	}

	/* ---------------- read ---------------- */

	/**
	 * Build the grouped, typed settings object for a coupon.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function read( int $coupon_id ): array {
		$kinds = RestMeta::definitions();
		$out   = array();
		foreach ( self::groups() as $group => $fields ) {
			$row = array();
			foreach ( $fields as $name => $key ) {
				$row[ $name ] = self::cast_read( $kinds[ $key ] ?? 'text', get_post_meta( $coupon_id, $key, true ) );
			}
			$out[ $group ] = $row;
		}
		return $out;
	}

	/**
	 * @param string $kind Sanitizer kind (from RestMeta).
	 * @param mixed  $raw  Raw stored meta value.
	 * @return mixed
	 */
	private static function cast_read( string $kind, $raw ) {
		switch ( $kind ) {
			case 'bool':
				return 'yes' === $raw;
			case 'int':
				return (int) $raw;
			case 'decimal':
				return '' === $raw || null === $raw ? null : (float) $raw;
			case 'int_list':
			case 'day_list':
				return is_array( $raw ) ? array_values( array_map( 'intval', $raw ) ) : array();
			case 'key_list':
			case 'code_list':
				return is_array( $raw ) ? array_values( array_map( 'strval', $raw ) ) : array();
			case 'tiers':
				return Tiers::parse( (string) $raw );
			case 'rules':
				return Rules::parse( (string) $raw );
			default:
				return (string) $raw;
		}
	}

	/* ---------------- write ---------------- */

	/**
	 * Apply a partial grouped settings object to a coupon. Only the groups / fields
	 * present in $input are touched. Sanitisation runs automatically via the registered
	 * meta sanitize_callbacks (RestMeta). Unknown groups / fields are ignored.
	 *
	 * @param int                 $coupon_id Coupon post ID.
	 * @param array<string,mixed> $input     Partial grouped settings.
	 * @return int Number of fields written / cleared.
	 */
	public static function write( int $coupon_id, array $input ): int {
		$kinds   = RestMeta::definitions();
		$groups  = self::groups();
		$applied = 0;
		foreach ( $input as $group => $fields ) {
			if ( ! isset( $groups[ $group ] ) || ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $name => $value ) {
				if ( ! isset( $groups[ $group ][ $name ] ) ) {
					continue;
				}
				$key = $groups[ $group ][ $name ];
				self::apply_write( $coupon_id, $key, $kinds[ $key ] ?? 'text', $value );
				++$applied;
			}
		}
		return $applied;
	}

	/**
	 * @param int    $coupon_id Coupon post ID.
	 * @param string $key       Meta key to write.
	 * @param string $kind      Sanitizer kind (from RestMeta).
	 * @param mixed  $value     Incoming value.
	 */
	private static function apply_write( int $coupon_id, string $key, string $kind, $value ): void {
		switch ( $kind ) {
			case 'bool':
				if ( $value && 'no' !== $value && '' !== $value ) {
					update_post_meta( $coupon_id, $key, 'yes' );
				} else {
					delete_post_meta( $coupon_id, $key );
				}
				return;
			case 'int':
				update_post_meta( $coupon_id, $key, max( 0, (int) $value ) );
				return;
			case 'int_list':
			case 'day_list':
			case 'key_list':
			case 'code_list':
				$list = is_array( $value ) ? $value : array();
				if ( array() === $list ) {
					delete_post_meta( $coupon_id, $key );
				} else {
					update_post_meta( $coupon_id, $key, $list );
				}
				return;
			case 'tiers':
				$json = Tiers::canonical_json( $value );
				if ( '' === $json ) {
					delete_post_meta( $coupon_id, $key );
				} else {
					update_post_meta( $coupon_id, $key, $json );
				}
				return;
			case 'rules':
				$json = Rules::canonical_json( $value );
				if ( '' === $json ) {
					delete_post_meta( $coupon_id, $key );
				} else {
					update_post_meta( $coupon_id, $key, $json );
				}
				return;
			default:
				$str = trim( (string) $value );
				if ( '' === $str ) {
					delete_post_meta( $coupon_id, $key );
				} else {
					update_post_meta( $coupon_id, $key, $str );
				}
		}
	}

	/* ---------------- JSON schema ---------------- */

	/**
	 * JSON schema for the grouped object (shared by the REST field + the abilities).
	 *
	 * @return array<string,mixed>
	 */
	public static function schema(): array {
		$kinds      = RestMeta::definitions();
		$properties = array();
		foreach ( self::groups() as $group => $fields ) {
			$group_props = array();
			foreach ( $fields as $name => $key ) {
				$group_props[ $name ] = self::field_schema( $kinds[ $key ] ?? 'text' );
			}
			$properties[ $group ] = array(
				'type'                 => 'object',
				'properties'           => $group_props,
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'description'          => __( 'Moksa coupon advanced settings (groups: schedule / conditions / BOGO / gift / shipping…)', 'moksafocou' ),
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private static function field_schema( string $kind ): array {
		switch ( $kind ) {
			case 'bool':
				return array( 'type' => 'boolean' );
			case 'int':
				return array( 'type' => 'integer' );
			case 'decimal':
				return array( 'type' => array( 'number', 'null' ) );
			case 'int_list':
			case 'day_list':
				return array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				);
			case 'key_list':
			case 'code_list':
				return array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				);
			case 'tiers':
				return array(
					'type'        => 'array',
					'description' => __( 'Tiered discount rows. Tiers are measured by moksafocou.tiers.basis (subtotal / quantity / weight, default subtotal). Each row: { threshold, kind percent|fixed, value (percent is 0-100, fixed is a fixed amount) }. Percent and fixed can be mixed; among matching tiers the one with the largest discount is used.', 'moksafocou' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'threshold' => array( 'type' => 'number' ),
							'kind'      => array(
								'type' => 'string',
								'enum' => array( 'percent', 'fixed' ),
							),
							'value'     => array( 'type' => 'number' ),
						),
						'additionalProperties' => false,
					),
				);
			case 'rules':
				return array(
					'type'                 => 'object',
					'description'          => __( 'Advanced rules tree (groups AND/OR). The top level match=all/any combines "groups", and each group\'s match=all/any combines "rules". Each rule = { type, op, value }. Types and value shapes: numeric (op gte/lte/gt/lt/eq/neq, value is a numeric string) subtotal / quantity / cart_weight (kg) / order_count / total_spent / hours_since_registered / hours_since_last_order; pair (op numeric, value={a: product or category ID, b: threshold}) product_quantity / category_spent; ID list (op in/not_in, value=integer array) product_in_cart / category_in_cart / ordered_product / ordered_category / shipping_zone; code (op in/not_in, value=string array) shipping_country (uppercase country code) / payment_method / coupon_applied / stock_status (instock, outofstock, onbackorder) / weekday (0-6) / user_role; custom taxonomy (op in/not_in, value={tax: taxonomy, terms:[term ID]}) custom_taxonomy; custom meta (value={key, value}) custom_user_meta (op eq/neq) / custom_cart_item_meta (op in/not_in); time (op gte/lte) time_of_day (value HH:MM) / date (value Y-m-d H:i). Payment method is validated only at checkout.', 'moksafocou' ),
					'properties'           => array(
						'match'  => array(
							'type' => 'string',
							'enum' => array( 'all', 'any' ),
						),
						'groups' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'match' => array(
										'type' => 'string',
										'enum' => array( 'all', 'any' ),
									),
									'rules' => array(
										'type'  => 'array',
										'items' => array(
											'type'       => 'object',
											'properties' => array(
												'type'  => array(
													'type' => 'string',
													'enum' => Rules::type_keys(),
												),
												'op'    => array(
													'type' => 'string',
													'enum' => array( 'gte', 'lte', 'gt', 'lt', 'eq', 'neq', 'in', 'not_in' ),
												),
												'value' => array( 'type' => array( 'string', 'array', 'number', 'object' ) ),
											),
											'additionalProperties' => false,
										),
									),
								),
								'additionalProperties' => false,
							),
						),
					),
					'additionalProperties' => false,
				);
			default:
				return array( 'type' => 'string' );
		}
	}

	/* ---------------- REST field registration ---------------- */

	/** Always-on: hook the grouped field onto the WooCommerce coupon REST resource. */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_rest_field' ) );
	}

	public static function register_rest_field(): void {
		if ( ! function_exists( 'register_rest_field' ) ) {
			return;
		}
		register_rest_field(
			'shop_coupon',
			self::FIELD,
			array(
				'get_callback'    => array( self::class, 'rest_get' ),
				'update_callback' => array( self::class, 'rest_update' ),
				'schema'          => self::schema(),
			)
		);
	}

	/**
	 * @param array<string,mixed> $response Prepared coupon response (has 'id').
	 * @return array<string,array<string,mixed>>
	 */
	public static function rest_get( $response ): array {
		$id = is_array( $response ) && isset( $response['id'] ) ? (int) $response['id'] : 0;
		return $id > 0 ? self::read( $id ) : array();
	}

	/**
	 * @param mixed $value  Submitted grouped settings.
	 * @param mixed $coupon WC_Coupon being saved.
	 * @return bool|\WP_Error
	 */
	public static function rest_update( $value, $coupon ) {
		if ( ! is_array( $value ) ) {
			return true;
		}
		$id = is_object( $coupon ) && method_exists( $coupon, 'get_id' ) ? (int) $coupon->get_id() : 0;
		if ( $id <= 0 ) {
			return true;
		}
		if ( ! current_user_can( self::CAP, $id ) ) {
			return new \WP_Error(
				'moksafocou_rest_forbidden',
				__( 'You do not have permission to edit this coupon\'s advanced settings.', 'moksafocou' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		self::write( $id, $value );
		return true;
	}
}
