<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Templates;

use Moksafocou\Coupon\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in coupon templates — curated presets an admin can apply with one click to
 * spin up a pre-filled draft coupon (matching the Advanced Coupons "templates" UX).
 *
 * Each template is pure data: native WC_Coupon fields + our _moksafocou_* meta.
 * `category` groups templates by marketing goal (new-customer / AOV / shipping / …)
 * so the page can section + filter them for quick selection. `requires` names the
 * feature module(s) a template depends on (a string or an array when it needs more
 * than one, e.g. 滿額免運 needs both shipping + conditions); the page disables apply
 * (and Applier refuses) when any required module is off, so a template never produces
 * a silently-inert coupon.
 */
final class Catalog {

	/**
	 * Marketing-goal buckets, in display order. Distinct from a coupon's discount
	 * mechanic (percent / fixed / BOGO) — that stays the per-card badge.
	 *
	 * @return array<string,string> category key => human label
	 */
	public static function categories(): array {
		return array(
			'acquisition' => __( 'New customer acquisition', 'moksa-coupons-for-woocommerce' ),
			'aov'         => __( 'Increase order value', 'moksa-coupons-for-woocommerce' ),
			'shipping'    => __( 'Shipping offer', 'moksa-coupons-for-woocommerce' ),
			'promo'       => __( 'Promotion / limited-time', 'moksa-coupons-for-woocommerce' ),
			'seasonal'    => __( 'Holiday / seasonal', 'moksa-coupons-for-woocommerce' ),
			'bonus'       => __( 'Buy & get / gift', 'moksa-coupons-for-woocommerce' ),
			'member'      => __( 'Membership / repeat purchase', 'moksa-coupons-for-woocommerce' ),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		return array(

			// ── 新客獲取 ────────────────────────────────────────────────
			array(
				'id'       => 'new_customer',
				'category' => 'acquisition',
				'label'    => __( '10% off first purchase for new customers', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'New customers get 10% off their first order (limited to one use per customer).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => 'conditions',
				'prefix'   => 'NEW',
				'native'   => array(
					'discount_type'        => 'percent',
					'amount'               => 10,
					'usage_limit_per_user' => 1,
					'description'          => __( 'New customer first-purchase offer', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::CUST_ENABLED    => 'yes',
					Keys::CUST_FIRST_ONLY => 'yes',
				),
			),
			array(
				'id'       => 'welcome_fixed',
				'category' => 'acquisition',
				'label'    => __( '$150 off $600 for new customers', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'New customers get $150 off orders over $600 to encourage first-purchase basket building (one use per person).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => 'conditions',
				'prefix'   => 'WELCOME',
				'native'   => array(
					'discount_type'        => 'fixed_cart',
					'amount'               => 150,
					'usage_limit_per_user' => 1,
					'description'          => __( 'New customer welcome discount', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::CUST_ENABLED    => 'yes',
					Keys::CUST_FIRST_ONLY => 'yes',
					Keys::MIN_SUBTOTAL    => '600',
				),
			),
			array(
				'id'       => 'signup_link',
				'category' => 'acquisition',
				'label'    => __( 'Claim a coupon via a dedicated link', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Automatically apply a coupon via a URL, ideal for EDM / social traffic (after applying, set the link alias on the "URL coupon" tab).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => 'url',
				'prefix'   => 'LINK',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'description'   => __( 'Link traffic coupon', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::URL_ENABLED => 'yes',
				),
			),

			// ── 提高客單價 ──────────────────────────────────────────────
			array(
				'id'       => 'spend_save',
				'category' => 'aov',
				'label'    => __( '$100 off $1000', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Get $100 off when the cart reaches $1000.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => 'conditions',
				'prefix'   => 'SAVE',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 100,
					'description'   => __( 'Threshold discount', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::MIN_SUBTOTAL => '1000',
				),
			),
			array(
				'id'       => 'spend_save_big',
				'category' => 'aov',
				'label'    => __( '$400 off $3000', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Get $400 off when the cart reaches $3000, boosting order value.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => 'conditions',
				'prefix'   => 'SAVE',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 400,
					'description'   => __( 'Large-amount discount', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::MIN_SUBTOTAL => '3000',
				),
			),
			array(
				'id'       => 'spend_percent',
				'category' => 'aov',
				'label'    => __( '12% off orders over $2000', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Get 12% off when the cart reaches $2000.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => 'conditions',
				'prefix'   => 'OVER',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 12,
					'description'   => __( 'Threshold discount', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::MIN_SUBTOTAL => '2000',
				),
			),
			array(
				'id'       => 'bulk_qty',
				'category' => 'aov',
				'label'    => __( '10% off 3 or more items', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Get 10% off when the cart has 3 or more items.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => 'conditions',
				'prefix'   => 'BULK',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'description'   => __( 'Bulk purchase offer', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::MIN_QTY => '3',
				),
			),

			// ── 運費優惠 ────────────────────────────────────────────────
			array(
				'id'       => 'free_ship',
				'category' => 'shipping',
				'label'    => __( 'Free shipping coupon', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'After applying, all shipping methods are free (via shipping override).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => 'shipping',
				'prefix'   => 'FREESHIP',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 0,
					'description'   => __( 'Free shipping offer', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::SHIP_MODE => 'free',
				),
			),
			array(
				'id'       => 'free_ship_min',
				'category' => 'shipping',
				'label'    => __( 'Free shipping over $800', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Free shipping only when the cart reaches $800, balancing shipping cost and basket building.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => array( 'shipping', 'conditions' ),
				'prefix'   => 'FREESHIP',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 0,
					'description'   => __( 'Free shipping threshold', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::SHIP_MODE    => 'free',
					Keys::MIN_SUBTOTAL => '800',
				),
			),
			array(
				'id'       => 'half_ship',
				'category' => 'shipping',
				'label'    => __( 'Half-price shipping', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'After applying, shipping for all methods is 50% off.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => 'shipping',
				'prefix'   => 'SHIP',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 0,
					'description'   => __( 'Shipping discount', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::SHIP_MODE  => 'percent',
					Keys::SHIP_VALUE => '50',
				),
			),

			// ── 促銷・限時 ──────────────────────────────────────────────
			array(
				'id'       => 'storewide',
				'category' => 'promo',
				'label'    => __( '15% off site-wide', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '15% off all products site-wide, ideal for big promotions.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'SALE',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 15,
					'description'   => __( 'Site-wide promotion', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'weekend_sale',
				'category' => 'promo',
				'label'    => __( '20% off weekends only', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'A 20% off coupon usable only on Saturdays and Sundays (based on the store time zone).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => 'conditions',
				'prefix'   => 'WEEKEND',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 20,
					'description'   => __( 'Weekend promotion', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::DAYTIME_ENABLED => 'yes',
					Keys::DAYTIME_DAYS    => array( 0, 6 ),
				),
			),
			array(
				'id'       => 'happy_hour',
				'category' => 'promo',
				'label'    => __( '10% off happy hour', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '10% off daily from 14:00–17:00 to drive orders during off-peak hours.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => 'conditions',
				'prefix'   => 'HAPPY',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'description'   => __( 'Off-peak offer', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::DAYTIME_ENABLED => 'yes',
					Keys::DAYTIME_DAYS    => array( 0, 1, 2, 3, 4, 5, 6 ),
					Keys::DAYTIME_START   => '14:00',
					Keys::DAYTIME_END     => '17:00',
				),
			),
			array(
				'id'       => 'flash_sale',
				'category' => 'promo',
				'label'    => __( '25% off limited time', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Short-term limited-time promotion (after applying, set the start and end times on the "Schedule" tab to automatically go live and expire).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => 'conditions',
				'prefix'   => 'FLASH',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 25,
					'description'   => __( 'Limited-time flash sale', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::SCHEDULE_ENABLED => 'yes',
				),
			),

			// ── 節慶・季節(購物節 × 折扣級距,搭配建立視窗的「到期日」即為限時券) ──
			array(
				'id'       => 'black_friday',
				'category' => 'seasonal',
				'label'    => __( '20% off Black Friday', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'A 20% off Black Friday big-sale coupon (set an expiry date when creating it to make it a limited-time coupon).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'BF',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 20,
					'description'   => __( 'Black Friday offer', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'cyber_monday',
				'category' => 'seasonal',
				'label'    => __( '15% off Cyber Monday', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '15% off online-exclusive for Cyber Monday.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'CYBER',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 15,
					'description'   => __( 'Cyber Monday deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'double_eleven',
				'category' => 'seasonal',
				'label'    => __( '20% off for Double 11', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '20% off coupon for the Double 11 shopping festival.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'DOUBLE11',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 20,
					'description'   => __( 'Double 11 deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'double_twelve',
				'category' => 'seasonal',
				'label'    => __( '12% off for Double 12', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '12% off coupon for the Double 12 follow-up sale.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'DOUBLE12',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 12,
					'description'   => __( 'Double 12 deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'anniversary',
				'category' => 'seasonal',
				'label'    => __( '10% off for the anniversary sale', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '10% off store-wide coupon for the anniversary sale.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'ANNIV',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'description'   => __( 'Anniversary sale deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'christmas',
				'category' => 'seasonal',
				'label'    => __( '15% off for Christmas', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '15% off coupon for the Christmas season.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'XMAS',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 15,
					'description'   => __( 'Christmas deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'christmas_free_ship',
				'category' => 'seasonal',
				'label'    => __( 'Free shipping for Christmas', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Store-wide free-shipping coupon for the Christmas season (via shipping override).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => 'shipping',
				'prefix'   => 'XMASSHIP',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 0,
					'description'   => __( 'Christmas free shipping', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::SHIP_MODE => 'free',
				),
			),
			array(
				'id'       => 'new_year',
				'category' => 'seasonal',
				'label'    => __( '12% off for New Year', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '12% off coupon for New Year\'s Eve / New Year\'s Day.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'NEWYEAR',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 12,
					'description'   => __( 'New Year deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'lunar_new_year',
				'category' => 'seasonal',
				'label'    => __( 'Lunar New Year red-envelope 200 off', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Lunar New Year red-envelope coupon, 200 off directly.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => '',
				'prefix'   => 'CNY',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 200,
					'description'   => __( 'Lunar New Year red-envelope discount', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'valentines',
				'category' => 'seasonal',
				'label'    => __( '12% off for Valentine\'s Day', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '12% off coupon for the Valentine\'s Day season.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'LOVE',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 12,
					'description'   => __( 'Valentine\'s Day deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'mothers_day',
				'category' => 'seasonal',
				'label'    => __( '10% off for Mother\'s Day', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '10% off coupon for the Mother\'s Day season.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'MOM',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'description'   => __( 'Mother\'s Day deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'fathers_day',
				'category' => 'seasonal',
				'label'    => __( '10% off for Father\'s Day', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '10% off coupon for the Father\'s Day season.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'DAD',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'description'   => __( 'Father\'s Day deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'mid_autumn',
				'category' => 'seasonal',
				'label'    => __( '10% off for the Mid-Autumn Festival', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '10% off coupon for the Mid-Autumn Festival season.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'MOON',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'description'   => __( 'Mid-Autumn Festival deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'double_eleven_free_ship',
				'category' => 'seasonal',
				'label'    => __( 'Double 11 free shipping', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Store-wide free-shipping coupon for Double 11 (via shipping override).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => 'shipping',
				'prefix'   => 'D11SHIP',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 0,
					'description'   => __( 'Double 11 free shipping', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::SHIP_MODE => 'free',
				),
			),

			array(
				'id'       => 'mid_year_618',
				'category' => 'seasonal',
				'label'    => __( '20% off for the 618 Mid-Year Sale', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '20% off coupon for the 618 Mid-Year shopping festival, the most important discount battle of the first half of the year.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'MID618',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 20,
					'description'   => __( '618 Mid-Year Sale', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'womens_day',
				'category' => 'seasonal',
				'label'    => __( '12% off for Women\'s Day (3.8)', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '12% off coupon for the 3.8 Queen\'s Day, focused on beauty, skincare and self-pampering.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'WOMEN',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 12,
					'description'   => __( 'Women\'s Day (3.8) deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'qixi',
				'category' => 'seasonal',
				'label'    => __( '12% off for Qixi Valentine\'s Day', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '12% off coupon for Qixi Valentine\'s Day, the couples\' gifting season.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'QIXI',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 12,
					'description'   => __( 'Qixi Valentine\'s Day deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'childrens_day',
				'category' => 'seasonal',
				'label'    => __( '100 off for Children\'s Day', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '100 off directly for the Children\'s Day family season, focused on baby and parent-child products.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => '',
				'prefix'   => 'KIDS',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 100,
					'description'   => __( 'Children\'s Day discount', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'dragon_boat',
				'category' => 'seasonal',
				'label'    => __( '10% off for the Dragon Boat Festival', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '10% off coupon for the Dragon Boat Festival season, rice-dumpling gift boxes and summer-cooling products.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'DRAGON',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'description'   => __( 'Dragon Boat Festival deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'national_day',
				'category' => 'seasonal',
				'label'    => __( '10% off for Double Tenth National Day', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '10% off coupon for the Double Tenth National Day holiday.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'NATION',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'description'   => __( 'Double Tenth National Day deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'halloween',
				'category' => 'seasonal',
				'label'    => __( '15% off for Halloween', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '15% off coupon for the Halloween season.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'HALLOWEEN',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 15,
					'description'   => __( 'Halloween deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
			array(
				'id'       => 'back_to_school',
				'category' => 'seasonal',
				'label'    => __( '10% off for the back-to-school season', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '10% off coupon for the back-to-school season, stationery, electronics and daily essentials.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'SCHOOL',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'description'   => __( 'Back-to-school season deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),

			// ── 買送・贈品 ──────────────────────────────────────────────
			array(
				'id'       => 'bogo',
				'category' => 'bonus',
				'label'    => __( 'Buy two get one free', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Buy 2 specific items, get 1 free (after applying, set the specific products on the "Buy X Get Y" tab).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'moksafocou_bogo',
				'requires' => 'bogo',
				'prefix'   => 'BOGO',
				'native'   => array(
					'discount_type' => 'moksafocou_bogo',
					'amount'        => 0,
					'description'   => __( 'Buy two get one free', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::BOGO_TRIGGER_QTY  => '2',
					Keys::BOGO_REWARD_QTY   => '1',
					Keys::BOGO_REWARD_MODE  => 'free',
					Keys::BOGO_DEAL_MODE    => 'repeat',
					Keys::BOGO_REPEAT_LIMIT => '0',
				),
			),
			array(
				'id'       => 'second_half',
				'category' => 'bonus',
				'label'    => __( 'Second item half price', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Buy 1 item, get the second at half price (after applying, set the specific products on the "Buy X Get Y" tab).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'moksafocou_bogo',
				'requires' => 'bogo',
				'prefix'   => 'SECOND',
				'native'   => array(
					'discount_type' => 'moksafocou_bogo',
					'amount'        => 0,
					'description'   => __( 'Second item half price', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::BOGO_TRIGGER_QTY  => '1',
					Keys::BOGO_REWARD_QTY   => '1',
					Keys::BOGO_REWARD_MODE  => 'percent',
					Keys::BOGO_REWARD_VALUE => '50',
					Keys::BOGO_DEAL_MODE    => 'repeat',
					Keys::BOGO_REPEAT_LIMIT => '0',
				),
			),
			array(
				'id'       => 'nth_third_free',
				'category' => 'bonus',
				'label'    => __( 'Cheapest item free for every 3', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'For every 3 items in the cart, the cheapest one is free (after applying, set the specific products / categories on the "Nth-item discount" tab).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'moksafocou_nth_item',
				'requires' => 'nthitem',
				'prefix'   => 'NTH3FREE',
				'native'   => array(
					'discount_type' => 'moksafocou_nth_item',
					'amount'        => 0,
					'description'   => __( 'Cheapest item free for every 3', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::NTH_N            => '3',
					Keys::NTH_REWARD_MODE  => 'free',
					Keys::NTH_GROUP_BY     => 'cart',
					Keys::NTH_DEAL_MODE    => 'repeat',
					Keys::NTH_REPEAT_LIMIT => '0',
				),
			),
			array(
				'id'       => 'nth_third_30off',
				'category' => 'bonus',
				'label'    => __( '30% off the third item for every 3', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'For every 3 items in the cart, get 30% off the cheapest one (after applying, set the specific products / categories on the "Nth-item discount" tab).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'moksafocou_nth_item',
				'requires' => 'nthitem',
				'prefix'   => 'NTH3',
				'native'   => array(
					'discount_type' => 'moksafocou_nth_item',
					'amount'        => 0,
					'description'   => __( '30% off the third item for every 3', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::NTH_N            => '3',
					Keys::NTH_REWARD_MODE  => 'percent',
					Keys::NTH_REWARD_VALUE => '30',
					Keys::NTH_GROUP_BY     => 'cart',
					Keys::NTH_DEAL_MODE    => 'repeat',
					Keys::NTH_REPEAT_LIMIT => '0',
				),
			),
			array(
				'id'       => 'free_gift',
				'category' => 'bonus',
				'label'    => __( 'Free gift on orders over 1500', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Automatically add a free gift on cart orders over 1500 (after applying, set the gift on the "Free gift" tab).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => array( 'freegift', 'conditions' ),
				'prefix'   => 'GIFT',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 0,
					'description'   => __( 'Spend-threshold gift', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::GIFT_ENABLED => 'yes',
					Keys::GIFT_MODE    => 'free',
					Keys::GIFT_QTY     => '1',
					Keys::MIN_SUBTOTAL => '1500',
				),
			),

			// ── 會員・回購 ──────────────────────────────────────────────
			array(
				'id'       => 'member_only',
				'category' => 'member',
				'label'    => __( 'Members-only 10% off', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '10% off coupon for logged-in members only (customer role).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => 'conditions',
				'prefix'   => 'MEMBER',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'description'   => __( 'Members-only deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::ROLE_ENABLED => 'yes',
					Keys::ROLE_TYPE    => 'allowed',
					Keys::ROLE_LIST    => array( 'customer' ),
				),
			),
			array(
				'id'       => 'returning',
				'category' => 'member',
				'label'    => __( 'Win-back gift 12% off', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '12% off to reward returning customers who have completed at least 1 order.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => 'conditions',
				'prefix'   => 'AGAIN',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 12,
					'description'   => __( 'Win-back deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::CUST_ENABLED    => 'yes',
					Keys::CUST_MIN_ORDERS => '1',
				),
			),
			array(
				'id'       => 'big_spender',
				'category' => 'member',
				'label'    => __( 'High-spend member 20% off', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '20% off to reward high-value customers whose cumulative spend reaches 10000.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => 'conditions',
				'prefix'   => 'TOPVIP',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 20,
					'description'   => __( 'High-spend member deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::CUST_ENABLED   => 'yes',
					Keys::CUST_MIN_SPENT => '10000',
				),
			),
			// ── 階梯折扣 / 進階規則 / 區域 / 付款 等新功能展示 ──────────────
			array(
				'id'       => 'tiered_aov',
				'category' => 'aov',
				'label'    => __( 'Tiered discount by cumulative spend', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'A single coupon tiered by cart amount: 10% off over 1000, 15% off over 2000, 20% off over 3000, automatically taking the highest tier.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => 'discounttiers',
				'prefix'   => 'TIER',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'description'   => __( 'Tiered discount by cumulative spend', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::TIERS_ENABLED     => 'yes',
					Keys::TIERS_TARGET_MODE => 'all',
					Keys::TIERS             => '[{"min_subtotal":1000,"min_qty":0,"percent":10},{"min_subtotal":2000,"min_qty":0,"percent":15},{"min_subtotal":3000,"min_qty":0,"percent":20}]',
				),
			),
			array(
				'id'       => 'advanced_combo',
				'category' => 'aov',
				'label'    => __( 'Advanced rules: over 600 and 2 items get 80 off', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Demo advanced-rules AND combination: cart over 600 "and" at least 2 items to get 80 off. You can expand further on the "Advanced rules" tab.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => 'advrules',
				'prefix'   => 'COMBO',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 80,
					'description'   => __( 'Spend-and-quantity discount', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::RULES_ENABLED => 'yes',
					Keys::RULES         => '{"match":"all","groups":[{"match":"all","rules":[{"type":"subtotal","op":"gte","value":"600"},{"type":"quantity","op":"gte","value":"2"}]}]}',
				),
			),
			array(
				'id'       => 'winback',
				'category' => 'member',
				'label'    => __( 'Return-visit gift for no purchase in the last 30 days', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Returning customers whose last order was more than 30 days ago get 100 off, to win back dormant customers (logged-in members only; guests have no purchase history).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => 'advrules',
				'prefix'   => 'BACK',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 100,
					'description'   => __( 'Return-visit win-back gift', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::RULES_ENABLED => 'yes',
					Keys::RULES         => '{"match":"all","groups":[{"match":"all","rules":[{"type":"hours_since_last_order","op":"gte","value":"720"}]}]}',
				),
			),
			array(
				'id'       => 'tw_mainland_freeship',
				'category' => 'shipping',
				'label'    => __( 'Free shipping to the main island (Taiwan only)', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Free shipping only when the shipping address is in Taiwan; not applicable overseas.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => array( 'shipping', 'conditions' ),
				'prefix'   => 'TWSHIP',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 0,
					'description'   => __( 'Main-island free shipping', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::SHIP_MODE            => 'free',
					Keys::SHIPREGION_ENABLED   => 'yes',
					Keys::SHIPREGION_MODE      => 'allow',
					Keys::SHIPREGION_COUNTRIES => array( 'TW' ),
				),
			),
			array(
				'id'       => 'heavy_freeship',
				'category' => 'shipping',
				'label'    => __( 'Free shipping on weight over 5kg', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Free shipping when the total cart weight reaches 5 kg, suitable for heavy goods such as food / pet food (after applying, adjust the threshold to the actual product weight).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => array( 'shipping', 'advrules' ),
				'prefix'   => 'HEAVY',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 0,
					'description'   => __( 'Weight-based free shipping', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::SHIP_MODE     => 'free',
					Keys::RULES_ENABLED => 'yes',
					Keys::RULES         => '{"match":"all","groups":[{"match":"all","rules":[{"type":"cart_weight","op":"gte","value":"5"}]}]}',
				),
			),
			array(
				'id'       => 'percent_capped',
				'category' => 'aov',
				'label'    => __( '15% off store-wide (up to 500)', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '15% off store-wide, but with a maximum discount of 500 per order, protecting margins on high-value orders.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => 'discountcap',
				'prefix'   => 'CAPD',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 15,
					'description'   => __( 'Maximum-discount protection', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::DISCOUNT_CAP => '500',
				),
			),
			array(
				'id'       => 'auto_sitewide',
				'category' => 'promo',
				'label'    => __( 'Auto-apply 5% off store-wide', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '5% off store-wide automatically added when the customer enters the cart, no code required (after applying, set the campaign start and end on the Schedule tab to avoid a permanent discount).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => array( 'autoapply', 'conditions' ),
				'prefix'   => 'AUTO',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 5,
					'description'   => __( 'Auto-apply store-wide discount', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::AUTO_APPLY       => 'yes',
					Keys::SCHEDULE_ENABLED => 'yes',
				),
			),
			array(
				'id'       => 'vip_exclusive',
				'category' => 'member',
				'label'    => __( 'VIP non-stackable 20% off', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '20% off for members only (customer role), and cannot be combined with other coupons.', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => array( 'conditions', 'stacking' ),
				'prefix'   => 'VIP',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 20,
					'description'   => __( 'VIP-exclusive non-stackable', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::ROLE_ENABLED  => 'yes',
					Keys::ROLE_TYPE     => 'allowed',
					Keys::ROLE_LIST     => array( 'customer' ),
					Keys::STACK_EXCLUDE => 'yes',
				),
			),
			array(
				'id'       => 'payment_specific',
				'category' => 'promo',
				'label'    => __( '50 off for a specific payment method', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '50 off coupon valid only for a specific payment method (after applying, choose the payment method to restrict on the "Payment method" tab, e.g. LINE Pay / JKOPAY).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'fixed_cart',
				'requires' => 'conditions',
				'prefix'   => 'PAY',
				'native'   => array(
					'discount_type' => 'fixed_cart',
					'amount'        => 50,
					'description'   => __( 'Payment-method discount', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::PAYMENT_ENABLED => 'yes',
					Keys::PAYMENT_MODE    => 'allow',
				),
			),
			array(
				'id'       => 'category_required',
				'category' => 'promo',
				'label'    => __( 'Required-category discount', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '85% off only when the cart contains products from a specified category, suitable for cross-selling / bundle promotions (after applying, choose the required category on the "Product conditions" tab).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => 'conditions',
				'prefix'   => 'BUNDLE',
				'native'   => array(
					'discount_type' => 'percent',
					'amount'        => 15,
					'description'   => __( 'Required-category discount', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::REQ_CATEGORIES_MODE => 'any',
				),
			),
			array(
				'id'       => 'bogo_category_once',
				'category' => 'bonus',
				'label'    => __( 'Buy 3 get 1 free in specific categories', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( 'Buy 3, get 1 free in a specific category (once, no repeated stacking; after applying, set the category on the "Buy X Get Y" tab).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'moksafocou_bogo',
				'requires' => 'bogo',
				'prefix'   => 'BGIFT',
				'native'   => array(
					'discount_type' => 'moksafocou_bogo',
					'amount'        => 0,
					'description'   => __( 'Buy 3 get 1 free in specific categories', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(
					Keys::BOGO_TRIGGER_QTY  => '3',
					Keys::BOGO_REWARD_QTY   => '1',
					Keys::BOGO_REWARD_MODE  => 'free',
					Keys::BOGO_DEAL_MODE    => 'once',
					Keys::BOGO_REPEAT_LIMIT => '1',
				),
			),
			array(
				'id'       => 'birthday',
				'category' => 'member',
				'label'    => __( 'Birthday gift 12% off', 'moksa-coupons-for-woocommerce' ),
				'desc'     => __( '12% off coupon exclusive to the birthday person\'s birthday month, limited to one use per person (after applying, send it to the birthday person).', 'moksa-coupons-for-woocommerce' ),
				'type_key' => 'percent',
				'requires' => '',
				'prefix'   => 'BIRTHDAY',
				'native'   => array(
					'discount_type'        => 'percent',
					'amount'               => 12,
					'usage_limit_per_user' => 1,
					'description'          => __( 'Birthday gift deal', 'moksa-coupons-for-woocommerce' ),
				),
				'meta'     => array(),
			),
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::all() as $template ) {
			if ( $template['id'] === $id ) {
				return $template;
			}
		}
		return null;
	}

	/**
	 * Normalize a template's `requires` (string | array | absent) to a clean list of
	 * module slugs. Empty string / empty array → no requirement.
	 *
	 * @param array<string,mixed> $tpl
	 * @return array<int,string>
	 */
	public static function required_modules( array $tpl ): array {
		$req = $tpl['requires'] ?? array();
		if ( is_string( $req ) ) {
			$req = ( '' === $req ) ? array() : array( $req );
		}
		if ( ! is_array( $req ) ) {
			return array();
		}
		$slugs = array();
		foreach ( $req as $slug ) {
			$slug = (string) $slug;
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Human label for a feature-module slug — single source shared by the page (blocked
	 * notice) and the Applier (error message).
	 */
	public static function module_label( string $slug ): string {
		$map = array(
			'conditions'    => __( 'Coupon conditions', 'moksa-coupons-for-woocommerce' ),
			'shipping'      => __( 'Shipping override', 'moksa-coupons-for-woocommerce' ),
			'bogo'          => __( 'Buy X Get Y (BOGO)', 'moksa-coupons-for-woocommerce' ),
			'nthitem'       => __( 'Nth-item discount', 'moksa-coupons-for-woocommerce' ),
			'mixmatch'      => __( 'Mix & Match', 'moksa-coupons-for-woocommerce' ),
			'freegift'      => __( 'Free gift', 'moksa-coupons-for-woocommerce' ),
			'url'           => __( 'URL coupon', 'moksa-coupons-for-woocommerce' ),
			'stacking'      => __( 'Stacking control', 'moksa-coupons-for-woocommerce' ),
			'frontend'      => __( 'Front-end coupon wall', 'moksa-coupons-for-woocommerce' ),
			'discountcap'   => __( 'Maximum discount', 'moksa-coupons-for-woocommerce' ),
			'discounttiers' => __( 'Tiered discount', 'moksa-coupons-for-woocommerce' ),
			'advrules'      => __( 'Advanced rules (AND/OR)', 'moksa-coupons-for-woocommerce' ),
			'autoapply'     => __( 'Auto-apply coupon', 'moksa-coupons-for-woocommerce' ),
		);
		return $map[ $slug ] ?? $slug;
	}

	/**
	 * Whitelist a template's meta map to known plugin keys only (defensive — a typo
	 * in a template would otherwise write a junk meta key).
	 *
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	public static function sanitize_meta( array $meta ): array {
		$allowed = Keys::all();
		$clean   = array();
		foreach ( $meta as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				$clean[ $key ] = $value;
			}
		}
		return $clean;
	}
}
