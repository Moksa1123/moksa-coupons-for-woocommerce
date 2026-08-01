<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Summary;

use Moksafocou\Coupon\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * A live 'Coupon summary' side metabox on the coupon editor. A small script reads the form as the
 * admin edits and renders a plain-language summary of what the coupon does, which advanced
 * features are on, and any detected conflicts (e.g. tiers enabled on a non-percent coupon).
 * All labels come from PHP so they stay translatable.
 */
final class Panel {

	public static function boot(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function add(): void {
		add_meta_box(
			'moksafocou-summary',
			__( 'Coupon summary', 'moksafocou' ),
			array( self::class, 'render' ),
			'shop_coupon',
			'side',
			'high'
		);
	}

	public static function render(): void {
		echo '<div id="moksafocou-summary-panel" class="moksafocou-summary">'
			. '<p class="mfc-sum-empty">' . esc_html__( 'While editing the fields, this shows a live effect summary and conflict warnings for this coupon.', 'moksafocou' ) . '</p>'
			. '</div>';
	}

	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'shop_coupon' !== $screen->id ) {
			return;
		}
		$rel  = 'src/Modules/Summary/assets/js/summary.js';
		$path = \MOKSAFOCOU_PLUGIN_DIR . $rel;
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : \MOKSAFOCOU_VERSION;
		wp_enqueue_script( 'moksafocou-summary', \MOKSAFOCOU_PLUGIN_URL . $rel, array(), $ver, true );
		wp_localize_script(
			'moksafocou-summary',
			'moksafocouSummary',
			array(
				'features' => self::features(),
				'i18n'     => self::i18n(),
			)
		);
		wp_register_style( 'moksafocou-summary', false, array(), $ver );
		wp_enqueue_style( 'moksafocou-summary' );
		wp_add_inline_style( 'moksafocou-summary', self::css() );
	}

	/**
	 * Advanced-feature toggles to surface as "已啟用" chips: checkbox selector => label.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function features(): array {
		$map = array(
			Keys::SCHEDULE_ENABLED   => __( 'Schedule', 'moksafocou' ),
			Keys::CUST_ENABLED       => __( 'Customer conditions', 'moksafocou' ),
			Keys::ROLE_ENABLED       => __( 'User role', 'moksafocou' ),
			Keys::DAYTIME_ENABLED    => __( 'Day and time window', 'moksafocou' ),
			Keys::TIERS_ENABLED      => __( 'Tiered discount', 'moksafocou' ),
			Keys::RULES_ENABLED      => __( 'Advanced rules', 'moksafocou' ),
			Keys::SHIPREGION_ENABLED => __( 'Shipping region', 'moksafocou' ),
			Keys::PAYMENT_ENABLED    => __( 'Payment method', 'moksafocou' ),
			Keys::AUTO_APPLY         => __( 'Auto-apply', 'moksafocou' ),
			Keys::GIFT_ENABLED       => __( 'Free gift', 'moksafocou' ),
			Keys::STACK_EXCLUDE      => __( 'Cannot be stacked', 'moksafocou' ),
			Keys::URL_ENABLED        => __( 'URL coupon', 'moksafocou' ),
		);
		$out = array();
		foreach ( $map as $key => $label ) {
			$out[] = array(
				'sel'   => '#' . $key,
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * @return array<string,string>
	 */
	private static function i18n(): array {
		return array(
			'percent'             => __( 'Percentage discount', 'moksafocou' ),
			'fixed_cart'          => __( 'Fixed cart discount', 'moksafocou' ),
			'fixed_product'       => __( 'Fixed product discount', 'moksafocou' ),
			'bogo'                => __( 'Buy X Get Y', 'moksafocou' ),
			'moksafocou_cashback' => __( 'Cashback', 'moksafocou' ),
			'cashbackTab'         => __( 'Apply cashback based on the "Cashback" tab settings after the order is paid', 'moksafocou' ),
			'discountHead'        => __( 'Discount type', 'moksafocou' ),
			'featuresHead'        => __( 'Enabled features', 'moksafocou' ),
			'conflictsHead'       => __( 'Notice', 'moksafocou' ),
			/* translators: %s: discount expressed as a Taiwan 折 number. */
			'zhe'                 => __( 'Approx. %s off', 'moksafocou' ),
			/* translators: %s: percentage off. */
			'percentOff'          => __( '%s%% off', 'moksafocou' ),
			/* translators: %s: fixed amount off. */
			'amountOff'           => __( '%s off', 'moksafocou' ),
			'noExpiry'            => __( 'No expiry date', 'moksafocou' ),
			/* translators: %s: expiry date. */
			'expiresOn'           => __( 'Expires: %s', 'moksafocou' ),
			'tiersDrive'          => __( 'Discount amount determined by the tiered table', 'moksafocou' ),
			'bogoTab'             => __( 'Please set the trigger and reward on the "Buy X Get Y" tab', 'moksafocou' ),
			'cTiersType'          => __( 'Tiered discount only applies to "Percentage discount"; the current discount type does not match.', 'moksafocou' ),
			'cMinMax'             => __( 'The cart minimum amount is greater than the maximum amount, so this coupon will never be usable.', 'moksafocou' ),
			'cPercentRange'       => __( 'Percentage discount cannot exceed 100.', 'moksafocou' ),
			'none'                => __( '(Not set yet)', 'moksafocou' ),
		);
	}

	private static function css(): string {
		return '.moksafocou-summary .mfc-sum-head{font-weight:600;margin:10px 0 4px;font-size:12px;color:#1d2327;}'
			. '.moksafocou-summary .mfc-sum-val{margin:0 0 6px;font-size:13px;}'
			. '.moksafocou-summary .mfc-sum-chips{display:flex;flex-wrap:wrap;gap:5px;}'
			. '.moksafocou-summary .mfc-sum-chip{background:#f0f6fc;color:#0a4b78;border-radius:10px;padding:1px 8px;font-size:11px;}'
			. '.moksafocou-summary .mfc-sum-warn{background:#fcf0f1;color:#8a1f11;border-left:3px solid #d63638;padding:5px 8px;margin:4px 0;font-size:12px;border-radius:2px;}'
			. '.moksafocou-summary .mfc-sum-info{color:#646970;font-size:12px;margin:3px 0;}';
	}
}
