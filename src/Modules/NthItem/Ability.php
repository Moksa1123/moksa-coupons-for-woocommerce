<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\NthItem;

use Moksafocou\Support\AbilityMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the destructive ability moksafocou/create-nth-item-coupon so the AI assistant /
 * command palette / MCP can build a 'Nth-item discount' coupon in one shot. The execute_callback is the
 * propose-only NthItemOps::create_prepare; the real write runs via the confirm flow
 * (NthItemOps::create_apply). Marked destructive, so the MCP gate hides it unless
 * moksafocou_mcp_expose_destructive=yes.
 */
final class Ability {

	public const CATEGORY = 'moksafocou';

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			'moksafocou/create-nth-item-coupon',
			array(
				'label'               => __( 'Create an Nth-item discount coupon', 'moksafocou' ),
				'description'         => __( 'Create an "Nth-item discount" coupon: for the same set of products (or the whole site), every N items qualifies the Nth item for free / percentage / fixed discount per item, once or repeatable. Example: second item 40% off → n=2, reward_mode=percent, reward_value=40. Destructive — the call only "proposes"; it is created only after the user confirms.', 'moksafocou' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'code'           => array(
							'type'        => 'string',
							'description' => __( 'Coupon code, e.g. SECOND60', 'moksafocou' ),
						),
						'product_ids'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Applicable product IDs (leave empty + no categories = whole site)', 'moksafocou' ),
						),
						'category_ids'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Applicable product category term IDs', 'moksafocou' ),
						),
						'group_by'       => array(
							'type'        => 'string',
							'enum'        => array( 'cart', 'product' ),
							'description' => __( 'cart: combine the whole cart / product: count each product separately (default cart)', 'moksafocou' ),
						),
						'n'              => array(
							'type'        => 'integer',
							'minimum'     => 2,
							'description' => __( 'Discount one item per how many items, N (at least 2; enter 2 to discount the second item)', 'moksafocou' ),
						),
						'reward_mode'    => array(
							'type'        => 'string',
							'enum'        => array( 'free', 'percent', 'fixed_per_item' ),
							'description' => __( 'Discount type: free / percent: discount percentage / fixed_per_item: fixed amount per item', 'moksafocou' ),
						),
						'reward_value'   => array(
							'type'        => 'number',
							'minimum'     => 0,
							'description' => __( 'Discount amount; percent is the discount % (40% off = 40), fixed_per_item is an amount, leave empty for free', 'moksafocou' ),
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
							'description' => __( 'Add-more prompt; you can use {nth_n} {coupon_code}', 'moksafocou' ),
						),
						'date_expires'   => array(
							'type'        => 'string',
							'description' => __( 'Expiry date YYYY-MM-DD (optional)', 'moksafocou' ),
						),
						'usage_limit'    => array( 'type' => 'integer' ),
						'individual_use' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'code', 'n', 'reward_mode' ),
					'additionalProperties' => false,
				),
				'output_schema'       => AbilityMeta::summary_output(),
				'execute_callback'    => array( NthItemOps::class, 'create_prepare' ),
				'permission_callback' => array( self::class, 'can_write' ),
				'meta'                => AbilityMeta::write(),
			)
		);
	}

	public static function can_write(): bool {
		return current_user_can( NthItemOps::CAP );
	}
}
