<?php

declare( strict_types=1 );

namespace Moksafocou\Coupon\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the plugin's coupon post-meta keys. All use the
 * `_moksafocou_` prefix (leading underscore = protected meta, hidden from the
 * Custom Fields UI).
 */
final class Keys {

	public const SCHEDULE_ENABLED   = '_moksafocou_schedule_enabled';
	public const SCHEDULE_START     = '_moksafocou_schedule_start';
	public const SCHEDULE_END       = '_moksafocou_schedule_end';
	public const SCHEDULE_MSG_START = '_moksafocou_schedule_start_msg';
	public const SCHEDULE_MSG_END   = '_moksafocou_schedule_end_msg';

	public const ROLE_ENABLED = '_moksafocou_role_enabled';
	public const ROLE_TYPE    = '_moksafocou_role_type';
	public const ROLE_LIST    = '_moksafocou_roles';
	public const ROLE_MSG     = '_moksafocou_role_msg';

	public const MIN_SUBTOTAL          = '_moksafocou_min_subtotal';
	public const MIN_SUBTOTAL_INCL_TAX = '_moksafocou_min_subtotal_incl_tax';
	public const MIN_QTY               = '_moksafocou_min_qty';
	public const CART_MSG              = '_moksafocou_cart_msg';

	/*
	 * Customer-history conditions (logged-in customer's paid-order history; guests
	 * are treated as having no history). Enforced alongside schedule/role/cart.
	 */
	public const CUST_ENABLED    = '_moksafocou_customer_enabled';
	public const CUST_FIRST_ONLY = '_moksafocou_customer_first_only';
	public const CUST_MIN_ORDERS = '_moksafocou_customer_min_orders';
	public const CUST_MAX_ORDERS = '_moksafocou_customer_max_orders';
	public const CUST_MIN_SPENT  = '_moksafocou_customer_min_spent';
	public const CUST_MAX_SPENT  = '_moksafocou_customer_max_spent';
	public const CUST_MSG        = '_moksafocou_customer_msg';

	/*
	 * Product / category cart-presence conditions: gate validity on the cart actually
	 * containing the required products / categories ('any' = at least one, 'all' = every
	 * one). Distinct from WC core's product_ids (which only scopes WHICH items discount).
	 */
	public const REQ_PRODUCTS        = '_moksafocou_req_products';
	public const REQ_PRODUCTS_MODE   = '_moksafocou_req_products_mode';
	public const REQ_CATEGORIES      = '_moksafocou_req_categories';
	public const REQ_CATEGORIES_MODE = '_moksafocou_req_categories_mode';
	public const PRODUCT_MSG         = '_moksafocou_product_msg';
	public const EXCL_PRODUCTS       = '_moksafocou_excl_products';
	public const EXCL_CATEGORIES     = '_moksafocou_excl_categories';
	public const EXCL_MSG            = '_moksafocou_excl_msg';

	/*
	 * Shipping-region condition: gate validity on the cart's shipping destination country
	 * (allow = only these / disallow = all but these). Distinct from the ShippingOverride
	 * module (SHIP_MODE/SHIP_VALUE), which rewrites the shipping COST. Country codes are
	 * stored uppercase (ISO-3166-1 alpha-2) to match WC_Customer::get_shipping_country().
	 */
	public const SHIPREGION_ENABLED   = '_moksafocou_shipregion_enabled';
	public const SHIPREGION_MODE      = '_moksafocou_shipregion_mode';
	public const SHIPREGION_COUNTRIES = '_moksafocou_shipregion_countries';
	public const SHIPREGION_MSG       = '_moksafocou_shipregion_msg';

	/*
	 * Payment-method condition: restrict a coupon to / from chosen payment gateways. The
	 * gateway is only known at checkout, so this is enforced at checkout validation (classic
	 * + Store API), not at cart apply.
	 */
	public const PAYMENT_ENABLED = '_moksafocou_payment_enabled';
	public const PAYMENT_MODE    = '_moksafocou_payment_mode';
	public const PAYMENT_METHODS = '_moksafocou_payment_methods';
	public const PAYMENT_MSG     = '_moksafocou_payment_msg';

	/*
	 * Day-of-week / time-of-day window (site timezone). Allowed weekdays (0=Sun..6=Sat)
	 * and an optional HH:MM–HH:MM window (overnight windows allowed). Gates validity.
	 */
	public const DAYTIME_ENABLED = '_moksafocou_daytime_enabled';
	public const DAYTIME_DAYS    = '_moksafocou_daytime_days';
	public const DAYTIME_START   = '_moksafocou_daytime_start';
	public const DAYTIME_END     = '_moksafocou_daytime_end';
	public const DAYTIME_MSG     = '_moksafocou_daytime_msg';

