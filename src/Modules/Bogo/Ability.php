<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Bogo;

use Moksafocou\Support\AbilityMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the destructive ability moksafocou/create-bogo-coupon so the AI
 * assistant / command palette / MCP can build a 'Buy X Get Y' coupon in one shot. The
 * execute_callback is the propose-only BogoOps::create_prepare; the real write runs
 * via the confirm flow (BogoOps::create_apply). Marked destructive, so the existing
 * MCP gate hides it unless moksafocou_mcp_expose_destructive=yes.
 */
final class Ability {

	public const CATEGORY = 'moksa-coupons-for-woocommerce';

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			'moksafocou/create-bogo-coupon',
			array(
				'label'               => __( 'Create a Buy X Get Y coupon', 'moksa-coupons-for-woocommerce' ),
				'description'         => __( 'Create a "Buy X Get Y (BOGO)" coupon: after the customer buys the specified products / categories in the required quantity, the gift items in the cart can be free / percentage / fixed discount, and it can be set to once or repeatable. Destructive — the call only "proposes" it, and it is created only after the user confirms.', 'moksa-coupons-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'code'                 => array(
							'type'        => 'string',
							'description' => __( 'Coupon code, e.g. BUY2GET1', 'moksa-coupons-for-woocommerce' ),
						),
						'trigger_product_ids'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Purchase-condition product IDs (use either categories or both)', 'moksa-coupons-for-woocommerce' ),
						),
						'trigger_category_ids' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Purchase-condition product category term IDs', 'moksa-coupons-for-woocommerce' ),
						),
						'trigger_qty'          => array(
							'type'        => 'integer',
							'description' => __( 'Quantity N to purchase (default 1)', 'moksa-coupons-for-woocommerce' ),
						),
						'reward_product_ids'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Gift product IDs', 'moksa-coupons-for-woocommerce' ),
						),
						'reward_category_ids'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Gift product category term IDs', 'moksa-coupons-for-woocommerce' ),
						),
						'reward_qty'           => array(
							'type'        => 'integer',
							'description' => __( 'Gift / discount quantity M (default 1)', 'moksa-coupons-for-woocommerce' ),
						),
						'reward_mode'          => array(
							'type'        => 'string',
							'enum'        => array( 'free', 'percent', 'fixed_per_item' ),
							'description' => __( 'Discount type: free / percent / fixed_per_item (fixed amount per item)', 'moksa-coupons-for-woocommerce' ),
						),
						'reward_value'         => array(
							'type'        => 'number',
							'description' => __( 'Discount amount; 0–100 for percent, an amount for fixed_per_item, leave empty for free', 'moksa-coupons-for-woocommerce' ),
						),
						'deal_mode'            => array(
							'type'        => 'string',
							'enum'        => array( 'once', 'repeat' ),
							'description' => __( 'once (only once) / repeat (repeatable) (default once)', 'moksa-coupons-for-woocommerce' ),
						),
						'repeat_limit'         => array(
							'type'        => 'integer',
							'description' => __( 'Repeat limit (0 = no limit, applies to repeat only)', 'moksa-coupons-for-woocommerce' ),
						),
						'date_expires'         => array(
							'type'        => 'string',
							'description' => __( 'Expiry date YYYY-MM-DD (optional)', 'moksa-coupons-for-woocommerce' ),
						),
						'usage_limit'          => array( 'type' => 'integer' ),
						'individual_use'       => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'code', 'reward_mode' ),
					'additionalProperties' => false,
				),
				'output_schema'       => AbilityMeta::summary_output(),
				'execute_callback'    => array( BogoOps::class, 'create_prepare' ),
				'permission_callback' => array( self::class, 'can_write' ),
				'meta'                => AbilityMeta::write(),
			)
		);
	}

	public static function can_write(): bool {
		return current_user_can( BogoOps::CAP );
	}
}
