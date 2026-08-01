<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\MixMatch;

use Moksafocou\Support\AbilityMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the destructive ability moksafocou/create-mixmatch-coupon so the AI assistant /
 * command palette / MCP can build a 'Mix & Match' coupon in one shot. The execute_callback is the
 * propose-only MixMatchOps::create_prepare; the real write runs via the confirm flow. Marked
 * destructive, so the MCP gate hides it unless moksafocou_mcp_expose_destructive=yes.
 */
final class Ability {

	public const CATEGORY = 'moksafocou';

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			'moksafocou/create-mixmatch-coupon',
			array(
				'label'               => __( 'Create a Mix & Match coupon', 'moksafocou' ),
				'description'         => __( 'Create a "Mix & Match" coupon: specify a set of products (or the whole site), the customer picks any N items, and the group is priced at a fixed total or a group percentage discount, repeatable. Example: pick 3 for $299 → qty=3, price_mode=fixed_total, price_value=299; pick 5 at 25% off → qty=5, price_mode=percent, price_value=25. Destructive — the call only "proposes"; it is created only after the user confirms.', 'moksafocou' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'code'           => array(
							'type'        => 'string',
							'description' => __( 'Coupon code, e.g. PICK3FOR299', 'moksafocou' ),
						),
						'product_ids'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Selectable product IDs (leave empty + no categories = whole site)', 'moksafocou' ),
						),
						'category_ids'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Selectable product category term IDs', 'moksafocou' ),
						),
						'qty'            => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Number of items to pick, N (at least 1)', 'moksafocou' ),
						),
						'price_mode'     => array(
							'type'        => 'string',
							'enum'        => array( 'fixed_total', 'percent' ),
							'description' => __( 'fixed_total: group fixed total / percent: group discount percentage', 'moksafocou' ),
						),
						'price_value'    => array(
							'type'        => 'number',
							'minimum'     => 0,
							'description' => __( 'fixed_total is the group total; percent is the discount % (0–100)', 'moksafocou' ),
						),
						'deal_mode'      => array(
							'type'        => 'string',
							'enum'        => array( 'once', 'repeat' ),
							'description' => __( 'once = one time only / repeat = repeatable (default repeat)', 'moksafocou' ),
						),
						'repeat_limit'   => array(
							'type'        => 'integer',
							'description' => __( 'Repeat limit (0 = no limit, applies to repeat only)', 'moksafocou' ),
						),
						'notice_message' => array(
							'type'        => 'string',
							'description' => __( 'Add-more prompt; you can use {mixmatch_qty} {coupon_code}', 'moksafocou' ),
						),
						'date_expires'   => array(
							'type'        => 'string',
							'description' => __( 'Expiry date YYYY-MM-DD (optional)', 'moksafocou' ),
						),
						'usage_limit'    => array( 'type' => 'integer' ),
						'individual_use' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'code', 'qty', 'price_mode' ),
					'additionalProperties' => false,
				),
				'output_schema'       => AbilityMeta::summary_output(),
				'execute_callback'    => array( MixMatchOps::class, 'create_prepare' ),
				'permission_callback' => array( self::class, 'can_write' ),
				'meta'                => AbilityMeta::write(),
			)
		);
	}

	public static function can_write(): bool {
		return current_user_can( MixMatchOps::CAP );
	}
}
