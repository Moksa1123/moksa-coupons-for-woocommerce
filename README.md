# Moksa Coupons for WooCommerce (`moksafocou`)

An **AI-first, modular coupon toolkit** for WooCommerce. It brings the premium coupon
features merchants usually pay for — BOGO, cart conditions, tiered discounts, cashback,
URL coupons, auto-apply and a full marketing-automation loop — to every store, and makes
**every coupon action available to the WordPress Abilities API, the in-dashboard AI
assistant, and external AI tools over MCP**.

> This repository is the **clean WordPress.org install package** (no dev files, `vendor`
> is `--no-dev`). Drop the `moksafocou/` folder into `wp-content/plugins/` and activate.

- **Requires:** WordPress 7.0+ · PHP 8.2+ · WooCommerce 10.7+
- **Version:** 0.4.0 · **License:** GPLv3
- Every feature is a separate module, **off by default** — enable only what you need under
  *Moksa 優惠券 → 設定*.

---

## Features

### Discount mechanics
- **BOGO (Buy X Get Y)** — buy N of a product/category, get M free or discounted.
- **Tiered discounts** — one coupon, different reward by cart **subtotal / quantity / weight**;
  each tier can be a percentage **or** a fixed amount (mix both in one ladder).
- **Cashback / loyalty** — reward a percentage or fixed amount after the order is paid.
- **Discount cap** — limit the maximum amount a percentage coupon can discount.
- **Free gift**, **shipping override** (free / % / fixed), **stacking control**, **auto-apply**.

### Conditions & rules
- **Cart conditions** — min subtotal / quantity, customer history (first-order, min/max
  orders or spend), required / excluded products & categories, shipping region, payment method.
- **Visual AND/OR rule builder** — 26 condition types incl. cart weight, shipping zone/country,
  ordered product/category, custom taxonomy and custom user / cart-item meta.
- **Scheduling** — start/end date-time and day-of-week / time-of-day windows.
- **Role restrictions** · **URL coupons** (`/coupon/<code>` + server-side QR + `?coupon=` apply).

### Marketing automation (the full loop)
- **My Account coupons** — a "我的優惠券" tab listing each customer's personal coupons (copy / one-click apply).
- **Post-purchase remarketing** — on order completion, clone a chosen template coupon into a
  unique, customer-locked coupon (every-order / first-order / spend-threshold).
- **Store credit wallet** — turns cashback into a real balance, auto-applied at checkout, with
  **refund handling** (refunds return spent credit and claw back reversed cashback).
- **Gift cards** — sell store credit as a product; the amount lands in the recipient's wallet,
  or is emailed to them as a one-off gift coupon when they have no account.
- **Referral** — shareable links; reward referrer + friend on the friend's first order.
- **Birthday coupons** · **expiry-reminder emails** (daily WP-Cron) · **free-shipping nudge**
  ("再買 NT$X 免運") on cart & checkout.

### Reports & intelligence
- **Per-coupon report** — usage + total discount, plus a **revenue / daily-trend overview**.
- **Campaign report** — per-campaign orders / revenue / discount for coupons tagged with a campaign.
- **AI coupon advisor** — abilities that **suggest coupons from your own data** (lift average
  order value, move slow-selling stock, duplicate the best performer) and **audit** coupons
  (expiring-but-unused, expired-but-live, over-discounting).

### Merchandising & ops
- **Front-end coupon wall** shortcode + Gutenberg block · **one-click templates** (40+)
- **CSV import / export**, coupon-list bulk tools, a live editor summary, and a clean settings screen.

### AI, Abilities & MCP (optional, off by default)
Every coupon action is registered as a **WordPress Ability** (34 in total), reachable from:
- the **in-dashboard AI assistant** (WordPress 7.0 AI Client — bring your own provider);
- the **WordPress Abilities API** & REST;
- an optional **MCP server** for external AI tools.

All AI/MCP features are capability-checked (`manage_woocommerce`). External MCP exposes only
the **read-only** tools by default; destructive abilities (create / update / delete) stay hidden
behind a separate opt-in and run as a **propose → apply** confirmation flow.

---

## Installation

1. Download the latest release zip (or clone this repo) and copy the `moksafocou/` folder to
   `wp-content/plugins/`.
2. Activate it (WooCommerce must be active).
3. Open **Moksa 優惠券 → 設定** and switch on the modules you want. Everything is off by default.

No build step is required — the package ships ready to run.

---

## Architecture (for contributors)

- **PSR-4** under `src/` (`Moksafocou\`), modular: each feature is a
  `src/Modules/<Name>/Module.php` lazy-loaded only when its `moksafocou_<slug>_enabled`
  option is `yes` (so a disabled module registers no hooks).
- Shared foundations live in `src/Support/` (e.g. `CouponType`, `PersonalCoupon`, `OrderOnce`,
  `Cron`, `AbilityMeta`) and `src/Admin/`.
- No production Composer dependencies — `vendor/` here is only Composer's `--no-dev` autoloader,
  and the plugin also ships a built-in PSR-4 fallback so it runs even without `vendor/`.

### Building the clean package
This repo **is** the distributable. To rebuild it from a development checkout (which additionally
contains `tests/`, `scripts/`, `phpcs.xml`, dev `vendor/`):

```bash
# from the dev plugin root
composer install --no-dev --optimize-autoloader   # vendor = autoloader only
# copy shippable files only:
#   moksafocou.php uninstall.php readme.txt LICENSE composer.json src/ assets/ languages/ vendor/
# then strip EVERY dotfile (wp.org hidden_files hard block):
find . -name '.*' -not -name '.' -not -name '..' -exec rm -rf {} +
```

The result must have **0 hidden files**, no `tests/` / `scripts/` / `phpcs.xml`, and the
folder name == slug (`moksafocou`).

---

## License

GPLv3 — see [`LICENSE`](LICENSE).
