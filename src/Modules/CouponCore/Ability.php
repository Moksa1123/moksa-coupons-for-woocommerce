<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponCore;

use Moksafocou\Coupon\CouponService;
use Moksafocou\Coupon\Meta\CouponSettings;
use Moksafocou\Support\AbilityMeta;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress Abilities API registration for coupon capabilities. One definition is
 * consumed by the command palette, REST, the in-dashboard AI assistant and MCP.
 *
 * Read abilities run directly; destructive abilities are propose-only — their
 * execute_callback points at CouponOps::*_prepare and the real change happens via
 * the confirm flow. Requires WordPress 6.9+ core Abilities API.
 */
final class Ability {

	public const CATEGORY = 'moksa-coupons-for-woocommerce';

	public const CAP = 'manage_woocommerce';

	/** @var array<string,bool> Abilities hidden from MCP this request (filled by gate). */
	private static array $mcp_hidden = [];

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Moksa Coupons', 'moksa-coupons-for-woocommerce' ),
				'description' => __( 'WooCommerce coupon creation, lookup and management capabilities', 'moksa-coupons-for-woocommerce' ),
			]
		);
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		self::register_reads();
		self::register_writes();
	}

	// === Read abilities ===

	private static function register_reads(): void {
		// 「有幾張優惠券」要有一個只回數字的工具。給模型一包清單它就會拿回傳筆數
		// 當總數，或乾脆自己編一個 —— 實測問「目前有幾個優惠券」會答出不存在的數字。
		// 單一純量沒有可誤讀的空間。
		wp_register_ability(
			'moksafocou/count-coupons',
			[
				'label'               => __( 'Count coupons', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'How many coupons the store has, broken down by status. Use this for any "how many coupons" question instead of counting rows from list-coupons. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				// 不要寫成 'properties' => []：空陣列 json_encode 出來是 `[]` 不是 `{}`，
				// OpenAI 直接退整包；改成拿掉 properties 又會讓 WP 的輸入驗證判定無效。
				// 給一個真的可選參數最單純，兩邊都照一般工具處理。
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'status' => [
							'type'        => 'string',
							'enum'        => [ 'any', 'publish', 'draft' ],
							'description' => __( 'Which figure to emphasise; the response always contains every status. Pass "any" unless the question is about one status.', 'moksa-coupons-for-woocommerce' ),
						],
					],
					// 必填是刻意的：全部參數都可省時，模型有時整個不帶引數呼叫，WP 就會用
					// null 去驗 type:object 而判定無效（實測三次有兩次踩到）。有一個必填欄位
					// 就保證送進來的是物件。
					'required'             => [ 'status' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'total'   => [ 'type' => 'integer' ],
						'publish' => [ 'type' => 'integer' ],
						'draft'   => [ 'type' => 'integer' ],
						'expired' => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_count' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/list-coupons',
			[
				'label'               => __( 'List coupons', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'List coupons, filterable by status (publish = enabled / draft = disabled), discount type, and keyword. Returns total (how many match the filter in the whole store) and coupons (a page of at most 50 rows, default 20). Answer questions about how many there are with total, never with the number of rows returned. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'status'        => [
							'type'        => 'string',
							'description' => __( 'Status: publish (enabled) / draft (disabled) / any. Not given = all', 'moksa-coupons-for-woocommerce' ),
						],
						'discount_type' => [
							'type'        => 'string',
							'enum'        => CouponService::DISCOUNT_TYPES,
							'description' => __( 'Discount type filter (optional)', 'moksa-coupons-for-woocommerce' ),
						],
						'search'        => [
							'type'        => 'string',
							'description' => __( 'Code / description keyword (optional)', 'moksa-coupons-for-woocommerce' ),
						],
						'limit'         => [
							'type'        => 'integer',
							'description' => __( 'Maximum number of records (default 20, maximum 50)', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'count'   => [
							'type'        => 'integer',
							'description' => 'Rows returned in this response (capped by limit).',
						],
						'total'   => [
							'type'        => 'integer',
							'description' => 'Total coupons matching the filter. Use this to answer how many there are.',
						],
						'coupons' => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_list' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/get-coupon',
			[
				'label'               => __( 'Look up coupon details', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Get the full settings of a single coupon (code, discount type and amount, expiry date, usage count and limit, minimum spend, restricted products, etc.). Provide a code or ID. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'code_or_id' => [
							'type'        => 'string',
							'description' => __( 'Coupon code or ID', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [ 'code_or_id' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'coupon' => [ 'type' => 'object' ] ],
				],
				'execute_callback'    => [ self::class, 'execute_get' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/find-coupon-by-code',
			[
				'label'               => __( 'Check whether a code exists', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Check whether a given coupon code already exists, returning whether it exists and its ID. Use this before creating to avoid duplicates. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'code' => [
							'type'        => 'string',
							'description' => __( 'Coupon code to check', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [ 'code' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'     => [ 'type' => 'integer' ],
						'exists' => [ 'type' => 'boolean' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_find' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/coupon-usage-summary',
			[
				'label'               => __( 'Look up coupon usage', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Look up a coupon\'s usage count, total limit and per-user limit, and remaining uses. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'code_or_id' => [
							'type'        => 'string',
							'description' => __( 'Coupon code or ID', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [ 'code_or_id' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'usage_count' => [ 'type' => 'integer' ],
						'usage_limit' => [ 'type' => 'integer' ],
						'remaining'   => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_usage' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);
	}

	// === Destructive abilities (propose-only execute_callback) ===

	private static function register_writes(): void {
		wp_register_ability(
			'moksafocou/create-coupon',
			[
				'label'               => __( 'Create coupon', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Create a new WooCommerce coupon. This is a destructive operation — the call only "proposes" it, and it is only created after the user clicks "Confirm"; you do not need to ask for confirmation yourself.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::create_input_schema(),
				'output_schema'       => self::summary_output(),
				'execute_callback'    => [ CouponOps::class, 'create_prepare' ],
				'permission_callback' => [ self::class, 'can_write' ],
				'meta'                => self::write_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/update-coupon',
			[
				'label'               => __( 'Update coupon', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Update the fields of an existing coupon (discount, expiry date, usage limit, etc.; the code cannot be changed). Destructive — the call only "proposes" it, and it only takes effect after the user confirms.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::update_input_schema(),
				'output_schema'       => self::summary_output(),
				'execute_callback'    => [ CouponOps::class, 'update_prepare' ],
				'permission_callback' => [ self::class, 'can_write' ],
				'meta'                => self::write_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/toggle-coupon',
			[
				'label'               => __( 'Enable/disable coupon', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Enable (publish) or disable (draft) a coupon. Destructive — the call only "proposes" it, and it only takes effect after the user confirms.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'code_or_id' => [
							'type'        => 'string',
							'description' => __( 'Coupon code or ID', 'moksa-coupons-for-woocommerce' ),
						],
						'enable'     => [
							'type'        => 'boolean',
							'description' => __( 'true = enable, false = disable', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [ 'code_or_id', 'enable' ],
					'additionalProperties' => false,
				],
				'output_schema'       => self::summary_output(),
				'execute_callback'    => [ CouponOps::class, 'toggle_prepare' ],
				'permission_callback' => [ self::class, 'can_write' ],
				'meta'                => self::write_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/delete-coupon',
			[
				'label'               => __( 'Delete coupon', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Delete a coupon (by default moved to trash; force=true permanently deletes). Destructive and irreversible — the call only "proposes" it, and it is only performed after the user confirms.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'code_or_id' => [
							'type'        => 'string',
							'description' => __( 'Coupon code or ID', 'moksa-coupons-for-woocommerce' ),
						],
						'force'      => [
							'type'        => 'boolean',
							'description' => __( 'true = permanently delete, false = move to trash (default)', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [ 'code_or_id' ],
					'additionalProperties' => false,
				],
				'output_schema'       => self::summary_output(),
				'execute_callback'    => [ CouponOps::class, 'delete_prepare' ],
				'permission_callback' => [ self::class, 'can_write' ],
				'meta'                => self::write_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/bulk-generate-coupons',
			[
				'label'               => __( 'Bulk-generate coupons', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Bulk-generate multiple coupons with the same settings and unique codes at once (for marketing campaigns). Destructive — the call only "proposes" it, and they are only created after the user confirms.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::bulk_input_schema(),
				'output_schema'       => self::summary_output(),
				'execute_callback'    => [ CouponOps::class, 'bulk_generate_prepare' ],
				'permission_callback' => [ self::class, 'can_write' ],
				'meta'                => self::write_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/extend-expiry',
			[
				'label'               => __( 'Extend expiry date', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Extend the expiry date of one or more coupons to a specified date. Destructive — the call only "proposes" it, and it only takes effect after the user confirms.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'codes_or_ids' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'Array of coupon codes or IDs', 'moksa-coupons-for-woocommerce' ),
						],
						'date_expires' => [
							'type'        => 'string',
							'description' => __( 'New expiry date YYYY-MM-DD', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [ 'codes_or_ids', 'date_expires' ],
					'additionalProperties' => false,
				],
				'output_schema'       => self::summary_output(),
				'execute_callback'    => [ CouponOps::class, 'extend_expiry_prepare' ],
				'permission_callback' => [ self::class, 'can_write' ],
				'meta'                => self::write_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/duplicate-coupon',
			[
				'label'               => __( 'Copy coupon', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Copy an existing coupon into a new draft (keeping all discount settings and schedule / role / cart conditions, resetting the usage count to zero, and automatically appending -COPY- to the code). Destructive — the call only "proposes" it, and it is only created after the user confirms.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'code_or_id' => [
							'type'        => 'string',
							'description' => __( 'Coupon code or ID of the source coupon to copy', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [ 'code_or_id' ],
					'additionalProperties' => false,
				],
				'output_schema'       => self::summary_output(),
				'execute_callback'    => [ CouponOps::class, 'duplicate_prepare' ],
				'permission_callback' => [ self::class, 'can_write' ],
				'meta'                => self::write_meta(),
			]
		);
	}

	// === Read execute callbacks ===

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	/**
	 * @param mixed $input Unused (no parameters).
	 * @return array<string,int>
	 */
	public static function execute_count( $input ): array {
		unset( $input );
		if ( ! self::can_read() ) {
			return [
				'total'   => 0,
				'publish' => 0,
				'draft'   => 0,
				'expired' => 0,
			];
		}
		return CouponService::counts();
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_list( $input ): array {
		if ( ! self::can_read() ) {
			return [
				'count'   => 0,
				'total'   => 0,
				'coupons' => [],
			];
		}
		return CouponService::list( is_array( $input ) ? $input : [] );
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_get( $input ): array {
		if ( ! self::can_read() ) {
			return [];
		}
		$ref    = is_array( $input ) && isset( $input['code_or_id'] ) ? (string) $input['code_or_id'] : '';
		$coupon = CouponService::get( $ref );
		return null === $coupon ? [] : [ 'coupon' => $coupon ];
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_find( $input ): array {
		if ( ! self::can_read() ) {
			return [
				'id'     => 0,
				'exists' => false,
			];
		}
		$code = is_array( $input ) && isset( $input['code'] ) ? (string) $input['code'] : '';
		$id   = CouponService::find_id_by_code( $code );
		return [
			'id'     => $id,
			'exists' => $id > 0,
		];
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_usage( $input ): array {
		if ( ! self::can_read() ) {
			return [];
		}
		$ref  = is_array( $input ) && isset( $input['code_or_id'] ) ? (string) $input['code_or_id'] : '';
		$data = CouponService::get( $ref );
		if ( null === $data ) {
			return [];
		}
		$limit     = (int) $data['usage_limit'];
		$count     = (int) $data['usage_count'];
		$remaining = $limit > 0 ? max( 0, $limit - $count ) : -1;
		return [
			'code'                 => $data['code'],
			'usage_count'          => $count,
			'usage_limit'          => $limit,
			'usage_limit_per_user' => (int) $data['usage_limit_per_user'],
			'remaining'            => $remaining,
		];
	}

	// === Permission callbacks ===

	public static function can_read(): bool {
		return current_user_can( self::CAP );
	}

	public static function can_write(): bool {
		return current_user_can( CouponOps::CAP );
	}

	// === Schema / meta helpers ===

	/** @return array<string,mixed> */
	private static function read_meta(): array {
		return AbilityMeta::read();
	}

	/** @return array<string,mixed> */
	private static function write_meta(): array {
		return AbilityMeta::write();
	}

	/** @return array<string,mixed> */
	private static function summary_output(): array {
		return AbilityMeta::summary_output();
	}

	/** @return array<string,mixed> */
	private static function create_input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'code'                          => [
					'type'        => 'string',
					'description' => __( 'Coupon code, e.g. SUMMER25', 'moksa-coupons-for-woocommerce' ),
				],
				'discount_type'                 => [
					'type'        => 'string',
					'enum'        => CouponService::DISCOUNT_TYPES,
					'default'     => 'fixed_cart',
					'description' => __( 'Discount type: percent (percentage) / fixed_cart (fixed cart discount) / fixed_product (fixed product discount)', 'moksa-coupons-for-woocommerce' ),
				],
				'amount'                        => [
					'type'        => 'number',
					'description' => __( 'Discount amount; for percent it is a percentage number (25 = 25%)', 'moksa-coupons-for-woocommerce' ),
				],
				'description'                   => [
					'type'        => 'string',
					'description' => __( 'Coupon description (optional)', 'moksa-coupons-for-woocommerce' ),
				],
				'date_expires'                  => [
					'type'        => 'string',
					'description' => __( 'Expiry date YYYY-MM-DD (optional)', 'moksa-coupons-for-woocommerce' ),
				],
				'individual_use'                => [ 'type' => 'boolean' ],
				'free_shipping'                 => [ 'type' => 'boolean' ],
				'exclude_sale_items'            => [ 'type' => 'boolean' ],
				'minimum_amount'                => [ 'type' => 'number' ],
				'maximum_amount'                => [ 'type' => 'number' ],
				'usage_limit'                   => [ 'type' => 'integer' ],
				'usage_limit_per_user'          => [ 'type' => 'integer' ],
				'limit_usage_to_x_items'        => [ 'type' => 'integer' ],
				'product_ids'                   => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'excluded_product_ids'          => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'product_categories'            => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'excluded_product_categories'   => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'email_restrictions'            => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'auto_apply'                    => [
					'type'        => 'boolean',
					'description' => __( 'Whether to auto-apply (added automatically when the customer reaches the cart; requires the "Auto-apply" module enabled, and the coupon has no usage or email limit)', 'moksa-coupons-for-woocommerce' ),
				],
				'discount_cap'                  => [
					'type'        => 'number',
					'description' => __( 'Maximum discount amount for a percentage discount (e.g. 20% off, up to 500; requires the "Maximum discount" module enabled). 0 or empty = no limit', 'moksa-coupons-for-woocommerce' ),
				],
				'exclude_coupons'               => [
					'type'        => 'boolean',
					'description' => __( 'Whether it cannot be combined with other coupons (mutually exclusive coupon; requires the "Stacking control" module enabled)', 'moksa-coupons-for-woocommerce' ),
				],
				'moksa-coupons-for-woocommerce' => CouponSettings::schema(),
			],
			'required'             => [ 'code', 'discount_type', 'amount' ],
			'additionalProperties' => false,
		];
	}

	/** @return array<string,mixed> */
	private static function update_input_schema(): array {
		$schema                             = self::create_input_schema();
		$schema['properties']['code_or_id'] = [
			'type'        => 'string',
			'description' => __( 'Coupon code or ID to update', 'moksa-coupons-for-woocommerce' ),
		];
		unset( $schema['properties']['code'] );
		$schema['required'] = [ 'code_or_id' ];
		return $schema;
	}

	/** @return array<string,mixed> */
	private static function bulk_input_schema(): array {
		$schema = self::create_input_schema();
		unset( $schema['properties']['code'] );
		$schema['properties']['count']  = [
			'type'        => 'integer',
			'description' => __( 'Number of coupons to generate (1–500)', 'moksa-coupons-for-woocommerce' ),
		];
		$schema['properties']['prefix'] = [
			'type'        => 'string',
			'description' => __( 'Code prefix (optional, e.g. SALE-)', 'moksa-coupons-for-woocommerce' ),
		];
		$schema['required']             = [ 'count', 'discount_type', 'amount' ];
		return $schema;
	}

	// === MCP exposure gate ===

	private static function expose_destructive(): bool {
		return 'yes' === get_option( 'moksafocou_mcp_expose_destructive', 'no' );
	}

	/**
	 * @param mixed $args
	 * @param mixed $name
	 * @return mixed
	 */
	public static function gate_mcp_exposure( $args, $name ) {
		if ( ! is_string( $name ) || 0 !== strpos( $name, 'moksafocou/' ) || ! is_array( $args ) ) {
			return $args;
		}
		$destructive = ! empty( $args['meta']['annotations']['destructive'] );
		if ( $destructive && ! self::expose_destructive() ) {
			$args['meta']['mcp']['public'] = false;
			self::$mcp_hidden[ $name ]     = true;
		}
		return $args;
	}

	/**
	 * @param mixed  $included
	 * @param string $ability_id
	 * @return mixed
	 */
	public static function include_in_mcp( $included, $ability_id ) {
		if ( is_string( $ability_id ) && 0 === strpos( $ability_id, 'moksafocou/' ) ) {
			return empty( self::$mcp_hidden[ $ability_id ] );
		}
		return $included;
	}
}
