<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponCore;

use Moksafocou\Coupon\CouponService;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin REST controller for direct, authenticated coupon CRUD. This is the
 * "programmatic integration" surface. Writes go through CouponOps prepare→apply
 * in a single call (the caller is an authenticated admin, so no human-confirm
 * gate is needed — that gate is specific to the AI / MCP path).
 */
final class CouponRest {

	private const NS = 'moksafocou/v1';

	public static function register(): void {
		register_rest_route(
			self::NS,
			'/coupons',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'list' ],
					'permission_callback' => [ self::class, 'can' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'create' ],
					'permission_callback' => [ self::class, 'can' ],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/coupons/(?P<ref>[^/]+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get' ],
					'permission_callback' => [ self::class, 'can' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ self::class, 'update' ],
					'permission_callback' => [ self::class, 'can' ],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'delete' ],
					'permission_callback' => [ self::class, 'can' ],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/coupons/(?P<ref>[^/]+)/toggle',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'toggle' ],
				'permission_callback' => [ self::class, 'can' ],
			]
		);
	}

	public static function can(): bool {
		return current_user_can( Ability::CAP );
	}

	public static function list( \WP_REST_Request $request ): \WP_REST_Response {
		$args = [
			'status'        => $request->get_param( 'status' ),
			'discount_type' => $request->get_param( 'discount_type' ),
			'search'        => $request->get_param( 'search' ),
			'limit'         => $request->get_param( 'limit' ),
		];
		return new \WP_REST_Response( CouponService::list( array_filter( $args, static fn( $v ) => null !== $v ) ), 200 );
	}

	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		$data = CouponService::get( (string) $request['ref'] );
		if ( null === $data ) {
			return new \WP_REST_Response( [ 'message' => __( 'Coupon not found.', 'moksa-coupons-for-woocommerce' ) ], 404 );
		}
		return new \WP_REST_Response( $data, 200 );
	}

	public static function create( \WP_REST_Request $request ): \WP_REST_Response {
		$prepared = CouponOps::create_prepare( (array) $request->get_json_params() );
		if ( $prepared instanceof \WP_Error ) {
			return self::error( $prepared );
		}
		$result = CouponOps::create_apply( $prepared );
		if ( $result instanceof \WP_Error ) {
			return self::error( $result );
		}
		return new \WP_REST_Response( $result, 201 );
	}

	/**
	 * Partial update of an existing coupon (PUT/PATCH/POST). Reuses the same validation +
	 * write path as the AI update-coupon ability, so an integration can change fields without
	 * the delete-and-recreate dance that would lose the coupon's id, usage count and history.
	 * `code` is immutable here (update_prepare drops it).
	 */
	public static function update( \WP_REST_Request $request ): \WP_REST_Response {
		$input               = (array) $request->get_json_params();
		$input['code_or_id'] = (string) $request['ref'];
		$prepared            = CouponOps::update_prepare( $input );
		if ( $prepared instanceof \WP_Error ) {
			return self::error( $prepared );
		}
		$result = CouponOps::update_apply( $prepared );
		return $result instanceof \WP_Error ? self::error( $result ) : new \WP_REST_Response( $result, 200 );
	}

	public static function toggle( \WP_REST_Request $request ): \WP_REST_Response {
		$prepared = CouponOps::toggle_prepare(
			[
				'code_or_id' => (string) $request['ref'],
				'enable'     => (bool) $request->get_param( 'enable' ),
			]
		);
		if ( $prepared instanceof \WP_Error ) {
			return self::error( $prepared );
		}
		$result = CouponOps::toggle_apply( $prepared );
		return $result instanceof \WP_Error ? self::error( $result ) : new \WP_REST_Response( $result, 200 );
	}

	public static function delete( \WP_REST_Request $request ): \WP_REST_Response {
		$prepared = CouponOps::delete_prepare(
			[
				'code_or_id' => (string) $request['ref'],
				'force'      => (bool) $request->get_param( 'force' ),
			]
		);
		if ( $prepared instanceof \WP_Error ) {
			return self::error( $prepared );
		}
		$result = CouponOps::delete_apply( $prepared );
		return $result instanceof \WP_Error ? self::error( $result ) : new \WP_REST_Response( $result, 200 );
	}

	private static function error( \WP_Error $error ): \WP_REST_Response {
		$code = $error->get_error_code();
		$http = 'moksafocou_forbidden' === $code ? 403 : ( 'moksafocou_not_found' === $code ? 404 : 400 );
		return new \WP_REST_Response(
			[
				'code'    => $code,
				'message' => $error->get_error_message(),
			],
			$http
		);
	}
}