	/*
	 * URL-coupon per-coupon meta. NOTE: the per-coupon meta key `_moksafocou_url_enabled`
	 * (leading underscore) is DISTINCT from the SITE option `moksafocou_url_enabled`
	 * (no underscore) that gates the whole module — do not confuse the two.
	 */
	public const URL_ENABLED         = '_moksafocou_url_enabled';
	public const URL_SLUG            = '_moksafocou_url_slug';
	public const URL_REDIRECT        = '_moksafocou_url_redirect';
	public const URL_SUCCESS_MSG     = '_moksafocou_url_success_msg';
	public const URL_REDIRECT_ORIGIN = '_moksafocou_url_redirect_to_origin';

	/*
	 * BOGO (Buy X Get Y) per-coupon config. Stored as flat scalars (not one blob) so
	 * they flow through all()/copyable()/uninstall like every other meta. Active only
	 * when the coupon's native discount_type = 'moksafocou_bogo'.
	 */
	public const BOGO_TRIGGER_PRODUCT_IDS  = '_moksafocou_bogo_trigger_product_ids';
	public const BOGO_TRIGGER_CATEGORY_IDS = '_moksafocou_bogo_trigger_category_ids';
	public const BOGO_TRIGGER_QTY          = '_moksafocou_bogo_trigger_qty';
	public const BOGO_REWARD_PRODUCT_IDS   = '_moksafocou_bogo_reward_product_ids';
	public const BOGO_REWARD_CATEGORY_IDS  = '_moksafocou_bogo_reward_category_ids';
	public const BOGO_REWARD_QTY           = '_moksafocou_bogo_reward_qty';
	public const BOGO_REWARD_MODE          = '_moksafocou_bogo_reward_mode';
	public const BOGO_REWARD_VALUE         = '_moksafocou_bogo_reward_value';
	public const BOGO_DEAL_MODE            = '_moksafocou_bogo_deal_mode';
	public const BOGO_REPEAT_LIMIT         = '_moksafocou_bogo_repeat_limit';
	public const BOGO_NOTICE_MSG           = '_moksafocou_bogo_notice_msg';

	/*
	 * Nth-item discount (第二件6折 / 第N件折扣) per-coupon config. A single pool of in-set
	 * items: every N items, the Nth (cheapest) is discounted. Active only when the coupon's
	 * native discount_type = 'moksafocou_nth_item'. group_by: cart (one pool) | product
	 * (each product its own pool). reward_value for percent is the DISCOUNT % (六折 = 40).
	 */
	public const NTH_PRODUCT_IDS  = '_moksafocou_nth_product_ids';
	public const NTH_CATEGORY_IDS = '_moksafocou_nth_category_ids';
	public const NTH_GROUP_BY     = '_moksafocou_nth_group_by';
	public const NTH_N            = '_moksafocou_nth_n';
	public const NTH_REWARD_MODE  = '_moksafocou_nth_reward_mode';
	public const NTH_REWARD_VALUE = '_moksafocou_nth_reward_value';
	public const NTH_DEAL_MODE    = '_moksafocou_nth_deal_mode';
	public const NTH_REPEAT_LIMIT = '_moksafocou_nth_repeat_limit';
	public const NTH_NOTICE_MSG   = '_moksafocou_nth_notice_msg';

	/*
	 * Mix & match bundle pricing (任選 N 件 $X / 任選 N 件 Y 折) per-coupon config. A pool of
	 * member items: every N units forms a bundle priced as a fixed total or an across-the-board
	 * percent. Active only when the coupon's native discount_type = 'moksafocou_mixmatch'.
	 */
	public const MIXMATCH_PRODUCT_IDS  = '_moksafocou_mixmatch_product_ids';
	public const MIXMATCH_CATEGORY_IDS = '_moksafocou_mixmatch_category_ids';
	public const MIXMATCH_QTY          = '_moksafocou_mixmatch_qty';
	public const MIXMATCH_PRICE_MODE   = '_moksafocou_mixmatch_price_mode';
	public const MIXMATCH_PRICE_VALUE  = '_moksafocou_mixmatch_price_value';
	public const MIXMATCH_DEAL_MODE    = '_moksafocou_mixmatch_deal_mode';
	public const MIXMATCH_REPEAT_LIMIT = '_moksafocou_mixmatch_repeat_limit';
	public const MIXMATCH_NOTICE_MSG   = '_moksafocou_mixmatch_notice_msg';

	/** Auto-apply flag ('yes'/absent). Canonical store; the id-cache option is derived. */
	public const AUTO_APPLY = '_moksafocou_auto_apply';

