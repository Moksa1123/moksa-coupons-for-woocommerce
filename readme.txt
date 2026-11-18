=== Moksa Coupons for WooCommerce ===
Contributors: moksa0923
Tags: coupons, woocommerce, bogo, cart conditions, ai
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
WC requires at least: 10.7
WC tested up to: 11.3
Stable tag: 1.3.1
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
* an optional **MCP server** that exposes coupon abilities to external AI tools. It is served by the shared Moksa MCP endpoint (`/wp-json/moksa/v1/mcp`), which lists the tools of every Moksa plugin you have switched it on in; the older `/wp-json/moksafocou/v1/mcp` keeps working and lists coupon tools only.

These features are off by default and respect WordPress capabilities — every ability and REST route checks the current user's `manage_woocommerce` (or a coupon-specific) capability. Over external MCP, tools that change coupons are all off by default and are switched on one by one. Each needs its own capability (for example `delete_shop_coupons` to delete), and each runs in two steps: the change is first proposed with a summary, and only carried out when the AI tool confirms it with a one-time token (valid for 10 minutes, usable once). Recent changes made this way are listed on the settings screen.

Use a dedicated account with only the permissions it needs for the application password you give an AI tool. If the AI tool is set to approve tool calls automatically, switching on a change tool lets the AI confirm that change by itself — the site cannot tell the difference.

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

**External MCP server (optional, off by default).** When you enable this feature, the plugin exposes coupon abilities to external AI tools over the Model Context Protocol. This is an inbound, authenticated endpoint: a request must come from a logged-in user with the `manage_woocommerce` capability. The plugin does not send your data to any external service through this feature; it only answers authorised incoming requests. Tools that change coupons stay off over external MCP until you switch them on one by one. For each call the plugin keeps a short log on your own site (time, account, tool name, outcome and a fingerprint of the arguments — never the arguments themselves); nothing is sent anywhere.

== Screenshots ==

1. One-click coupon templates, grouped by goal with a category sidebar and a recently-used row.
2. Quick-configure a template — code, discount, usage limits, expiry, description — before creating the coupon.
3. Modular settings: switch on only the coupon features you need. Everything is off by default.
4. Every advanced coupon setting in one tabbed panel on the coupon editor (schedule, role, cart and customer conditions, BOGO, gift, shipping, stacking, URL, front-end).
5. Coupon management hub with at-a-glance counts, quick links and per-coupon reports.
6. Front-end coupon wall rendered by the [moksafocou_coupons] shortcode.

== Changelog ==

= 1.3.1 =
* Fix: the shared Moksa AI component no longer changes other plugins' abilities. It used to rewrite every ability's input and output schema on the site, which broke tools such as Novamira's execute-ability ("output[data] is not of type string"). Now only the input schema of Moksa's own abilities is adjusted for AI models.
* Fix: on sites with a persistent object cache (Redis/Memcached), one-time tasks (upgrade, coupon index build, rewrite-rule flush) no longer repeat on every request, and first activation no longer re-enables a module the owner had switched off.
* Compatibility: WordPress 7.2. Line-style admin menu icon; coupons are never picked by the new /random URL.
* Fix: the QR code preview on the coupon edit screen showed only part of the code.
* Fix: the coupon cards block settings in the editor are now translated.

= 1.3.0 =
* New: one MCP endpoint for the whole site, /wp-json/moksa/v1/mcp, shared by every Moksa plugin you have switched MCP on in. The old /wp-json/moksafocou/v1/mcp keeps working and lists coupon tools only.
* New: over MCP, tools that change coupons are switched on one by one in Settings → AI and external openness. Each needs its own permission (for example "delete coupons" to delete), and each runs in two steps: the change is proposed with a summary first, and only carried out when the AI tool confirms it with a one-time token (valid for 10 minutes, usable once). The old all-or-nothing "Allow external MCP to make changes" switch is gone; after updating, every change tool starts switched off.
* New: the settings screen lists the latest changes made over MCP (time, account, tool, result). Only a fingerprint of what was sent is kept, never the content.
* Fix: an automatically applied coupon is no longer forced into a cart that already holds an "individual use only" coupon, and an "individual use only" coupon is no longer applied automatically on top of a code the customer entered. The customer's own coupon is always kept.
* Fix: when a customer applies an "individual use only" coupon, the automatically applied coupon it removes no longer comes straight back.
* Fix: a coupon issued to one customer no longer shows up in the public coupon list, even when it was copied from a template marked "show in list".
* Improvement: looking up coupons (auto-apply, URL coupon codes, My coupons, expiry reminders, reports) now uses an index instead of searching every coupon, which is faster on stores with many coupons. The index is built in the background right after the update.
* Improvement: the shared QR code is written in a form that survives output escaping; it scans the same.
* Improvement: uninstalling removes this plugin's settings by name instead of a database pattern match.
* Removed: leftover code of the plugin's old built-in chat window, which the shared Moksa AI chat replaced earlier, and its two settings.
* Tested with WooCommerce 11.3.

