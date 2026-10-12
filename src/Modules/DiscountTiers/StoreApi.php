<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\DiscountTiers;

defined( 'ABSPATH' ) || exit;

/**
 * The block cart and checkout's view of tiered coupons.
 *
 * The block cart is WooCommerce's default and fires none of the classic totals hooks the nudge
 * was attached to, so on most shops the "spend NT$X more" row simply did not exist — em925 ended
 * up reading wc/store/cart themselves and drawing their own bar. And the Store API's coupon
 * object has no label at all, only a code, so the block checkout showed "−NT$247" against a
 * coupon name with no hint of which tier produced it.
 *
 * This puts {@see State} into the cart response under extensions.moksafocou, and registers the
 * script that renders it through WooCommerce Blocks' own extension points rather than by reaching
 * into the DOM, which the block cart re-renders whenever it likes.
 */
final class StoreApi {

	public const NAMESPACE = 'moksafocou';

	private static bool $nudge = true;
	private static bool $label = true;

	/**
	 * A switched-off part is left out of the response rather than hidden by the script, so a theme
	 * reading extensions.moksafocou sees the same choice the shop made.
	 *
	 * @param bool $nudge Send next (the "add NT$X more" row).
	 * @param bool $label Send label_suffix (the current tier on the coupon line).
	 */
	public static function boot( bool $nudge = true, bool $label = true ): void {
		self::$nudge = $nudge;
		self::$label = $label;

		// Modules boot on woocommerce_init, which runs AFTER woocommerce_blocks_loaded. Hooking that
		// event from here attached to something that had already happened: no error, no data, and
		// the first test against the live Store API came back without extensions.moksafocou at all.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_endpoint_data();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( self::class, 'register_endpoint_data' ) );
		}
		add_action( 'woocommerce_blocks_cart_block_registration', array( self::class, 'register_integration' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( self::class, 'register_integration' ) );
	}

	public static function register_endpoint_data(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'cart',
				'namespace'       => self::NAMESPACE,
				'data_callback'   => array( self::class, 'data' ),
				'schema_callback' => array( self::class, 'schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Registered from inside the hook, never at load time: the integration class implements a
	 * WooCommerce Blocks interface, and naming it on a site where Blocks is not loaded would be a
	 * fatal error rather than a missing feature.
	 *
	 * @param mixed $registry IntegrationRegistry.
	 */
	public static function register_integration( $registry ): void {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}
		if ( ! interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
			return;
		}
		// The cart and the checkout registries are separate, and both fire on a page that holds
		// both blocks; is_registered() keeps the second call from throwing.
		if ( method_exists( $registry, 'is_registered' ) && $registry->is_registered( BlocksIntegration::NAME ) ) {
			return;
		}
		$registry->register( new BlocksIntegration() );
	}

	/**
	 * @return array{tiers:array<int,array<string,mixed>>}
	 */
	public static function data(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return array( 'tiers' => array() );
		}

		$rows = array();
		foreach ( State::for_cart( WC()->cart ) as $code => $state ) {
			$current = $state['current'];
			$next    = $state['next'];

			$rows[] = array(
				'code'          => (string) $code,
				'basis'         => $state['basis'],
				'current_label' => null !== $current ? $current['label'] : '',
				// Sent ready to append, so the full-width brackets of a Chinese locale come from
				// the translation rather than from a hard-coded "(" in JavaScript.
				'label_suffix'  => self::$label && null !== $current
					? sprintf(
						/* translators: %s: the current tier, e.g. "82 折" or "18% off". */
						__( ' (%s)', 'moksa-coupons-for-woocommerce' ),
						$current['label']
					)
					: '',
				'next'          => self::$nudge && null !== $next
					? array(
						'label'    => $next['label'],
						'message'  => $next['message'],
						'gap_text' => $next['gap_text'],
						'progress' => $next['progress'],
					)
					: null,
			);
		}

		return array( 'tiers' => $rows );
	}

	/** @return array<string,mixed> */
	public static function schema(): array {
		return array(
			'tiers' => array(
				'description' => __( 'Where the cart stands on each applied tiered coupon.', 'moksa-coupons-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'code'          => array( 'type' => 'string' ),
						'basis'         => array( 'type' => 'string' ),
						'current_label' => array( 'type' => 'string' ),
						'label_suffix'  => array( 'type' => 'string' ),
						'next'          => array(
							'type'       => array( 'object', 'null' ),
							'properties' => array(
								'label'    => array( 'type' => 'string' ),
								'message'  => array( 'type' => 'string' ),
								'gap_text' => array( 'type' => 'string' ),
								'progress' => array( 'type' => 'number' ),
							),
						),
					),
				),
			),
		);
	}
}
