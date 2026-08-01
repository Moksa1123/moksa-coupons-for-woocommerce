<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The single registry of every moksafocou-aware coupon discount type. Slug, the three
 * display labels, and the "special-price" flag used to live in five hand-kept places
 * (CouponType, CouponCard::badge, SpecialPriceTypes::TYPES and each module's
 * Type::add_type) which drifted apart — e.g. the BOGO editor label read 'Buy X Get Y (BOGO)'
 * while its card badge read 'Buy X Get Y'. Everything now reads from definitions() here, so a
 * type is described in exactly one row.
 *
 * Three label contexts are intentionally distinct (a row may differ in each):
 *  - label : the customer/report label (cards' long form, reports, emails).
 *  - badge : the compact pill on a coupon card (defaults to label).
 *  - admin : the coupon-editor "Discount type" dropdown label, which may carry the English
 *            mechanic name for admin clarity (defaults to label).
 */
final class DiscountTypeRegistry {

	/**
	 * @return array<int,array{slug:string,label:string,badge?:string,admin?:string,special?:bool}>
	 */
	private static function definitions(): array {
		return array(
			array(
				'slug'  => 'percent',
				'label' => __( 'Percentage discount', 'moksafocou' ),
			),
			array(
				'slug'  => 'fixed_cart',
				'label' => __( 'Fixed cart discount', 'moksafocou' ),
				'badge' => __( 'Cart discount', 'moksafocou' ),
			),
			array(
				'slug'  => 'fixed_product',
				'label' => __( 'Fixed product discount', 'moksafocou' ),
				'badge' => __( 'Product discount', 'moksafocou' ),
			),
			array(
				'slug'    => 'moksafocou_bogo',
				'label'   => __( 'Buy X Get Y', 'moksafocou' ),
				'admin'   => __( 'Buy X Get Y (BOGO)', 'moksafocou' ),
				'special' => true,
			),
			array(
				'slug'    => 'moksafocou_nth_item',
				'label'   => __( 'Nth-item discount', 'moksafocou' ),
				'special' => true,
			),
			array(
				'slug'    => 'moksafocou_mixmatch',
				'label'   => __( 'Mix & Match', 'moksafocou' ),
				'admin'   => __( 'Mix & Match', 'moksafocou' ),
				'special' => true,
			),
			array(
				'slug'  => 'moksafocou_cashback',
				'label' => __( 'Cashback', 'moksafocou' ),
				'admin' => __( 'Cashback / points (Cashback)', 'moksafocou' ),
			),
		);
	}

	/** @return array<string,array{slug:string,label:string,badge?:string,admin?:string,special?:bool}> slug => row. */
	private static function by_slug(): array {
		$out = array();
		foreach ( self::definitions() as $row ) {
			$out[ $row['slug'] ] = $row;
		}
		return $out;
	}

	/** @return array<string,string> slug => customer/report label. */
	public static function labels(): array {
		$out = array();
		foreach ( self::definitions() as $row ) {
			$out[ $row['slug'] ] = $row['label'];
		}
		return $out;
	}

	/**
	 * Customer/report label for a slug. Unknown slugs fall back to WooCommerce's own
	 * registered label, then to the raw slug as a last resort.
	 */
	public static function label( string $slug ): string {
		$rows = self::by_slug();
		if ( isset( $rows[ $slug ] ) ) {
			return $rows[ $slug ]['label'];
		}
		$wc = function_exists( 'wc_get_coupon_types' ) ? wc_get_coupon_types() : array();
		if ( isset( $wc[ $slug ] ) ) {
			return (string) $wc[ $slug ];
		}
		return '' !== $slug ? $slug : '—';
	}

	/** Compact card-badge label for a slug (defaults to the customer label). */
	public static function badge( string $slug ): string {
		$rows = self::by_slug();
		if ( ! isset( $rows[ $slug ] ) ) {
			return self::label( $slug );
		}
		return $rows[ $slug ]['badge'] ?? $rows[ $slug ]['label'];
	}

	/** Coupon-editor "Discount type" dropdown label for a slug (defaults to the customer label). */
	public static function admin_label( string $slug ): string {
		$rows = self::by_slug();
		if ( ! isset( $rows[ $slug ] ) ) {
			return self::label( $slug );
		}
		return $rows[ $slug ]['admin'] ?? $rows[ $slug ]['label'];
	}

	/** @return array<int,string> Slugs whose discount is applied via set_price (not WC's amount engine). */
	public static function special_slugs(): array {
		$out = array();
		foreach ( self::definitions() as $row ) {
			if ( ! empty( $row['special'] ) ) {
				$out[] = $row['slug'];
			}
		}
		return $out;
	}

	/** The slug itself when the plugin knows it, otherwise the catch-all 'other'. */
	public static function type_key( string $slug ): string {
		return isset( self::by_slug()[ $slug ] ) ? $slug : 'other';
	}
}
