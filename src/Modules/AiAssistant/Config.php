<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Shared config for the in-dashboard coupon AI assistant — ability whitelist,
 * system prompt and the destructive-action handler table. Deliberately
 * decoupled: the whitelist and handlers start empty and are populated by feature
 * modules (e.g. CouponCore) via the two filters below, so this module has no hard
 * dependency on any other module.
 */
final class Config {

	public const CAP  = 'manage_woocommerce';
	public const NAME = 'Moksa AI';

	/**
	 * Abilities exposed to the AI as tools. Destructive ones are intercepted by
	 * the Agent and routed through the human-confirm gate.
	 *
	 * @return array<int,string>
	 */
	public static function abilities(): array {
		return (array) apply_filters( 'moksafocou_ai_assistant_abilities', [] );
	}

	/**
	 * Destructive handler table: ability id => [ prepare, apply ]. Populated by
	 * feature modules through the filter.
	 *
	 * @return array<string,array{prepare:callable,apply:callable}>
	 */
	public static function destructive_handlers(): array {
		return (array) apply_filters( 'moksafocou_ai_destructive_handlers', [] );
	}

	/**
	 * @return array<int,string>
	 */
	public static function destructive_abilities(): array {
		return array_keys( self::destructive_handlers() );
	}

	public static function system_instruction(): string {
		$today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );

		$base = __( 'You are the "coupon assistant" for a WooCommerce merchant. Common tools: list-coupons to list, get-coupon for details, find-coupon-by-code to check whether a code is a duplicate, coupon-usage-summary for usage, get-coupon-report for performance reports (all read-only); create-coupon to create, update-coupon to update, toggle-coupon to enable or disable, delete-coupon to delete, bulk-generate-coupons for mass generation, extend-expiry to extend the expiry date, duplicate-coupon to duplicate, create-tiered-coupon to create a tiered coupon, apply-template to apply a template (all destructive). Destructive operations are only "proposed"; the system asks the user to click "Confirm" before they take effect, so you do not need to ask for confirmation again. When creating a coupon: the discount types are only percent (percentage), fixed_cart (fixed cart amount), and fixed_product (fixed product amount); the amount for percent is a percentage number and cannot exceed 100; use YYYY-MM-DD for the expiry date.', 'moksafocou' )
			. __( ' Advanced capabilities (all via the moksafocou settings object in create-coupon / update-coupon, or dedicated shortcuts): tiered discounts (tiers, or use create-tiered-coupon), maximum discount (discount_cap), auto-apply (auto_apply), mutual exclusion / no stacking (exclude_coupons), schedule start and end, user role, cart minimum, products / categories, shipping region, payment method, weekday and time window, Buy X Get Y, free gift, shipping override, and 26 types of AND/OR "advanced rules" (moksafocou.advanced_rules). When unsure which rule types exist or what the merchant\'s payment / shipping codes are, first use list-rule-types / get-settings-schema / list-payment-gateways / list-shipping-zones / list-countries to find out; when unsure about templates, use list-templates instead of guessing. Confirm with tools before creating (such as whether a code is a duplicate), and reply briefly and clearly; if something cannot be found or fails, say so plainly and do not make things up. Always end with text, do not stop at a tool call.', 'moksafocou' );

		// Reply-language directive: follow the user, not a hardcoded language. A merchant on an
		// English / Simplified site should not get Traditional-Chinese answers.
		$base .= ' ' . __( 'Always reply in the language the user asks in (use Traditional Chinese when the interface is in Traditional Chinese).', 'moksafocou' );

		// Date handling is language-neutral and always applies.
		$date_rule = sprintf(
			/* translators: %s: today's date in Y-m-d format. */
			__( 'Today\'s date is %s. When the user gives only a month / day or a relative date (such as "December 31" or "end of next month"), always resolve it to this year; only use next year if that date has already passed this year. Never use a past year.', 'moksafocou' ),
			$today
		);

		// The Taiwan "N 折" conversion maths only makes sense for Chinese-locale admins; it is
		// noise (and can derail answers) on non-Chinese sites, so gate it on the user locale.
		$zhe_rule = __( 'Taiwanese "zhe" discount conversion (always compute step by step with the formula, never by intuition): a "zhe" figure is the proportion of the price still paid after the discount, and the percent coupon\'s amount = 100 minus that payment percentage. For a single-digit "N-zhe", the payment percentage = N*10, so amount = 100 - N*10: 9-zhe -> pay 90 -> amount = 10; 8-zhe -> amount = 20; 7-zhe -> amount = 30; 5-zhe -> amount = 50. For a two-digit "NN-zhe", the payment percentage = NN, so amount = 100 - NN: 85-zhe -> amount = 15; 79-zhe -> amount = 21. Note in particular: the amount for "9-zhe" is always 10, not 9 and not 1. Only when the user states the discount directly ("N% off", "a discount of N%", "save N%") or a fixed amount ("N off") should you fill it in literally.', 'moksafocou' );

		$locale = function_exists( 'get_user_locale' ) ? (string) get_user_locale() : 'zh_TW';
		$prompt = ( 0 === strncmp( $locale, 'zh', 2 ) )
			? $base . $zhe_rule . $date_rule
			: $base . $date_rule;

		return (string) apply_filters( 'moksafocou_ai_system_instruction', $prompt );
	}
}
