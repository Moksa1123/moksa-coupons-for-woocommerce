<?php

declare( strict_types=1 );

namespace Moksafocou\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shared renderers + helpers for the coupon-editor field tabs. Several feature modules
 * (BOGO, tiered discounts, cart/customer conditions) need the exact same WooCommerce
 * product / category search <select> and the same int-list sanitisation; keeping one copy
 * here avoids the markup drifting between modules.
 */
final class FieldsHelpers {

	/**
	 * A WooCommerce product-search multiselect, pre-filled with the already-selected ids.
	 *
	 * @param string         $id       Field id / name.
	 * @param string         $label    Field label.
	 * @param array<int,int> $selected Pre-selected product IDs.
	 */
	public static function product_select( string $id, string $label, array $selected ): void {
		echo '<p class="form-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<select class="wc-product-search" multiple="multiple" style="width:50%;" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '[]" data-placeholder="' . esc_attr__( 'Search products…', 'moksa-coupons-for-woocommerce' ) . '" data-action="woocommerce_json_search_products_and_variations">';
		foreach ( $selected as $pid ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			if ( $product ) {
				echo '<option value="' . esc_attr( (string) $pid ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
			}
		}
		echo '</select></p>';
	}

	/**
	 * A WooCommerce category-search multiselect, pre-filled with the already-selected term ids.
	 *
	 * @param string         $id       Field id / name.
	 * @param string         $label    Field label.
	 * @param array<int,int> $selected Pre-selected category term IDs.
	 */
	public static function category_select( string $id, string $label, array $selected ): void {
		echo '<p class="form-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<select class="wc-category-search" multiple="multiple" style="width:50%;" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '[]" data-placeholder="' . esc_attr__( 'Search categories…', 'moksa-coupons-for-woocommerce' ) . '" data-action="woocommerce_json_search_categories">';
		foreach ( $selected as $tid ) {
			$term = get_term( $tid, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				echo '<option value="' . esc_attr( (string) $tid ) . '" selected="selected">' . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select></p>';
	}

	/**
	 * Normalise a posted list of ids to positive integers (no dedupe — callers that need
	 * uniqueness do their own array_unique, see CouponConditions::save_id_list).
	 *
	 * @param mixed $value
	 * @return array<int,int>
	 */
	public static function int_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'absint', $value ) ) );
	}
}
