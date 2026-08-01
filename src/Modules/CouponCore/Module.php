<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponCore;

use Moksafocou\Coupon\CouponService;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon core module. Always boots (it is the heart of the plugin) and wires:
 * - the WordPress Abilities (command palette / REST / AI / MCP), behind a 6.9+ guard
 * - the two MCP exposure gate filters (registered before the init action fires)
 * - the plugin's own REST controller for direct programmatic CRUD
 */
final class Module {

	public static function boot(): void {
		// Abilities require WordPress 6.9+ core Abilities API — degrade gracefully.
		if ( function_exists( 'wp_register_ability' ) ) {
			add_action( 'wp_abilities_api_categories_init', [ Ability::class, 'register_category' ] );
			add_action( 'wp_abilities_api_init', [ Ability::class, 'register' ] );
			// Second-wave discovery / report / template / lifecycle abilities.
			add_action( 'wp_abilities_api_init', [ ToolsAbility::class, 'register' ] );
			// gate_mcp_exposure must be added before the init action fires.
			add_filter( 'wp_register_ability_args', [ Ability::class, 'gate_mcp_exposure' ], 10, 2 );
			add_filter( 'woocommerce_mcp_include_ability', [ Ability::class, 'include_in_mcp' ], 10, 2 );
		}

		add_action( 'rest_api_init', [ CouponRest::class, 'register' ] );

		// Self-built MCP server (1:1 tools). Self-gates on the server_enabled option.
		add_action( 'rest_api_init', [ \Moksafocou\Mcp\Server::class, 'register' ] );

		// Register coupon abilities + destructive handlers into the AI assistant
		// (decoupled — the assistant has no hard dependency on this module).
		add_filter( 'moksafocou_ai_assistant_abilities', [ self::class, 'ai_abilities' ] );
		add_filter( 'moksafocou_ai_destructive_handlers', [ self::class, 'ai_handlers' ] );
		add_filter( 'moksafocou_ai_rewrite_call_args', [ self::class, 'rewrite_ai_args' ], 10, 3 );
	}

	/**
	 * Deterministically enforce Taiwan "N 折" semantics on AI create/update calls.
	 * If the user's message contained "9 折" etc., force discount_type=percent with
	 * the correct amount regardless of what the model filled in (small models often
	 * miscompute 折). The confirm card then shows the corrected value.
	 *
	 * @param mixed  $args    The model's call arguments.
	 * @param mixed  $ability The ability id.
	 * @param mixed  $message The original user message.
	 * @return array<string,mixed>
	 */
	public static function rewrite_ai_args( $args, $ability, $message ): array {
		$args = is_array( $args ) ? $args : [];
		if ( ! in_array( $ability, [ 'moksafocou/create-coupon', 'moksafocou/update-coupon' ], true ) ) {
			return $args;
		}
		$amount = CouponService::zhe_first_amount( is_string( $message ) ? $message : '' );
		if ( null !== $amount ) {
			$args['discount_type'] = 'percent';
			$args['amount']        = $amount;
		}
		return $args;
	}

	/**
	 * @param array<int,string> $abilities
	 * @return array<int,string>
	 */
	public static function ai_abilities( array $abilities ): array {
		return array_merge(
			$abilities,
			[
				'moksafocou/list-coupons',
				'moksafocou/get-coupon',
				'moksafocou/find-coupon-by-code',
				'moksafocou/coupon-usage-summary',
				'moksafocou/create-coupon',
				'moksafocou/update-coupon',
				'moksafocou/toggle-coupon',
				'moksafocou/delete-coupon',
				'moksafocou/bulk-generate-coupons',
				'moksafocou/extend-expiry',
				'moksafocou/duplicate-coupon',
				// Discovery / lookup (read).
				'moksafocou/list-rule-types',
				'moksafocou/get-settings-schema',
				'moksafocou/list-payment-gateways',
				'moksafocou/list-shipping-zones',
				'moksafocou/list-countries',
				'moksafocou/validate-rules',
				'moksafocou/get-coupon-report',
				'moksafocou/list-templates',
				'moksafocou/list-scheduled-coupons',
				// Shortcuts / lifecycle (propose-only writes).
				'moksafocou/create-tiered-coupon',
				'moksafocou/apply-template',
				'moksafocou/restore-coupon',
				'moksafocou/expire-now',
				'moksafocou/bulk-reschedule-expiry',
			]
		);
	}

	/**
	 * @param array<string,array{prepare:callable,apply:callable}> $handlers
	 * @return array<string,array{prepare:callable,apply:callable}>
	 */
	public static function ai_handlers( array $handlers ): array {
		return array_merge(
			$handlers,
			[
				'moksafocou/create-coupon'          => [
					'prepare' => [ CouponOps::class, 'create_prepare' ],
					'apply'   => [ CouponOps::class, 'create_apply' ],
				],
				'moksafocou/update-coupon'          => [
					'prepare' => [ CouponOps::class, 'update_prepare' ],
					'apply'   => [ CouponOps::class, 'update_apply' ],
				],
				'moksafocou/toggle-coupon'          => [
					'prepare' => [ CouponOps::class, 'toggle_prepare' ],
					'apply'   => [ CouponOps::class, 'toggle_apply' ],
				],
				'moksafocou/delete-coupon'          => [
					'prepare' => [ CouponOps::class, 'delete_prepare' ],
					'apply'   => [ CouponOps::class, 'delete_apply' ],
				],
				'moksafocou/bulk-generate-coupons'  => [
					'prepare' => [ CouponOps::class, 'bulk_generate_prepare' ],
					'apply'   => [ CouponOps::class, 'bulk_generate_apply' ],
				],
				'moksafocou/extend-expiry'          => [
					'prepare' => [ CouponOps::class, 'extend_expiry_prepare' ],
					'apply'   => [ CouponOps::class, 'extend_expiry_apply' ],
				],
				'moksafocou/duplicate-coupon'       => [
					'prepare' => [ CouponOps::class, 'duplicate_prepare' ],
					'apply'   => [ CouponOps::class, 'duplicate_apply' ],
				],
				'moksafocou/create-tiered-coupon'   => [
					'prepare' => [ CouponOps::class, 'create_tiered_prepare' ],
					'apply'   => [ CouponOps::class, 'create_apply' ],
				],
				'moksafocou/apply-template'         => [
					'prepare' => [ CouponOps::class, 'apply_template_prepare' ],
					'apply'   => [ CouponOps::class, 'apply_template_apply' ],
				],
				'moksafocou/restore-coupon'         => [
					'prepare' => [ CouponOps::class, 'restore_prepare' ],
					'apply'   => [ CouponOps::class, 'restore_apply' ],
				],
				'moksafocou/expire-now'             => [
					'prepare' => [ CouponOps::class, 'expire_now_prepare' ],
					'apply'   => [ CouponOps::class, 'expire_now_apply' ],
				],
				'moksafocou/bulk-reschedule-expiry' => [
					'prepare' => [ CouponOps::class, 'bulk_reschedule_prepare' ],
					'apply'   => [ CouponOps::class, 'bulk_reschedule_apply' ],
				],
			]
		);
	}
}