	/*
	 * Advanced rule builder: a free-form AND/OR condition tree (groups of rules), the
	 * Advanced-Coupons-style "match ALL/ANY of these groups" engine. Stored as a canonical
	 * JSON blob. Enforced IN ADDITION to the simple per-dimension conditions. Payment-method
	 * rules are deferred to checkout (the gateway is unknown at cart-apply time).
	 */
	public const RULES_ENABLED = '_moksafocou_rules_enabled';
	public const RULES         = '_moksafocou_rules';
	public const RULES_MSG     = '_moksafocou_rules_msg';

	/** Max total discount (currency) a percent coupon may give. Empty = uncapped. */
	public const DISCOUNT_CAP = '_moksafocou_discount_cap';

	/*
	 * Tiered discount: ONE percent coupon gives a different percent-off depending on which
	 * cart tier is met (e.g. <1000 → 9折/10% off, ≥1000 → 8折/20% off). Active only when the
	 * coupon's native discount_type = 'percent'. TIERS holds a JSON array of rows, each
	 * { min_subtotal, min_qty, percent } (both thresholds AND-ed; highest-percent qualifying
	 * tier wins). The discount can optionally be limited to specific products / categories.
	 */
	public const TIERS_ENABLED           = '_moksafocou_tiers_enabled';
	public const TIERS                   = '_moksafocou_tiers';
	public const TIERS_BASIS             = '_moksafocou_tiers_basis';
	public const TIERS_TARGET_MODE       = '_moksafocou_tiers_target_mode';
	public const TIERS_TARGET_PRODUCTS   = '_moksafocou_tiers_target_products';
	public const TIERS_TARGET_CATEGORIES = '_moksafocou_tiers_target_categories';

	/*
	 * Free-gift / Add-product: when the coupon is applied, auto-add this product to
	 * the cart at the configured price (free / percent / fixed). Single gift per coupon.
	 */
	public const GIFT_ENABLED    = '_moksafocou_gift_enabled';
	public const GIFT_PRODUCT_ID = '_moksafocou_gift_product_id';
	public const GIFT_QTY        = '_moksafocou_gift_qty';
	public const GIFT_MODE       = '_moksafocou_gift_mode';
	public const GIFT_VALUE      = '_moksafocou_gift_value';

	/*
	 * Stacking control: govern whether this coupon may be combined with OTHER coupons.
	 * Allow / disallow lists store normalized (lowercased, comma-separated) coupon codes
	 * so they stay AI/REST friendly and match wc_format_coupon_code at compare time.
	 */
	public const STACK_EXCLUDE    = '_moksafocou_stack_exclude';
	public const STACK_ALLOWED    = '_moksafocou_stack_allowed';
	public const STACK_DISALLOWED = '_moksafocou_stack_disallowed';
	public const STACK_MSG        = '_moksafocou_stack_msg';

	/*
	 * Shipping override: when the coupon is applied, rewrite every shipping rate's cost
	 * (free / percent off / fixed off). Distinct from WC core's free_shipping flag, which
	 * only enables the dedicated "Free shipping" method.
	 */
	public const SHIP_MODE  = '_moksafocou_ship_mode';
	public const SHIP_VALUE = '_moksafocou_ship_value';

	/*
	 * Frontend display: opt a coupon into the public "[moksafocou_coupons]" list and
	 * give it a marketing label/description shown on the card.
	 */
	public const SHOW_IN_LIST = '_moksafocou_show_in_list';
	public const FRONT_LABEL  = '_moksafocou_front_label';

	/*
	 * Urgency display (coupon card): a live countdown to the coupon's deadline + a "N left"
	 * badge derived from the usage limit. Display-only — "limited stock" enforcement IS WC's
	 * native usage_limit; these only visualise time-left / remaining redemptions.
	 */
	public const COUNTDOWN_ENABLED = '_moksafocou_countdown_enabled';
	public const COUNTDOWN_SOURCE  = '_moksafocou_countdown_source';
	public const STOCK_SHOW        = '_moksafocou_stock_show';
	public const STOCK_THRESHOLD   = '_moksafocou_stock_threshold';

	/** Free-text campaign tag for grouping coupons into multi-stage activities. */
	public const CAMPAIGN = '_moksafocou_campaign';

	/** Cashback / loyalty reward config (post-order award, fires a hook for integrators). */
	public const CASHBACK_MODE  = '_moksafocou_cashback_mode';
	public const CASHBACK_VALUE = '_moksafocou_cashback_value';

	/**
	 * Keys that must NOT be copied when duplicating a coupon: a slug override is
	 * unique per coupon (two coupons cannot own the same /coupon/<slug>); a duplicate
	 * must NOT silently become a second auto-applying coupon. All BOGO keys ARE safe.
	 *
	 * @var array<int,string>
	 */
	public const COPY_EXCLUDE = [ self::URL_SLUG, self::AUTO_APPLY ];

