<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\TabIcons;

defined( 'ABSPATH' ) || exit;

/**
 * Gives each Moksa coupon-settings tab its own monochrome line icon, in a single
 * uniform style (CSS mask + currentColor, so the icon follows the tab's active /
 * hover colour just like WooCommerce's own tab icons). Mirrors the Advanced Coupons
 * coupon-settings UX where each section has a distinct icon.
 *
 * Icons are Feather (MIT) line glyphs embedded as SVG data URIs. Pure (PHP only),
 * so the CSS generation is unit-testable.
 */
final class Icons {

	/**
	 * Coupon data-tab key => inner SVG markup (24×24 viewBox, stroke-based). The tab
	 * <li> carries the class "<key>_options" (WooCommerce convention).
	 *
	 * @return array<string,string>
	 */
	public static function map(): array {
		return array(
			'moksafocou_schedule'   => "<rect x='3' y='4' width='18' height='18' rx='2' ry='2'/><line x1='16' y1='2' x2='16' y2='6'/><line x1='8' y1='2' x2='8' y2='6'/><line x1='3' y1='10' x2='21' y2='10'/>",
			'moksafocou_roles'      => "<path d='M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2'/><circle cx='9' cy='7' r='4'/><path d='M23 21v-2a4 4 0 0 0-3-3.87'/><path d='M16 3.13a4 4 0 0 1 0 7.75'/>",
			'moksafocou_cart'       => "<circle cx='9' cy='21' r='1'/><circle cx='20' cy='21' r='1'/><path d='M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6'/>",
			'moksafocou_customer'   => "<path d='M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2'/><circle cx='12' cy='7' r='4'/>",
			'moksafocou_products'   => "<line x1='16.5' y1='9.4' x2='7.5' y2='4.21'/><path d='M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z'/><polyline points='3.27 6.96 12 12.01 20.73 6.96'/><line x1='12' y1='22.08' x2='12' y2='12'/>",
			'moksafocou_daytime'    => "<circle cx='12' cy='12' r='10'/><polyline points='12 6 12 12 16 14'/>",
			'moksafocou_shipregion' => "<path d='M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z'/><circle cx='12' cy='10' r='3'/>",
			'moksafocou_payment'    => "<rect x='1' y='4' width='22' height='16' rx='2' ry='2'/><line x1='1' y1='10' x2='23' y2='10'/>",
			'moksafocou_url'        => "<path d='M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71'/><path d='M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'/>",
			'moksafocou_bogo'       => "<polyline points='20 12 20 22 4 22 4 12'/><rect x='2' y='7' width='20' height='5'/><line x1='12' y1='22' x2='12' y2='7'/><path d='M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z'/><path d='M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z'/>",
			'moksafocou_gift'       => "<polyline points='21 8 21 21 3 21 3 8'/><rect x='1' y='3' width='22' height='5'/><line x1='10' y1='12' x2='14' y2='12'/>",
			'moksafocou_stacking'   => "<polygon points='12 2 2 7 12 12 22 7 12 2'/><polyline points='2 17 12 22 22 17'/><polyline points='2 12 12 17 22 12'/>",
			'moksafocou_shipping'   => "<rect x='1' y='3' width='15' height='13'/><polygon points='16 8 20 8 23 11 23 16 16 16 16 8'/><circle cx='5.5' cy='18.5' r='2.5'/><circle cx='18.5' cy='18.5' r='2.5'/>",
			'moksafocou_frontend'   => "<path d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/><circle cx='12' cy='12' r='3'/>",
			'moksafocou_tiers'      => "<line x1='18' y1='20' x2='18' y2='10'/><line x1='12' y1='20' x2='12' y2='4'/><line x1='6' y1='20' x2='6' y2='14'/>",
			'moksafocou_advrules'   => "<line x1='4' y1='21' x2='4' y2='14'/><line x1='4' y1='10' x2='4' y2='3'/><line x1='12' y1='21' x2='12' y2='12'/><line x1='12' y1='8' x2='12' y2='3'/><line x1='20' y1='21' x2='20' y2='16'/><line x1='20' y1='12' x2='20' y2='3'/><line x1='1' y1='14' x2='7' y2='14'/><line x1='9' y1='8' x2='15' y2='8'/><line x1='17' y1='16' x2='23' y2='16'/>",
		);
	}

	/** Wrap inner SVG markup into a full, CSS-safe data URI. */
	public static function data_uri( string $inner ): string {
		$svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='#000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>" . $inner . '</svg>';
		return 'data:image/svg+xml,' . rawurlencode( $svg );
	}

	/**
	 * Build the full stylesheet. Covers BOTH coupon-settings presentations with the
	 * same icon set, so the relevant rules apply whichever is active:
	 *  - tabs mode    : the icon sits on each native coupon-data tab (li.<key>_options).
	 *  - metabox mode : the icon sits on the matching left tab inside the consolidated
	 *                   metabox (.moksafocou-settings-tabs li.<key>_options).
	 * Selectors for the inactive presentation simply match nothing.
	 */
	public static function css(): string {
		// Shared look for every Moksa tab icon (matches WooCommerce's native tab icons),
		// in both the native coupon-data tabs and our consolidated-metabox tabs.
		$css = '#woocommerce-coupon-data ul.coupon_data_tabs li[class*="moksafocou_"][class*="_options"] a::before,'
			. '.moksafocou-settings-tabs li[class*="moksafocou_"][class*="_options"] a::before{'
			. 'content:"";display:inline-block;width:16px;height:16px;vertical-align:text-bottom;margin-inline-end:6px;'
			. 'background-color:currentColor;'
			. '-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;-webkit-mask-position:center;mask-position:center;'
			. '-webkit-mask-size:16px 16px;mask-size:16px 16px;}';

		foreach ( self::map() as $key => $inner ) {
			$uri  = self::data_uri( $inner );
			$css .= sprintf(
				'#woocommerce-coupon-data ul.coupon_data_tabs li.%1$s_options a::before,'
				. '.moksafocou-settings-tabs li.%1$s_options a::before{-webkit-mask-image:url("%2$s");mask-image:url("%2$s");}',
				$key,
				$uri
			);
		}
		return $css;
	}
}
