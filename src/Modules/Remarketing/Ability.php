<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Remarketing;

use Moksafocou\Coupon\CouponService;
use Moksafocou\Support\AbilityMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the post-purchase remarketing rule to the Abilities API / AI assistant / MCP: read the
 * current configuration, or set it in one call (validating the template coupon exists). Honours the
 * "every coupon action is an ability" promise for the marketing-automation layer.
 */
final class Ability {

	private const CAP      = 'manage_woocommerce';
	private const CATEGORY = 'moksafocou';

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'moksafocou/get-remarketing-config',
			array(
				'label'               => __( 'Get remarketing settings', 'moksafocou' ),
				'description'         => __( 'Read the current "automatically issue a coupon after order completion" settings: template coupon, issuance conditions, amount threshold, validity days, and whether to send an email. Read-only.', 'moksafocou' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilityMeta::empty_input(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'enabled'     => array( 'type' => 'boolean' ),
						'source'      => array( 'type' => 'string' ),
						'condition'   => array( 'type' => 'string' ),
						'min_total'   => array( 'type' => 'number' ),
						'expiry_days' => array( 'type' => 'integer' ),
						'email'       => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( self::class, 'get_config' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'meta'                => AbilityMeta::read(),
			)
		);

		wp_register_ability(
			'moksafocou/set-remarketing-config',
			array(
				'label'               => __( 'Set remarketing rules', 'moksafocou' ),
				'description'         => __( 'Set "automatically issue a coupon after order completion": template coupon code, issuance conditions (all / first_order / min_total), amount threshold, validity days, and whether to send an email. Validates that the template coupon exists.', 'moksafocou' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'enabled'     => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to enable automatic coupon issuance', 'moksafocou' ),
						),
						'source'      => array(
							'type'        => 'string',
							'description' => __( 'Template coupon code (will be copied into a customer-exclusive coupon)', 'moksafocou' ),
						),
						'condition'   => array(
							'type'        => 'string',
							'enum'        => Rules::CONDITIONS,
							'description' => __( 'Issuance conditions', 'moksafocou' ),
						),
						'min_total'   => array(
							'type'        => 'number',
							'description' => __( 'Threshold amount when the condition is min_total', 'moksafocou' ),
						),
						'expiry_days' => array(
							'type'        => 'integer',
							'description' => __( 'Win-back coupon validity in days (0 = use template expiry)', 'moksafocou' ),
						),
						'email'       => array(
							'type'        => 'boolean',
							'description' => __( 'Also notify the customer by email', 'moksafocou' ),
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => AbilityMeta::summary_output(),
				'execute_callback'    => array( self::class, 'set_config' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'meta'                => AbilityMeta::write(),
			)
		);
	}

	public static function can_manage(): bool {
		return current_user_can( self::CAP );
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function get_config( $input ): array {
		if ( ! self::can_manage() ) {
			return array( 'enabled' => false );
		}
		return array(
			'enabled'     => 'yes' === get_option( 'moksafocou_remarketing_enabled', 'no' ),
			'source'      => (string) get_option( 'moksafocou_remarketing_source', '' ),
			'condition'   => Rules::normalize_condition( (string) get_option( 'moksafocou_remarketing_condition', 'all' ) ),
			'min_total'   => (float) get_option( 'moksafocou_remarketing_min_total', 0 ),
			'expiry_days' => (int) get_option( 'moksafocou_remarketing_expiry_days', 30 ),
			'email'       => 'yes' === get_option( 'moksafocou_remarketing_email', 'no' ),
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function set_config( $input ) {
		if ( ! self::can_manage() ) {
			return new \WP_Error( 'moksafocou_forbidden', __( 'You do not have permission to do this.', 'moksafocou' ) );
		}
		$input = is_array( $input ) ? $input : array();

		if ( isset( $input['source'] ) ) {
			$src = trim( (string) $input['source'] );
			if ( '' !== $src && ! CouponService::resolve_id( $src ) ) {
				return new \WP_Error( 'moksafocou_not_found', __( 'The specified template coupon code was not found.', 'moksafocou' ) );
			}
			update_option( 'moksafocou_remarketing_source', $src );
		}
		if ( isset( $input['enabled'] ) ) {
			update_option( 'moksafocou_remarketing_enabled', $input['enabled'] ? 'yes' : 'no' );
		}
		if ( isset( $input['condition'] ) ) {
			update_option( 'moksafocou_remarketing_condition', Rules::normalize_condition( (string) $input['condition'] ) );
		}
		if ( isset( $input['min_total'] ) ) {
			update_option( 'moksafocou_remarketing_min_total', (string) (float) $input['min_total'] );
		}
		if ( isset( $input['expiry_days'] ) ) {
			update_option( 'moksafocou_remarketing_expiry_days', (string) max( 0, (int) $input['expiry_days'] ) );
		}
		if ( isset( $input['email'] ) ) {
			update_option( 'moksafocou_remarketing_email', $input['email'] ? 'yes' : 'no' );
		}

		return array( 'summary' => __( 'Remarketing settings updated.', 'moksafocou' ) );
	}
}
