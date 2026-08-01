<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\AiAssistant;

use Moksafocou\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * In-dashboard coupon AI assistant. Uses the WordPress 7.0 AI Client
 * (wp_ai_client_prompt + using_abilities) to let merchants create / query coupons
 * in natural language. Boots only when the 'ai' module option is enabled (the
 * registry gate) and the AI Client is present.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'ai';
	}

	public function label(): string {
		return __( 'Moksa Coupon AI — create / look up coupons in one sentence', 'moksafocou' );
	}

	public function category(): string {
		return 'ai';
	}

	public function name(): string {
		return Config::NAME;
	}

	public function tagline(): string {
		return __( 'Requires WordPress 7.0 and an AI key set under Settings → Connectors', 'moksafocou' );
	}

	public function boot(): void {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}

		// 對話窗本體已移到共用層（lib/moksa-ai），全站只跑一份。這裡不再自己註冊 REST
		// 與 enqueue，改成把本外掛的能力橋接進共用層的中立 filter。
		// 子模組（CouponCore / Bogo …）維持掛在 moksafocou_ai_* 上，由 Config 彙整後送出。
		add_filter( 'moksa_ai_abilities', [ self::class, 'share_abilities' ] );
		add_filter( 'moksa_ai_destructive_handlers', [ self::class, 'share_destructive_handlers' ] );
		add_filter( 'moksa_ai_system_instruction', [ self::class, 'share_system_instruction' ] );

		// Platform host: this is the suite's one AI chat. Widen its tool list with the READ
		// abilities of sibling plugins (points / member / group-buy) so it can answer "顧客有幾點 /
		// 什麼會員等級 / KOL 分潤多少" — read-only (sibling writes keep their own confirm flow).
		add_filter( 'moksafocou_ai_assistant_abilities', [ self::class, 'add_platform_abilities' ] );
		add_filter( 'moksafocou_ai_system_instruction', [ self::class, 'add_platform_prompt' ], 9 );
	}

	/**
	 * @param string[] $abilities
	 * @return string[]
	 */
	public static function share_abilities( array $abilities ): array {
		return array_merge( $abilities, Config::abilities() );
	}

	/**
	 * @param array<string, array{prepare:callable, apply:callable}> $handlers
	 * @return array<string, array{prepare:callable, apply:callable}>
	 */
	public static function share_destructive_handlers( array $handlers ): array {
		return array_merge( $handlers, Config::destructive_handlers() );
	}

	public static function share_system_instruction( string $base ): string {
		return trim( $base ) . "\n\n" . Config::system_instruction();
	}

	/**
	 * Append sibling plugins' public read abilities to the chat tool list (zero hard dep —
	 * just whatever is registered in the Abilities API under the platform namespaces).
	 *
	 * @param array<int,string> $names
	 * @return array<int,string>
	 */
	public static function add_platform_abilities( array $names ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $names;
		}
		$prefixes = (array) apply_filters( 'moksafocou_ai_platform_namespaces', [ 'points/', 'member/', 'groupbuy/' ] );
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$n        = (string) $ability->get_name();
			$platform = false;
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $n, (string) $prefix ) ) {
					$platform = true;
					break;
				}
			}
			if ( ! $platform ) {
				continue;
			}
			$meta = (array) $ability->get_meta();
			$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : [];
			if ( array_key_exists( 'public', $mcp ) && ! $mcp['public'] ) {
				continue;
			}
			$ann = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : [];
			if ( ! empty( $ann['destructive'] ) ) {
				continue; // read-only across siblings — no prepare/apply handler here.
			}
			$names[] = $n;
		}
		return array_values( array_unique( $names ) );
	}

	public static function add_platform_prompt( string $prompt ): string {
		return $prompt . ' ' . __( 'Besides coupons, you can also look up a customer\'s points balance, membership tier and benefits, and group-buy / KOL commissions (use the available tools to query; these are read-only). Call the corresponding tool directly when needed.', 'moksafocou' );
	}

	/**
	 * The floating chat is an admin-wide widget (like a support bubble), so it has
	 * no single screen gate; it is gated by capability (least privilege).
	 */
	public static function enqueue_chat(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		$rel  = 'src/Modules/AiAssistant/assets/js/floating-chat.js';
		$path = MOKSAFOCOU_PLUGIN_DIR . $rel;
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOCOU_VERSION;
		wp_enqueue_script(
			'moksafocou-ai-chat',
			MOKSAFOCOU_PLUGIN_URL . $rel,
			[ 'wp-api-fetch' ],
			$ver,
			true
		);

		$greeting = (string) get_option(
			'moksafocou_ai_greeting',
			__( 'Hi, I\'m the Moksa assistant — I can help with coupons, points, membership tiers, and group-buy commissions. Try: "Create a 20% off coupon SUMMER20 that expires on 8/31" or "List active coupons".', 'moksafocou' )
		);
		$ex_raw   = (string) get_option(
			'moksafocou_ai_examples',
			__( 'Create a 10% off coupon VIP10, list active coupons, mass-generate 50 SALE- coupons for 100 off', 'moksafocou' )
		);
		$examples = array_values( array_filter( array_map( 'trim', explode( ',', $ex_raw ) ) ) );

		wp_localize_script(
			'moksafocou-ai-chat',
			'moksafocouAi',
			[
				'name'        => Config::NAME,
				'userId'      => get_current_user_id(),
				'greeting'    => $greeting,
				'placeholder' => __( 'For example: create a 15% off coupon AUTUMN15', 'moksafocou' ),
				'examples'    => $examples,
				'sendLabel'   => __( 'Send', 'moksafocou' ),
				'closeLabel'  => __( 'Close', 'moksafocou' ),
				'diffLabel'   => __( 'View field contents', 'moksafocou' ),
				'thinking'    => __( 'Processing', 'moksafocou' ),
				'clearLabel'  => __( 'Clear', 'moksafocou' ),
				'errorPrefix' => __( 'An error occurred', 'moksafocou' ),
				'emptyReply'  => __( '(No reply)', 'moksafocou' ),
				'confirmYes'  => __( 'Confirm', 'moksafocou' ),
				'confirmNo'   => __( 'Cancel', 'moksafocou' ),
				'cancelled'   => __( 'Cancelled.', 'moksafocou' ),
				'running'     => __( 'Running…', 'moksafocou' ),
			]
		);
	}
}
