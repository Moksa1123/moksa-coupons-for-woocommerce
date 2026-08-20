<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponCore;

use Moksafocou\Coupon\Meta\CouponSettings;
use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\AbilityMeta;
use Moksafocou\Support\Rules;
use Moksafocou\Modules\Reports\ReportService;
use Moksafocou\Modules\Templates\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Discovery / lookup / report / template / lifecycle abilities — the second wave that
 * makes the marquee feature surface (advanced rules, tiers, region/payment conditions,
 * templates, analytics) actually usable by AI / MCP. Read abilities run directly; the
 * four write abilities are propose-only (execute_callback → CouponOps::*_prepare) and
 * confirmed via the same human-confirm flow as the core writes.
 *
 * Registered on wp_abilities_api_init alongside CouponCore\Ability, so the same MCP
 * exposure gate and category apply.
 */
final class ToolsAbility {

	private const CATEGORY = Ability::CATEGORY;
	private const CAP      = Ability::CAP;

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		self::register_discovery();
		self::register_report_templates();
		self::register_writes();
	}

	/* ---------------- discovery / lookup (read) ---------------- */

	private static function register_discovery(): void {
		wp_register_ability(
			'moksafocou/list-rule-types',
			[
				'label'               => __( 'List advanced rule types', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'List all 26 condition types available for "Advanced rules (AND/OR)", each with its allowed operators (op) and value shape. Check this before building moksafocou.advanced_rules. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::empty_input(),
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'types' => [ 'type' => 'object' ] ],
				],
				'execute_callback'    => [ self::class, 'execute_rule_types' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/get-settings-schema',
			[
				'label'               => __( 'Get advanced settings schema', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Return the complete JSON schema of the moksafocou advanced settings object for create-coupon / update-coupon (schedule / conditions / tiers / advanced rules / BOGO / gift / shipping…). Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::empty_input(),
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'schema' => [ 'type' => 'object' ] ],
				],
				'execute_callback'    => [ self::class, 'execute_settings_schema' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/list-payment-gateways',
			[
				'label'               => __( 'List payment methods', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'List the id, name, and enabled status of all payment methods in this store — use the real id when setting "Payment method conditions" or a payment_method rule. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::empty_input(),
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'gateways' => [ 'type' => 'array' ] ],
				],
				'execute_callback'    => [ self::class, 'execute_gateways' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/list-shipping-zones',
			[
				'label'               => __( 'List shipping zones', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'List the id and name of WooCommerce shipping zones (including the "Locations not covered" zone 0) — use the real id for shipping_zone rules. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::empty_input(),
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'zones' => [ 'type' => 'array' ] ],
				],
				'execute_callback'    => [ self::class, 'execute_zones' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/list-countries',
			[
				'label'               => __( 'List country codes', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'List the ISO codes and names of countries / regions — use the uppercase code (e.g. TW) when setting "Shipping region conditions" or a shipping_country rule. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::empty_input(),
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'countries' => [ 'type' => 'array' ] ],
				],
				'execute_callback'    => [ self::class, 'execute_countries' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/validate-rules',
			[
				'label'               => __( 'Validate advanced rule tree', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Dry-run an "Advanced rules" tree: return whether there are valid rules, the normalized result, the types used, and any unknown types that were dropped; if sample_cart is given, also return whether it passes. Does not write. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'rules'       => [
							// 單一 type：聯集型別會被 Gemini 整包退掉（連帶弄垮其他外掛的工具）。
							// handler 的 Rules::parse() 與 raw_rule_types() 本來就會把 JSON 字串 decode。
							'type'        => 'string',
							'description' => __( 'Rule tree as a JSON string', 'moksa-coupons-for-woocommerce' ),
						],
						'sample_cart' => [
							'type'        => 'object',
							'description' => __( 'Optional scenario (subtotal/qty/products/categories/country/payment/roles/weight…) used to test whether it passes', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [ 'rules' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'valid'                 => [ 'type' => 'boolean' ],
						'used_types'            => [ 'type' => 'array' ],
						'unknown_types_dropped' => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_validate_rules' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);
	}

	/* ---------------- report + templates (read) ---------------- */

	private static function register_report_templates(): void {
		wp_register_ability(
			'moksafocou/get-coupon-report',
			[
				'label'               => __( 'Coupon performance report', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Orders used and total discount for each coupon (paid orders only), sorted by discount amount from high to low. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'limit'         => [
							'type'        => 'integer',
							'description' => __( 'Maximum number of results to return (default 20)', 'moksa-coupons-for-woocommerce' ),
						],
						'sort'          => [
							'type'        => 'string',
							'enum'        => [ 'discount', 'orders' ],
							'description' => __( 'Sort: discount (default) / orders (order count)', 'moksa-coupons-for-woocommerce' ),
						],
						'force_refresh' => [
							'type'        => 'boolean',
							'description' => __( 'Ignore the 1-hour cache and recalculate', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'count' => [ 'type' => 'integer' ],
						'rows'  => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_report' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/coupon-revenue-overview',
			[
				'label'               => __( 'Coupon revenue overview', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Revenue, total discount, order count, and average order value of paid orders with coupons over the last N days, plus a daily trend. Answers "how much business coupons brought in". Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'days' => [
							'type'        => 'integer',
							'description' => __( 'Number of recent days to analyze (1–365, default 30)', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'days'           => [ 'type' => 'integer' ],
						'coupon_orders'  => [ 'type' => 'integer' ],
						'coupon_revenue' => [ 'type' => 'number' ],
						'total_discount' => [ 'type' => 'number' ],
						'daily'          => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_overview' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/coupon-campaign-report',
			[
				'label'               => __( 'Coupon campaign report', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Aggregate the coupon count, orders used, discount, and revenue for each campaign by "Campaign" tag (paid orders only), sorted by discount from high to low. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::empty_input(),
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'count' => [ 'type' => 'integer' ],
						'rows'  => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_campaign_report' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/suggest-coupons',
			[
				'label'               => __( 'Suggest coupons', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Make specific coupon suggestions based on store data (average order value with coupons, slow-moving products, currently most effective coupons, coupon activity), each with parameters you can create directly. Read-only analysis.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::empty_input(),
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'count'       => [ 'type' => 'integer' ],
						'suggestions' => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_suggest' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/audit-coupons',
			[
				'label'               => __( 'Coupon health check', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Find coupons that need attention: expired but still enabled, expiring within 7 days but never used, percentage discount too high (margin risk). Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::empty_input(),
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'count'  => [ 'type' => 'integer' ],
						'issues' => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_audit' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/list-templates',
			[
				'label'               => __( 'List coupon templates', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'List the built-in coupon templates (id, name, description, category, discount type, required modules). Pair with apply-template for one-click coupon creation. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'category' => [
							'type'        => 'string',
							'description' => __( 'List only a specific category (acquisition/aov/shipping/promo/seasonal/bonus/member)', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'count'     => [ 'type' => 'integer' ],
						'templates' => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_templates' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/list-scheduled-coupons',
			[
				'label'               => __( 'List schedule / expiry status', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'List coupons\' expiry dates, schedule start/end, and campaign, sorted by expiry date; you can view a single campaign only. Use this when managing multi-stage campaigns or coupons nearing expiry. Read-only.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'campaign' => [
							'type'        => 'string',
							'description' => __( 'List only coupons in this campaign', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'count'   => [ 'type' => 'integer' ],
						'coupons' => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_scheduled' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'meta'                => self::read_meta(),
			]
		);
	}

	/* ---------------- write (propose-only) ---------------- */

	private static function register_writes(): void {
		wp_register_ability(
			'moksafocou/create-tiered-coupon',
			[
				'label'               => __( 'Create a tiered discount coupon', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Create a percentage coupon that "gives different discounts by cart threshold" using a simple tier table (e.g. spend 1000 get 10% off, spend 2000 get 20% off). Destructive — the call only "proposes"; it is created only after the user confirms.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'code'              => [
							'type'        => 'string',
							'description' => __( 'Coupon code', 'moksa-coupons-for-woocommerce' ),
						],
						'basis'             => [
							'type'        => 'string',
							'enum'        => [ 'subtotal', 'quantity', 'weight' ],
							'description' => __( 'Tier basis: subtotal (default) / quantity / weight', 'moksa-coupons-for-woocommerce' ),
						],
						'tiers'             => [
							'type'        => 'array',
							'description' => __( 'Tier rows, each { threshold, kind percent|fixed, value }. For percent the value is a 0-100 percentage (10 = 10% off); for fixed the value is a fixed discount amount. They can be mixed.', 'moksa-coupons-for-woocommerce' ),
							'items'       => [
								'type'                 => 'object',
								'properties'           => [
									'threshold' => [ 'type' => 'number' ],
									'kind'      => [
										'type' => 'string',
										'enum' => [ 'percent', 'fixed' ],
									],
									'value'     => [ 'type' => 'number' ],
								],
								'additionalProperties' => false,
							],
						],
						'target_mode'       => [
							'type'        => 'string',
							'enum'        => [ 'cart', 'products', 'categories' ],
							'description' => __( 'Discount application scope: cart (whole cart, default) / products / categories', 'moksa-coupons-for-woocommerce' ),
						],
						'target_products'   => [
							'type'  => 'array',
							'items' => [ 'type' => 'integer' ],
						],
						'target_categories' => [
							'type'  => 'array',
							'items' => [ 'type' => 'integer' ],
						],
						'date_expires'      => [ 'type' => 'string' ],
						'usage_limit'       => [ 'type' => 'integer' ],
						'description'       => [ 'type' => 'string' ],
					],
					'required'             => [ 'code', 'tiers' ],
					'additionalProperties' => false,
				],
				'output_schema'       => self::summary_output(),
				'execute_callback'    => [ CouponOps::class, 'create_tiered_prepare' ],
				'permission_callback' => [ self::class, 'can_write' ],
				'meta'                => self::write_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/apply-template',
			[
				'label'               => __( 'Apply coupon template', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Apply a built-in template to create a draft coupon (first use list-templates to get the template_id). You can fine-tune code / amount / date_expires / usage_limit, etc. Destructive — the call only "proposes"; it is created only after the user confirms.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'template_id' => [
							'type'        => 'string',
							'description' => __( 'Template id (from list-templates)', 'moksa-coupons-for-woocommerce' ),
						],
						'overrides'   => [
							'type'                 => 'object',
							'description'          => __( 'Optional fine-tuning', 'moksa-coupons-for-woocommerce' ),
							'properties'           => [
								'code'                 => [ 'type' => 'string' ],
								'amount'               => [ 'type' => 'number' ],
								'date_expires'         => [ 'type' => 'string' ],
								'usage_limit'          => [ 'type' => 'integer' ],
								'usage_limit_per_user' => [ 'type' => 'integer' ],
								'description'          => [ 'type' => 'string' ],
								'individual_use'       => [ 'type' => 'string' ],
							],
							'additionalProperties' => false,
						],
					],
					'required'             => [ 'template_id' ],
					'additionalProperties' => false,
				],
				'output_schema'       => self::summary_output(),
				'execute_callback'    => [ CouponOps::class, 'apply_template_prepare' ],
				'permission_callback' => [ self::class, 'can_write' ],
				'meta'                => self::write_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/restore-coupon',
			[
				'label'               => __( 'Restore deleted coupon', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Restore a coupon from the trash as a draft (provide the numeric ID). Destructive — the call only "proposes"; it is restored only after the user confirms.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'code_or_id' => [
							'type'        => 'string',
							'description' => __( 'Numeric ID of the deleted coupon', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [ 'code_or_id' ],
					'additionalProperties' => false,
				],
				'output_schema'       => self::summary_output(),
				'execute_callback'    => [ CouponOps::class, 'restore_prepare' ],
				'permission_callback' => [ self::class, 'can_write' ],
				'meta'                => self::write_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/expire-now',
			[
				'label'               => __( 'Expire immediately', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Set one or more coupons to expire immediately (expiry date set to yesterday). Ends a campaign "right now" more explicitly than disabling. Destructive — the call only "proposes"; it takes effect only after the user confirms.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'codes_or_ids' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'Array of coupon codes or IDs', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [ 'codes_or_ids' ],
					'additionalProperties' => false,
				],
				'output_schema'       => self::summary_output(),
				'execute_callback'    => [ CouponOps::class, 'expire_now_prepare' ],
				'permission_callback' => [ self::class, 'can_write' ],
				'meta'                => self::write_meta(),
			]
		);

		wp_register_ability(
			'moksafocou/bulk-reschedule-expiry',
			[
				'label'               => __( 'Batch adjust expiry date', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Set the expiry date of multiple coupons to the same day at once (leave date_expires empty = clear the expiry date to make them permanent). Handy for extending or ending campaigns early. Destructive — the call only "proposes"; it takes effect only after the user confirms.', 'moksa-coupons-for-woocommerce' ),
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
							'description' => __( 'New expiry date YYYY-MM-DD; leave empty = clear the expiry date', 'moksa-coupons-for-woocommerce' ),
						],
					],
					'required'             => [ 'codes_or_ids' ],
					'additionalProperties' => false,
				],
				'output_schema'       => self::summary_output(),
				'execute_callback'    => [ CouponOps::class, 'bulk_reschedule_prepare' ],
				'permission_callback' => [ self::class, 'can_write' ],
				'meta'                => self::write_meta(),
			]
		);
	}

	/* ---------------- read execute callbacks ---------------- */

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_scheduled( $input ): array {
		if ( ! self::can_read() ) {
			return [
				'count'   => 0,
				'coupons' => [],
			];
		}
		$input    = is_array( $input ) ? $input : [];
		$campaign = isset( $input['campaign'] ) ? trim( (string) $input['campaign'] ) : '';
		$query    = new \WP_Query(
			[
				'post_type'      => 'shop_coupon',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			]
		);
		$rows     = [];
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$id   = (int) $post->ID;
			$camp = (string) get_post_meta( $id, Keys::CAMPAIGN, true );
			if ( '' !== $campaign && 0 !== strcasecmp( $camp, $campaign ) ) {
				continue;
			}
			$coupon  = new \WC_Coupon( $id );
			$expires = $coupon->get_date_expires();
			$rows[]  = [
				'code'           => $coupon->get_code(),
				'status'         => (string) get_post_status( $id ),
				'date_expires'   => $expires ? $expires->date( 'Y-m-d' ) : '',
				'schedule_start' => (string) get_post_meta( $id, Keys::SCHEDULE_START, true ),
				'schedule_end'   => (string) get_post_meta( $id, Keys::SCHEDULE_END, true ),
				'campaign'       => $camp,
			];
		}
		// Soonest expiry first; coupons with no expiry sort last.
		usort(
			$rows,
			static fn( array $a, array $b ): int => ( '' === $a['date_expires'] ? '9999-12-31' : $a['date_expires'] )
				<=> ( '' === $b['date_expires'] ? '9999-12-31' : $b['date_expires'] )
		);
		return [
			'count'   => count( $rows ),
			'coupons' => $rows,
		];
	}

	/** @return array<string,mixed> */
	public static function execute_rule_types(): array {
		if ( ! self::can_read() ) {
			return [];
		}
		return [ 'types' => Rules::types() ];
	}

	/** @return array<string,mixed> */
	public static function execute_settings_schema(): array {
		if ( ! self::can_read() ) {
			return [];
		}
		return [ 'schema' => CouponSettings::schema() ];
	}

	/** @return array<string,mixed> */
	public static function execute_gateways(): array {
		if ( ! self::can_read() || ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return [ 'gateways' => [] ];
		}
		$out = [];
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( is_object( $gateway ) && isset( $gateway->id ) ) {
				$out[] = [
					'id'      => (string) $gateway->id,
					'title'   => method_exists( $gateway, 'get_title' ) ? wp_strip_all_tags( (string) $gateway->get_title() ) : (string) $gateway->id,
					'enabled' => isset( $gateway->enabled ) && 'yes' === $gateway->enabled,
				];
			}
		}
		return [ 'gateways' => $out ];
	}

	/** @return array<string,mixed> */
	public static function execute_zones(): array {
		if ( ! self::can_read() || ! class_exists( '\WC_Shipping_Zones' ) ) {
			return [ 'zones' => [] ];
		}
		$out = [];
		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			if ( isset( $zone['id'], $zone['zone_name'] ) ) {
				$out[] = [
					'id'   => (int) $zone['id'],
					'name' => (string) $zone['zone_name'],
				];
			}
		}
		$out[] = [
			'id'   => 0,
			'name' => __( 'Other regions (not covered)', 'moksa-coupons-for-woocommerce' ),
		];
		return [ 'zones' => $out ];
	}

	/** @return array<string,mixed> */
	public static function execute_countries(): array {
		if ( ! self::can_read() || ! function_exists( 'WC' ) || ! WC()->countries ) {
			return [ 'countries' => [] ];
		}
		$out = [];
		foreach ( WC()->countries->get_countries() as $code => $name ) {
			$out[] = [
				'code' => (string) $code,
				'name' => (string) $name,
			];
		}
		return [ 'countries' => $out ];
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_validate_rules( $input ): array {
		if ( ! self::can_read() ) {
			return [];
		}
		$input   = is_array( $input ) ? $input : [];
		$raw     = $input['rules'] ?? '';
		$set     = Rules::parse( $raw );
		$known   = Rules::type_keys();
		$dropped = array_values(
			array_unique(
				array_filter(
					self::raw_rule_types( $raw ),
					static fn( string $t ): bool => ! in_array( $t, $known, true )
				)
			)
		);
		$out     = [
			'valid'                 => [] !== $set['groups'],
			'normalized'            => $set,
			'used_types'            => Rules::types_used( $set ),
			'unknown_types_dropped' => $dropped,
		];
		if ( isset( $input['sample_cart'] ) && is_array( $input['sample_cart'] ) ) {
			$out['would_pass'] = Rules::evaluate( $set, $input['sample_cart'], false );
		}
		return $out;
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_report( $input ): array {
		if ( ! self::can_read() ) {
			return [
				'count' => 0,
				'rows'  => [],
			];
		}
		$input = is_array( $input ) ? $input : [];
		$rows  = ReportService::compute( ! empty( $input['force_refresh'] ) );
		if ( ( $input['sort'] ?? 'discount' ) === 'orders' ) {
			usort( $rows, static fn( array $a, array $b ): int => (int) $b['orders'] <=> (int) $a['orders'] );
		}
		$limit = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 20;
		$rows  = array_slice( $rows, 0, $limit );
		return [
			'count' => count( $rows ),
			'rows'  => $rows,
		];
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_overview( $input ): array {
		if ( ! self::can_read() ) {
			return [ 'coupon_orders' => 0 ];
		}
		$input = is_array( $input ) ? $input : [];
		$days  = isset( $input['days'] ) ? (int) $input['days'] : 30;
		return ReportService::overview( $days );
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_campaign_report( $input ): array {
		if ( ! self::can_read() ) {
			return [
				'count' => 0,
				'rows'  => [],
			];
		}
		$rows = ReportService::by_campaign();
		return [
			'count' => count( $rows ),
			'rows'  => $rows,
		];
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_suggest( $input ): array {
		if ( ! self::can_read() ) {
			return [
				'count'       => 0,
				'suggestions' => [],
			];
		}
		$s = Advisor::suggestions();
		return [
			'count'       => count( $s ),
			'suggestions' => $s,
		];
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_audit( $input ): array {
		if ( ! self::can_read() ) {
			return [
				'count'  => 0,
				'issues' => [],
			];
		}
		$i = Advisor::audit();
		return [
			'count'  => count( $i ),
			'issues' => $i,
		];
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_templates( $input ): array {
		if ( ! self::can_read() ) {
			return [
				'count'     => 0,
				'templates' => [],
			];
		}
		$input    = is_array( $input ) ? $input : [];
		$category = isset( $input['category'] ) ? (string) $input['category'] : '';
		$out      = [];
		foreach ( Catalog::all() as $tpl ) {
			if ( '' !== $category && ( $tpl['category'] ?? '' ) !== $category ) {
				continue;
			}
			$out[] = [
				'id'       => (string) ( $tpl['id'] ?? '' ),
				'label'    => (string) ( $tpl['label'] ?? '' ),
				'desc'     => (string) ( $tpl['desc'] ?? '' ),
				'category' => (string) ( $tpl['category'] ?? '' ),
				'type_key' => (string) ( $tpl['type_key'] ?? '' ),
				'requires' => Catalog::required_modules( $tpl ),
			];
		}
		return [
			'count'     => count( $out ),
			'templates' => $out,
		];
	}

	/**
	 * Flat list of rule types named in a raw (pre-parse) tree, to surface dropped/unknown.
	 *
	 * @param mixed $raw
	 * @return array<int,string>
	 */
	private static function raw_rule_types( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : [];
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$types = [];
		foreach ( ( $raw['groups'] ?? [] ) as $group ) {
			foreach ( ( is_array( $group ) ? ( $group['rules'] ?? [] ) : [] ) as $rule ) {
				if ( is_array( $rule ) && isset( $rule['type'] ) ) {
					$types[] = (string) $rule['type'];
				}
			}
		}
		return $types;
	}

	/* ---------------- helpers ---------------- */

	public static function can_read(): bool {
		return current_user_can( self::CAP );
	}

	public static function can_write(): bool {
		return current_user_can( CouponOps::CAP );
	}

	/**
	 * Input schema for a no-argument ability. `properties` MUST be an object literal so it
	 * serializes to JSON `{}` (not `[]`): the WordPress AI Client passes this verbatim into
	 * each LLM function declaration, and providers reject `"properties":[]` with "[] is not
	 * of type 'object'", which 400s the whole request (every tool is validated up front).
	 * WP_Ability stores input_schema as-is, so the object cast survives registration.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty_input(): array {
		return AbilityMeta::empty_input();
	}

	/** @return array<string,mixed> */
	private static function summary_output(): array {
		return AbilityMeta::summary_output();
	}

	/** @return array<string,mixed> */
	private static function read_meta(): array {
		return AbilityMeta::read();
	}

	/** @return array<string,mixed> */
	private static function write_meta(): array {
		return AbilityMeta::write();
	}
}
