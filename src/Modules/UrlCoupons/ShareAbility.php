<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\UrlCoupons;

use Moksafocou\Coupon\CouponService;

defined( 'ABSPATH' ) || exit;

/**
 * Read ability moksafocou/get-coupon-share: returns a coupon's shareable auto-apply
 * URL plus a qr_url pointer (the gated REST SVG route), so the in-dashboard AI
 * assistant and any external MCP client can hand back a scannable link. Inline SVG
 * is deliberately NOT returned here — a multi-KB string would bloat model context
 * on every call; qr_url is a cheap pointer the consumer fetches if it needs the image.
 */
final class ShareAbility {

	public const CATEGORY = 'moksafocou';
	public const CAP      = 'manage_woocommerce';

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			'moksafocou/get-coupon-share',
			array(
				'label'               => __( 'Get coupon share link / QR', 'moksafocou' ),
				'description'         => __( 'Get the "auto-apply" share URL and QR code link for a coupon (customers apply the coupon automatically by clicking or scanning). If the coupon has not enabled the URL feature, it returns enabled=false with a hint. Read-only.', 'moksafocou' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'code_or_id' => array(
							'type'        => 'string',
							'description' => __( 'Coupon code or ID', 'moksafocou' ),
						),
					),
					'required'             => array( 'code_or_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'enabled'   => array( 'type' => 'boolean' ),
						'code'      => array( 'type' => 'string' ),
						'share_url' => array( 'type' => 'string' ),
						'qr_url'    => array( 'type' => 'string' ),
						'reason'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	public static function can_read(): bool {
		return current_user_can( self::CAP );
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute( $input ): array {
		if ( ! self::can_read() ) {
			return array(
				'enabled'   => false,
				'share_url' => '',
				'qr_url'    => '',
				'reason'    => __( 'You do not have permission to do this.', 'moksafocou' ),
			);
		}
		$ref = is_array( $input ) && isset( $input['code_or_id'] ) ? (string) $input['code_or_id'] : '';
		$id  = CouponService::resolve_id( $ref );
		if ( ! $id ) {
			return array(
				'enabled'   => false,
				'share_url' => '',
				'qr_url'    => '',
				'reason'    => __( 'Coupon not found.', 'moksafocou' ),
			);
		}
		$coupon = new \WC_Coupon( $id );
		$info   = ShareService::info( $coupon );
		return array(
			'enabled'   => $info['enabled'],
			'code'      => $coupon->get_code(),
			'share_url' => $info['share_url'],
			'qr_url'    => $info['enabled'] ? ShareService::qr_url( $id ) : '',
			'reason'    => $info['reason'],
		);
	}
}
