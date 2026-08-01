<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\AiAssistant;

use Moksafocou\Coupon\CouponService;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon AI REST: /ai-chat (conversation → Agent) and /ai-confirm (execute a
 * destructive action after explicit human confirmation). The confirm endpoint
 * requires a higher capability and a one-time, user-bound token.
 */
final class Rest {

	public const REST_NAMESPACE = 'moksafocou/v1';
	public const CONFIRM_PREFIX = 'moksafocou_ai_confirm_';

	public static function register(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/ai-chat',
			[
				'methods'             => 'POST',
				'permission_callback' => static function (): bool {
					return current_user_can( Config::CAP );
				},
				'args'                => [
					'message' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'history' => [
						'type'     => 'array',
						'required' => false,
						'default'  => [],
					],
				],
				'callback'            => [ self::class, 'chat' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai-confirm',
			[
				'methods'             => 'POST',
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => [
					'token' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
				'callback'            => [ self::class, 'confirm' ],
			]
		);
	}

	public static function chat( \WP_REST_Request $request ): \WP_REST_Response {
		$message = trim( (string) $request->get_param( 'message' ) );
		if ( '' === $message ) {
			return rest_ensure_response( [ 'reply' => '' ] );
		}

		$history = self::sanitize_history( $request->get_param( 'history' ) );

		// Deterministically resolve Taiwan "N 折" phrases before the AI sees them.
		$message .= CouponService::zhe_to_percent_hint( $message );

		$result = Agent::run( $message, Config::abilities(), Config::system_instruction(), $history );

		$type = $result['type'] ?? 'text';
		if ( 'confirm' === $type ) {
			return rest_ensure_response(
				[
					'confirm' => [
						'token'   => (string) ( $result['token'] ?? '' ),
						'summary' => (string) ( $result['summary'] ?? '' ),
						'fields'  => isset( $result['fields'] ) && is_array( $result['fields'] ) ? array_values( $result['fields'] ) : [],
					],
				]
			);
		}
		if ( 'error' === $type ) {
			return rest_ensure_response( [ 'error' => (string) ( $result['message'] ?? '' ) ] );
		}
		return rest_ensure_response( [ 'reply' => (string) ( $result['reply'] ?? '' ) ] );
	}

	/**
	 * @param mixed $raw
	 * @return array<int,array{role:string,text:string}>
	 */
	private static function sanitize_history( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}
			$role = ( isset( $turn['role'] ) && 'assistant' === $turn['role'] ) ? 'assistant' : 'user';
			$text = isset( $turn['text'] ) ? trim( sanitize_textarea_field( (string) $turn['text'] ) ) : '';
			if ( '' === $text ) {
				continue;
			}
			if ( mb_strlen( $text ) > 2000 ) {
				$text = mb_substr( $text, 0, 2000 );
			}
			$out[] = [
				'role' => $role,
				'text' => $text,
			];
		}
		return array_slice( $out, -10 );
	}

	public static function confirm( \WP_REST_Request $request ): \WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		$key   = self::CONFIRM_PREFIX . $token;
		$data  = get_transient( $key );

		if ( ! is_array( $data ) ) {
			return rest_ensure_response( [ 'error' => __( 'Confirmation has expired or does not exist, please try again.', 'moksafocou' ) ] );
		}
		if ( (int) ( $data['user'] ?? 0 ) !== get_current_user_id() ) {
			return rest_ensure_response( [ 'error' => __( 'Insufficient permissions, cannot run.', 'moksafocou' ) ] );
		}

		delete_transient( $key );

		$ability  = (string) ( $data['ability'] ?? '' );
		$handlers = Config::destructive_handlers();
		if ( ! isset( $handlers[ $ability ]['apply'] ) || ! is_callable( $handlers[ $ability ]['apply'] ) ) {
			return rest_ensure_response( [ 'error' => __( 'Unsupported operation.', 'moksafocou' ) ] );
		}

		$result = call_user_func( $handlers[ $ability ]['apply'], (array) ( $data['params'] ?? [] ) );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [ 'error' => $result->get_error_message() ] );
		}
		if ( is_array( $result ) ) {
			return rest_ensure_response( [ 'reply' => (string) ( $result['reply'] ?? '' ) ] );
		}
		return rest_ensure_response( [ 'reply' => (string) $result ] );
	}
}