= 1.2.1 =
* Auto-apply: a coupon the plugin added automatically is now taken back out of the cart once it can no longer apply - for example after it is set to draft, trashed or deleted. The block cart never ran WooCommerce's own clean-up, so such a coupon stayed in the cart as a NT$0 line until checkout refused it. Coupons the customer typed in are not touched.
* Tiered discounts: the "add NT$X more to upgrade" prompt and the tier shown on the coupon line ("(18% off)") can now be switched off separately, in both the block and the classic cart and checkout. Use the moksafocou_tiers_show_nudge filter for the prompt and moksafocou_tiers_label_current for the coupon line. In 1.2.0, turning the prompt off in the block cart also removed the tier from the coupon line.

= 1.2.0 =
* Tiered discounts now work in the block cart and checkout, which WooCommerce uses by default. The "add NT$X more to upgrade your discount" line and a progress bar appear under the totals; until now they were only shown on the classic cart and checkout, so on most stores they did not appear at all.
* Tiered discounts: the coupon line now names the tier the cart has reached, for example "Store-wide offer (18% off)" — or "82 折" on Traditional Chinese sites. A discount of NT$247 under a coupon advertised at 15% no longer leaves customers guessing that they had already moved up a tier. Works on both the block and the classic checkout.
* Tiered discounts: each tier can have an optional start and end date (site timezone, both inclusive). An opening-offer tier can end on 31 December and its replacement start on 1 January, and the ladder switches over at midnight with nothing to schedule. A tier outside its dates is neither applied nor shown as the next tier to reach. A tier whose end date is before its start date is kept as entered and a warning is shown, rather than being removed.
* Auto-apply: when the box is ticked on a coupon that cannot be applied automatically — one with a per-customer limit, an email restriction or an overall usage limit — the coupon screen now says which setting is in the way and why, and a notice appears on save. Previously the coupon simply never appeared in the cart.
* Tested with WooCommerce 11.1.

= 1.1.2 =
* Fix: on the WordPress plugin-update screen the AI assistant button appeared a second time inside the update progress frame. It is no longer loaded inside admin iframes.
* Fix: attributes in the AI assistant panel are now escaped as attributes, not only as text.
* Settings screens use a wider layout (up to 1500px).

= 1.1.1 =
* WordPress 7.1 compatibility: tested against 7.1. Read-only coupon tools now also declare the unified `public` flag introduced in 7.1, so AI clients and MCP adapters can discover them without channel-by-channel configuration. Tools that change or delete coupons are the other way round: whether an MCP client is offered them now follows the plugin's own "expose destructive abilities" setting, which is off by default — so a third-party MCP adapter cannot offer a destructive tool you have not asked for. Every tool is still gated by its own capability check.
* Fix: the coupon-cards block preview in the editor rendered without its stylesheet. WordPress 7.1 always renders the post editor inside an iframe, and the card styles are now declared on the block itself, so the preview matches the front end.
* Internal: the settings-screen presentation layer moved into a shared bundled library. No change to behaviour or to stored settings.

= 1.1.0 =
* Fix: the assistant could fail with a "Bad Request (400)" before it ran anything, so no question could be answered at all. Several coupon tools described a field as accepting more than one data type, which one of the supported AI providers rejects outright — and it rejects the whole set of tools, not just those. Every field now declares a single type.
* Fix: asking how many coupons there are could produce a made-up number. The list tool returns at most 50 rows, and the count it reported was the number of rows returned, which reads as a total. It now also reports the real total, and there is a dedicated counting tool for "how many" questions.
* New: a tool that reports how many coupons exist, broken down by enabled, disabled and expired.
* Advanced rule values may now arrive as a JSON string as well as a native value, so a rule written by an assistant is stored correctly instead of being silently saved as an unmatched single entry.

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
* Every coupon action is a capability-checked WordPress Ability, reachable from an in-dashboard AI assistant (WordPress 7.0 AI Client), the REST API and an optional self-built MCP server. Tools that change coupons are switched on one by one over external MCP, each with its own capability, and run as a propose / apply confirmation flow.

Platform integration:
* Companion-plugin hooks so a separate points / membership / group-buy plugin can own value, identity and attribution: a moksafocou_coupon_redeemed event on paid orders and a moksafocou_coupon_allowed_for_user filter (e.g. for VIP-gated coupons).

Quality:
* A single source of truth for coupon-type labels (no raw slugs in reports / emails), cached reports and a bounded customer-history lookup, first-run safe-default module seeding, a soft cross-module dependency advisory, a prefix-based uninstall cleanup, and a WordPress.org-clean build.

== Upgrade Notice ==

= 1.3.1 =
Fixes a conflict where the shared Moksa AI component changed other plugins' AI abilities, and repeated background tasks on sites with Redis or Memcached.

= 1.3.0 =
One MCP endpoint for all Moksa plugins. MCP tools that change coupons are now switched on one by one (all off after updating; re-enable under Settings → AI and external openness). Purge any page cache after updating.

= 1.2.1 =
Auto-applied coupons that have been drafted or deleted no longer linger in the block cart. The tier upgrade prompt and the tier on the coupon line can be switched off separately.

= 1.2.0 =
Tiered discounts now show their upgrade prompt and current tier in the block cart and checkout, and tiers can have start and end dates. If you added your own CSS or script to show the tier, remove it after updating or it will appear twice.

= 1.1.2 =
Small fixes: the AI assistant button no longer appears twice on the plugin-update screen, and settings screens use a wider layout.

= 1.1.1 =
Compatibility with WordPress 7.1, plus a fix for the coupon-cards block preview in the editor.

= 1.0.0 =
First public release.