	/** @return array<int,string> All plugin coupon-meta keys (for copy / cleanup). */
	public static function all(): array {
		return [
			self::SCHEDULE_ENABLED,
			self::SCHEDULE_START,
			self::SCHEDULE_END,
			self::SCHEDULE_MSG_START,
			self::SCHEDULE_MSG_END,
			self::ROLE_ENABLED,
			self::ROLE_TYPE,
			self::ROLE_LIST,
			self::ROLE_MSG,
			self::MIN_SUBTOTAL,
			self::MIN_SUBTOTAL_INCL_TAX,
			self::MIN_QTY,
			self::CART_MSG,
			self::CUST_ENABLED,
			self::CUST_FIRST_ONLY,
			self::CUST_MIN_ORDERS,
			self::CUST_MAX_ORDERS,
			self::CUST_MIN_SPENT,
			self::CUST_MAX_SPENT,
			self::CUST_MSG,
			self::REQ_PRODUCTS,
			self::REQ_PRODUCTS_MODE,
			self::REQ_CATEGORIES,
			self::REQ_CATEGORIES_MODE,
			self::PRODUCT_MSG,
			self::EXCL_PRODUCTS,
			self::EXCL_CATEGORIES,
			self::EXCL_MSG,
			self::SHIPREGION_ENABLED,
			self::SHIPREGION_MODE,
			self::SHIPREGION_COUNTRIES,
			self::SHIPREGION_MSG,
			self::PAYMENT_ENABLED,
			self::PAYMENT_MODE,
			self::PAYMENT_METHODS,
			self::PAYMENT_MSG,
			self::DAYTIME_ENABLED,
			self::DAYTIME_DAYS,
			self::DAYTIME_START,
			self::DAYTIME_END,
			self::DAYTIME_MSG,
			self::URL_ENABLED,
			self::URL_SLUG,
			self::URL_REDIRECT,
			self::URL_SUCCESS_MSG,
			self::URL_REDIRECT_ORIGIN,
			self::BOGO_TRIGGER_PRODUCT_IDS,
			self::BOGO_TRIGGER_CATEGORY_IDS,
			self::BOGO_TRIGGER_QTY,
			self::BOGO_REWARD_PRODUCT_IDS,
			self::BOGO_REWARD_CATEGORY_IDS,
			self::BOGO_REWARD_QTY,
			self::BOGO_REWARD_MODE,
			self::BOGO_REWARD_VALUE,
			self::BOGO_DEAL_MODE,
			self::BOGO_REPEAT_LIMIT,
			self::BOGO_NOTICE_MSG,
			self::NTH_PRODUCT_IDS,
			self::NTH_CATEGORY_IDS,
			self::NTH_GROUP_BY,
			self::NTH_N,
			self::NTH_REWARD_MODE,
			self::NTH_REWARD_VALUE,
			self::NTH_DEAL_MODE,
			self::NTH_REPEAT_LIMIT,
			self::NTH_NOTICE_MSG,
			self::MIXMATCH_PRODUCT_IDS,
			self::MIXMATCH_CATEGORY_IDS,
			self::MIXMATCH_QTY,
			self::MIXMATCH_PRICE_MODE,
			self::MIXMATCH_PRICE_VALUE,
			self::MIXMATCH_DEAL_MODE,
			self::MIXMATCH_REPEAT_LIMIT,
			self::MIXMATCH_NOTICE_MSG,
			self::AUTO_APPLY,
			self::RULES_ENABLED,
			self::RULES,
			self::RULES_MSG,
			self::DISCOUNT_CAP,
			self::TIERS_ENABLED,
			self::TIERS,
			self::TIERS_BASIS,
			self::TIERS_TARGET_MODE,
			self::TIERS_TARGET_PRODUCTS,
			self::TIERS_TARGET_CATEGORIES,
			self::GIFT_ENABLED,
			self::GIFT_PRODUCT_ID,
			self::GIFT_QTY,
			self::GIFT_MODE,
			self::GIFT_VALUE,
			self::STACK_EXCLUDE,
			self::STACK_ALLOWED,
			self::STACK_DISALLOWED,
			self::STACK_MSG,
			self::SHIP_MODE,
			self::SHIP_VALUE,
			self::SHOW_IN_LIST,
			self::FRONT_LABEL,
			self::COUNTDOWN_ENABLED,
			self::COUNTDOWN_SOURCE,
			self::STOCK_SHOW,
			self::STOCK_THRESHOLD,
			self::CAMPAIGN,
			self::CASHBACK_MODE,
			self::CASHBACK_VALUE,
		];
	}

	/**
	 * Keys that are safe to copy verbatim onto a duplicated coupon (everything in
	 * all() except the per-coupon-unique COPY_EXCLUDE keys).
	 *
	 * @return array<int,string>
	 */
	public static function copyable(): array {
		return array_values( array_diff( self::all(), self::COPY_EXCLUDE ) );
	}
}
