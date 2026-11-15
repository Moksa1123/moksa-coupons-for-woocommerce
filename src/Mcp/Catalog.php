<?php

declare( strict_types=1 );

namespace Moksafocou\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon MCP resources (read-only domain models a client can fetch once instead of calling tools
 * repeatedly) and prompts. Served through the shared Moksa MCP endpoint (see Provider); the shared
 * layer checks the provider capability before calling any of these.
 */
final class Catalog {

	/**
	 * Resource definitions.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function resources(): array {
		return array(
			array(
				'uri'         => 'moksafocou://rule-types',
				'name'        => __( 'Advanced rule types', 'moksa-coupons-for-woocommerce' ),
				'description' => __( '26 AND/OR advanced rule types, operators, and value shapes.', 'moksa-coupons-for-woocommerce' ),
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'moksafocou://settings-schema',
				'name'        => __( 'Coupon advanced settings schema', 'moksa-coupons-for-woocommerce' ),
				'description' => __( 'JSON schema for the moksafocou advanced settings object for create / update.', 'moksa-coupons-for-woocommerce' ),
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'moksafocou://templates',
				'name'        => __( 'Coupon template library', 'moksa-coupons-for-woocommerce' ),
				'description' => __( 'Built-in coupon templates (id / name / description / category / type).', 'moksa-coupons-for-woocommerce' ),
				'mimeType'    => 'application/json',
			),
		);
	}

	/**
	 * Read one resource.
	 *
	 * @param string $uri Resource uri.
	 * @return array<mixed>|null Payload, or null when the uri is unknown.
	 */
	public static function read( string $uri ): ?array {
		switch ( $uri ) {
			case 'moksafocou://rule-types':
				return \Moksafocou\Support\Rules::types();
			case 'moksafocou://settings-schema':
				return \Moksafocou\Coupon\Meta\CouponSettings::schema();
			case 'moksafocou://templates':
				$out = array();
				foreach ( \Moksafocou\Modules\Templates\Catalog::all() as $tpl ) {
					$out[] = array(
						'id'       => (string) ( $tpl['id'] ?? '' ),
						'label'    => (string) ( $tpl['label'] ?? '' ),
						'desc'     => (string) ( $tpl['desc'] ?? '' ),
						'category' => (string) ( $tpl['category'] ?? '' ),
						'type_key' => (string) ( $tpl['type_key'] ?? '' ),
					);
				}
				return $out;
			default:
				return null;
		}
	}

	/**
	 * Prompt definitions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function prompts(): array {
		return array(
			array(
				'name'        => 'create-coupon-from-idea',
				'description' => __( 'Guide coupon creation with a single marketing idea', 'moksa-coupons-for-woocommerce' ),
				'arguments'   => array(
					array(
						'name'        => 'idea',
						'description' => __( 'Marketing campaign idea, for example "Site-wide 10% off for the anniversary sale"', 'moksa-coupons-for-woocommerce' ),
						'required'    => true,
					),
				),
			),
			array(
				'name'        => 'campaign-ideas',
				'description' => __( 'Brainstorm coupon campaign ideas based on the store context', 'moksa-coupons-for-woocommerce' ),
				'arguments'   => array(
					array(
						'name'        => 'goal',
						'description' => __( 'Goal, for example raise average order value / acquire new customers', 'moksa-coupons-for-woocommerce' ),
						'required'    => false,
					),
				),
			),
		);
	}

	/**
	 * Build one prompt as plain text (the shared layer wraps it as a user message).
	 *
	 * @param string              $name Prompt name.
	 * @param array<string,mixed> $args Prompt arguments.
	 */
	public static function get_prompt( string $name, array $args ): ?string {
		if ( 'create-coupon-from-idea' === $name ) {
			$idea = isset( $args['idea'] ) ? (string) $args['idea'] : '';
			$text = sprintf(
				/* translators: %s: the marketing idea. */
				__( 'Please create a coupon with the moksafocou tools based on this marketing idea: "%s". First use find-coupon-by-code to confirm the code is not duplicated; when advanced conditions are needed, use list-rule-types / get-settings-schema to check the details, then call create-coupon or create-tiered-coupon.', 'moksa-coupons-for-woocommerce' ),
				$idea
			);
		} elseif ( 'campaign-ideas' === $name ) {
			$goal = isset( $args['goal'] ) ? (string) $args['goal'] : '';
			$text = '' !== $goal
				? sprintf(
					/* translators: %s: the campaign goal. */
					__( 'For the goal "%s", use list-templates to reference the built-in templates and propose 3 coupon campaign ideas suitable for this store, explaining the setup.', 'moksa-coupons-for-woocommerce' ),
					$goal
				)
				: __( 'Use list-templates to reference the built-in templates and propose 3 coupon campaign ideas suitable for this store, explaining the setup.', 'moksa-coupons-for-woocommerce' );
		} else {
			return null;
		}
		return $text;
	}
}
