=== Moksa Coupons for WooCommerce ===
Contributors: moksa0923
Tags: coupons, woocommerce, bogo, cart conditions, ai
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
WC requires at least: 10.7
WC tested up to: 10.9
Stable tag: 1.0.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

AI-first WooCommerce coupon toolkit: BOGO, cart conditions, role limits, scheduling, URL coupons, auto-apply and one-click templates.

== Description ==

Moksa Coupons for WooCommerce is a free, modular coupon toolkit that brings the premium coupon features merchants usually pay for — BOGO, cart conditions, scheduling, URL coupons and more — to every WooCommerce store, and makes every coupon action available to the WordPress Abilities API, the in-dashboard AI assistant and external AI tools over MCP.

Every feature is a separate module that is **off by default**; turn on only what you need under the plugin's own "Moksa coupon → Settings" screen.

= Coupon features =

* **BOGO (Buy X Get Y)** — buy N of a product/category, get M free or discounted.
* **Nth-item discount** — every N of a chosen set (or the whole store) discounts the Nth item — free, percent or a fixed amount (e.g. half price on the 2nd item), once or repeating.
* **Mix & Match** — pick any N from a set for one fixed bundle total (any 3 for $299) or a percent off the whole set.
* **Cart conditions** — minimum subtotal / quantity, customer-history rules (first-order-only, min/max orders or spend), required/excluded products and categories.
* **Role restrictions** — limit a coupon to chosen user roles.
* **Scheduling** — start/end date-time and day-of-week / time-of-day windows, enforced at checkout.
* **Free gift** — auto-add a gift product to the cart when the coupon applies.
* **Shipping override** — make a coupon set free shipping or a percentage/fixed shipping discount.
* **Stacking control** — allow or block combining a coupon with other coupons.
* **Discount cap** — cap the maximum amount a percentage coupon can discount.
* **Auto-apply** — apply qualifying coupons automatically, no code needed.
* **URL coupons** — a pretty `/coupon/<code>` link (with server-side QR) and an optional `?coupon=` apply, that auto-applies on click.
* **Coupon templates** — 50+ ready-made presets (new customer, spend-and-save, free shipping, BOGO, Nth-item, seasonal sales…) you can apply, tweak and create in one click.
* **Reports** — per-coupon usage and total discount, cached hourly.
* **Front-end coupon wall** — a `[moksafocou_coupons]` shortcode that lists your advertised coupons as copy-to-clipboard cards.

= AI, Abilities and MCP (optional) =

Every coupon action is registered as a WordPress Ability, so the same capability is reachable from:

* the **in-dashboard AI assistant** — create or look up coupons by typing in plain language (uses the WordPress AI Client, which you configure with your own provider);
* the **WordPress Abilities API** and REST API;
* an optional **MCP server** that exposes coupon abilities to external AI tools.

These features are off by default and respect WordPress capabilities — every ability and REST route checks the current user's `manage_woocommerce` (or a coupon-specific) capability. Destructive abilities exposed over external MCP stay disabled unless you separately opt in, and even then run as a confirm-then-apply (propose/apply) flow.

= Off by default, enable what you need =

