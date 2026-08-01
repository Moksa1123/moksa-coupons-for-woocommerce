<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponSend;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Send-coupon module — lazy-loaded, boots only when moksafocou_send_enabled is 'yes'.
 * Registers the destructive `send-coupon` ability (command palette / REST / AI / MCP)
 * and wires it into the AI assistant. The ability is propose-only (execute_callback =
 * SendOps::send_prepare); the real send runs via the confirm flow (SendOps::send_apply).
 * MCP exposure of this destructive ability is governed by CouponCore's existing gate.
 */
final class Module extends AbstractModule {

	private const ABILITY = 'moksafocou/send-coupon';

	public function slug(): string {
		return 'send';
	}

	public function label(): string {
		return __( 'Coupon delivery', 'moksafocou' );
	}

	public function category(): string {
		return 'coupon';
	}

	public function tagline(): string {
		return __( 'Send a coupon to a customer in natural language, optionally locked to that Email only', 'moksafocou' );
	}

	public function boot(): void {
		if ( function_exists( 'wp_register_ability' ) ) {
			add_action( 'wp_abilities_api_init', array( self::class, 'register_ability' ) );
		}
		add_filter( 'moksafocou_ai_assistant_abilities', array( self::class, 'ai_abilities' ) );
		add_filter( 'moksafocou_ai_destructive_handlers', array( self::class, 'ai_handlers' ) );
	}

	public static function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'Send coupon', 'moksafocou' ),
				'description'         => __( 'Send an existing coupon to a specified Email (optionally locked to that Email only). Destructive — the call only "proposes"; it is sent only after the user confirms.', 'moksafocou' ),
				'category'            => 'moksafocou',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'code_or_id'        => array(
							'type'        => 'string',
							'description' => __( 'Coupon code or ID to send', 'moksafocou' ),
						),
						'email'             => array(
							'type'        => 'string',
							'description' => __( 'Recipient Email', 'moksafocou' ),
						),
						'note'              => array(
							'type'        => 'string',
							'description' => __( 'Message to include for the customer (optional)', 'moksafocou' ),
						),
						'restrict_to_email' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to lock this coupon to that Email only (writes WooCommerce\'s Email restriction)', 'moksafocou' ),
						),
					),
					'required'             => array( 'code_or_id', 'email' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array( 'summary' => array( 'type' => 'string' ) ),
				),
				'execute_callback'    => array( SendOps::class, 'send_prepare' ),
				'permission_callback' => array( self::class, 'can_send' ),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	public static function can_send(): bool {
		return current_user_can( SendOps::CAP );
	}

	/**
	 * @param array<int,string> $abilities
	 * @return array<int,string>
	 */
	public static function ai_abilities( array $abilities ): array {
		$abilities[] = self::ABILITY;
		return $abilities;
	}

	/**
	 * @param array<string,array{prepare:callable,apply:callable}> $handlers
	 * @return array<string,array{prepare:callable,apply:callable}>
	 */
	public static function ai_handlers( array $handlers ): array {
		$handlers[ self::ABILITY ] = array(
			'prepare' => array( SendOps::class, 'send_prepare' ),
			'apply'   => array( SendOps::class, 'send_apply' ),
		);
		return $handlers;
	}
}