Every feature is a separate module and ships turned off. The AI assistant, Abilities and MCP integrations build on the WordPress Abilities API and AI Client included in WordPress 7.0 (this plugin's minimum), but stay inactive until you switch them on; the core coupon features need no AI configuration at all.

= Source =

Source code and issue tracker: [github.com/Moksa1123/moksa-coupons-for-woocommerce](https://github.com/Moksa1123/moksa-coupons-for-woocommerce).

== Installation ==

1. Upload the plugin to `/wp-content/plugins/moksafocou`, or install it from the Plugins screen.
2. Activate it. WooCommerce must be active.
3. Open **Moksa coupon → Settings** (or, until you enable the independent menu, **WooCommerce → Coupon settings**) and switch on the modules you want. Everything is off by default.

== Frequently Asked Questions ==

= Does it work without the AI features? =

Yes. All coupon features (BOGO, conditions, scheduling, URL coupons, templates, etc.) work on a standard WooCommerce store with no AI configuration. The AI assistant, Abilities and MCP are optional and off by default.

= Does the plugin send my data anywhere? =

No, not on its own. The plugin makes no outbound requests. The only way data leaves your site is if you enable the optional AI assistant — then the natural-language text you type is sent to the AI provider you configured in WordPress's AI Client. See "External services" below.

= Is it compatible with HPOS and block checkout? =

Yes. The plugin declares compatibility with WooCommerce High-Performance Order Storage and the cart/checkout blocks.

== External services ==

This plugin can connect to a third-party AI provider, but only when you, the site administrator, explicitly enable an optional feature, and only through infrastructure you configure yourself. The plugin bundles no AI SDK and ships with every AI/MCP feature turned OFF by default. The plugin makes no outbound HTTP requests of its own.

**AI coupon assistant (optional, off by default).** When you enable this feature, the natural-language text you type in the admin to create or query coupons — plus the coupon parameters needed to fulfil that request — is sent to the AI model provider you have configured in WordPress's AI Client (WordPress 7.0+). The plugin does not choose a provider, does not include or transmit any API keys of its own, and makes no AI request unless you enable the feature and configure a provider. Which provider receives the text, and the terms and privacy policy that apply, depend entirely on the AI Client connector you set up. Please review your chosen provider's terms of service and privacy policy.

**External MCP server (optional, off by default).** When you enable this feature, the plugin exposes coupon abilities to external AI tools over the Model Context Protocol. This is an inbound, authenticated endpoint: a request must come from a logged-in user with the `manage_woocommerce` capability. The plugin does not send your data to any external service through this feature; it only answers authorised incoming requests. Destructive abilities (create / update / delete coupons) remain disabled over external MCP unless you separately opt in.

== Screenshots ==

1. One-click coupon templates, grouped by goal with a category sidebar and a recently-used row.
2. Quick-configure a template — code, discount, usage limits, expiry, description — before creating the coupon.
3. Modular settings: switch on only the coupon features you need. Everything is off by default.
4. Every advanced coupon setting in one tabbed panel on the coupon editor (schedule, role, cart and customer conditions, BOGO, gift, shipping, stacking, URL, front-end).
5. Coupon management hub with at-a-glance counts, quick links and per-coupon reports.
6. Front-end coupon wall rendered by the [moksafocou_coupons] shortcode.

== Changelog ==

= 1.0.2 =
* Fix: the coupon-cards block title/description and the AI assistant's Clear / Close / Send / Confirm / Cancel controls now render in English (they could show Traditional Chinese regardless of site language). The block's text domain was also corrected so its strings localise properly.

= 1.0.1 =
* Bundle the Traditional Chinese (zh_TW) translation so the plugin is localized on install; community translations continue on translate.wordpress.org.

= 1.0.0 =
First public release. A free, modular, AI-first WooCommerce coupon toolkit — every feature is a separate module, off by default, enabled under "Moksa coupon → Settings".

Coupon types & discounts:
* BOGO (Buy X Get Y), Nth-item discount and Mix & Match set-price mechanics — at most one special-price coupon per cart, and their savings show transparently on the created order.
* Tiered discounts (ladder by subtotal / quantity / weight, percent or fixed, best-for-cart wins), a percentage discount cap, free gift, shipping override (free / percent / fixed), stacking control and auto-apply.

Conditions & rules:
* Cart conditions (minimum subtotal / quantity, customer-history, required / excluded products and categories), scheduling (date-time + day/time windows), role restrictions, shipping-region and payment-method conditions — all enforced at checkout, classic and block.
* A visual AND/OR advanced rule builder with 25 condition types.

Distribution & front-end:
* URL coupons with a pretty /coupon/<code> link and server-side QR plus an optional ?coupon= apply, 50+ one-click coupon templates, a [moksafocou_coupons] front-end coupon wall (shortcode + Gutenberg block) with eligibility hints and a live expiry countdown, a cart "you saved $X" savings summary and a free-shipping threshold nudge.

Management:
* An independent "Moksa coupon" admin menu with a dashboard, per-coupon / revenue / per-campaign reports, a consolidated tabbed coupon-settings metabox with type icons, a live editor summary, coupon-list status / bulk / duplicate tools, CSV import / export, a "My coupons" My Account page, post-purchase remarketing coupons and expiry-reminder emails.

AI, Abilities & MCP (optional, off by default):
* Every coupon action is a capability-checked WordPress Ability, reachable from an in-dashboard AI assistant (WordPress 7.0 AI Client), the REST API and an optional self-built MCP server. Destructive abilities stay disabled over external MCP unless separately opted in, and run as a propose / apply confirmation flow.

Platform integration:
* Companion-plugin hooks so a separate points / membership / group-buy plugin can own value, identity and attribution: a moksafocou_coupon_redeemed event on paid orders and a moksafocou_coupon_allowed_for_user filter (e.g. for VIP-gated coupons).

Quality:
* A single source of truth for coupon-type labels (no raw slugs in reports / emails), cached reports and a bounded customer-history lookup, first-run safe-default module seeding, a soft cross-module dependency advisory, a prefix-based uninstall cleanup, and a WordPress.org-clean build.

== Upgrade Notice ==

= 1.0.0 =
First public release.
