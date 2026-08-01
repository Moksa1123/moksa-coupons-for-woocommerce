<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\AdvancedRules;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Admin\FieldsSaveGuard;
use Moksafocou\Support\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * 'Advanced rules' coupon edit-screen tab: a visual AND/OR rule builder. The builder JS reads /
 * writes a single hidden <textarea> holding the canonical JSON, so the field degrades to a
 * raw-JSON editor when JS is unavailable and saves identically either way.
 */
final class Fields {

	use FieldsSaveGuard;

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_rules_nonce';
	private const FIELD = 'moksafocou_rules_json';

	private function action( int $id ): string {
		return 'moksafocou_save_rules_coupon_' . $id;
	}

	/**
	 * Type spec shared with the builder JS: type => { label, kind, ops:[op=>label] }. kind
	 * drives the value input (num|ids|country|payment|weekday|role|time|date). Single source
	 * of truth alongside Support\Rules::TYPES.
	 *
	 * @return array<string,array{label:string,kind:string,ops:array<string,string>}>
	 */
	public static function type_spec(): array {
		$numeric  = array(
			'gte' => __( 'Greater than or equal to ≥', 'moksafocou' ),
			'lte' => __( 'Less than or equal to ≤', 'moksafocou' ),
			'gt'  => __( 'Greater than >', 'moksafocou' ),
			'lt'  => __( 'Less than <', 'moksafocou' ),
			'eq'  => __( 'Equal to =', 'moksafocou' ),
			'neq' => __( 'Not equal to ≠', 'moksafocou' ),
		);
		$inset    = array(
			'in'     => __( 'In', 'moksafocou' ),
			'not_in' => __( 'Not in', 'moksafocou' ),
		);
		$beforeaf = array(
			'gte' => __( 'On or after', 'moksafocou' ),
			'lte' => __( 'On or before', 'moksafocou' ),
		);
		$eqneq    = array(
			'eq'  => __( 'Equal to', 'moksafocou' ),
			'neq' => __( 'Not equal to', 'moksafocou' ),
		);
		return array(
			'subtotal'               => array(
				'label' => __( 'Cart subtotal', 'moksafocou' ),
				'kind'  => 'num',
				'ops'   => $numeric,
			),
			'quantity'               => array(
				'label' => __( 'Total item count', 'moksafocou' ),
				'kind'  => 'num',
				'ops'   => $numeric,
			),
			'order_count'            => array(
				'label' => __( 'Customer\'s past order count', 'moksafocou' ),
				'kind'  => 'num',
				'ops'   => $numeric,
			),
			'coupon_usage_count'     => array(
				'label' => __( 'Number of times this coupon has been used (can restrict to the first N)', 'moksafocou' ),
				'kind'  => 'num',
				'ops'   => $numeric,
			),
			'total_spent'            => array(
				'label' => __( 'Customer\'s cumulative spend', 'moksafocou' ),
				'kind'  => 'num',
				'ops'   => $numeric,
			),
			'cart_weight'            => array(
				'label' => __( 'Cart weight (kg)', 'moksafocou' ),
				'kind'  => 'num',
				'ops'   => $numeric,
			),
			'product_quantity'       => array(
				'label' => __( 'Quantity of a specific product', 'moksafocou' ),
				'kind'  => 'pair',
				'ops'   => $numeric,
			),
			'category_spent'         => array(
				'label' => __( 'Cumulative spend in a category', 'moksafocou' ),
				'kind'  => 'pair',
				'ops'   => $numeric,
			),
			'ordered_product'        => array(
				'label' => __( 'Purchased a specific product', 'moksafocou' ),
				'kind'  => 'ids',
				'ops'   => $inset,
			),
			'ordered_category'       => array(
				'label' => __( 'Purchased in a category', 'moksafocou' ),
				'kind'  => 'ids',
				'ops'   => $inset,
			),
			'hours_since_registered' => array(
				'label' => __( 'Hours since registration', 'moksafocou' ),
				'kind'  => 'num',
				'ops'   => $numeric,
			),
			'hours_since_last_order' => array(
				'label' => __( 'Hours since last order', 'moksafocou' ),
				'kind'  => 'num',
				'ops'   => $numeric,
			),
			'coupon_applied'         => array(
				'label' => __( 'Coupon already applied in cart', 'moksafocou' ),
				'kind'  => 'codetext',
				'ops'   => $inset,
			),
			'stock_status'           => array(
				'label' => __( 'Cart contains stock status', 'moksafocou' ),
				'kind'  => 'stock',
				'ops'   => $inset,
			),
			'shipping_zone'          => array(
				'label' => __( 'Shipping zone', 'moksafocou' ),
				'kind'  => 'zone',
				'ops'   => $inset,
			),
			'custom_taxonomy'        => array(
				'label' => __( 'Cart contains custom taxonomy', 'moksafocou' ),
				'kind'  => 'tax',
				'ops'   => $inset,
			),
			'custom_user_meta'       => array(
				'label' => __( 'Custom user meta', 'moksafocou' ),
				'kind'  => 'kv',
				'ops'   => $eqneq,
			),
			'custom_cart_item_meta'  => array(
				'label' => __( 'Cart item meta', 'moksafocou' ),
				'kind'  => 'kv',
				'ops'   => $inset,
			),
			'product_in_cart'        => array(
				'label' => __( 'Cart contains product', 'moksafocou' ),
				'kind'  => 'ids',
				'ops'   => $inset,
			),
			'category_in_cart'       => array(
				'label' => __( 'Cart contains category', 'moksafocou' ),
				'kind'  => 'ids',
				'ops'   => $inset,
			),
			'shipping_country'       => array(
				'label' => __( 'Shipping country / region', 'moksafocou' ),
				'kind'  => 'country',
				'ops'   => $inset,
			),
			'payment_method'         => array(
				'label' => __( 'Payment method', 'moksafocou' ),
				'kind'  => 'payment',
				'ops'   => $inset,
			),
			'user_role'              => array(
				'label' => __( 'User role', 'moksafocou' ),
				'kind'  => 'role',
				'ops'   => $inset,
			),
			'weekday'                => array(
				'label' => __( 'Weekday', 'moksafocou' ),
				'kind'  => 'weekday',
				'ops'   => $inset,
			),
			'time_of_day'            => array(
				'label' => __( 'Time (hour:minute)', 'moksafocou' ),
				'kind'  => 'time',
				'ops'   => $beforeaf,
			),
			'date'                   => array(
				'label' => __( 'Date and time', 'moksafocou' ),
				'kind'  => 'date',
				'ops'   => $beforeaf,
			),
		);
	}

	/**
	 * @return array<int,array{id:string,title:string,render:callable}>
	 */
	public function sections(): array {
		return array(
			array(
				'id'     => 'moksafocou_advrules',
				'title'  => __( 'Advanced rules', 'moksafocou' ),
				'render' => function (): void {
					$this->render_panel();
				},
			),
		);
	}

	private function render_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id   = (int) $post->ID;
		$json = (string) get_post_meta( $id, Keys::RULES, true );
		$json = '' === $json ? '' : (string) Rules::canonical_json( $json );

		echo '<p class="description" style="margin:8px 12px;">'
			. esc_html__( 'Freely combine conditions with "groups" and AND/OR. The whole set can be set to "match all / any group", and within each group you can set "match all / any condition". This adds an extra check on top of the existing single conditions. The payment method condition is validated at checkout.', 'moksafocou' )
			. '</p>';
		woocommerce_wp_checkbox(
			array(
				'id'    => Keys::RULES_ENABLED,
				'value' => get_post_meta( $id, Keys::RULES_ENABLED, true ),
				'label' => __( 'Enable advanced rules', 'moksafocou' ),
			)
		);

		echo '<div class="moksafocou-rules-builder" style="margin:6px 12px;"></div>';
		echo '<p class="form-field"><label for="' . esc_attr( self::FIELD ) . '">' . esc_html__( 'Rules JSON (advanced / fallback)', 'moksafocou' ) . '</label>';
		echo '<textarea id="' . esc_attr( self::FIELD ) . '" name="' . esc_attr( self::FIELD ) . '" rows="4" class="moksafocou-rules-json" style="width:100%;font-family:monospace;">' . esc_textarea( $json ) . '</textarea></p>';

		woocommerce_wp_text_input(
			array(
				'id'    => Keys::RULES_MSG,
				'value' => get_post_meta( $id, Keys::RULES_MSG, true ),
				'label' => __( 'Message shown when not met', 'moksafocou' ),
			)
		);
	}

	/** Enqueue the builder JS + its data on the coupon edit screen only. */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'shop_coupon' !== $screen->id ) {
			return;
		}
		$rel  = 'src/Modules/AdvancedRules/assets/js/builder.js';
		$path = \MOKSAFOCOU_PLUGIN_DIR . $rel;
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : \MOKSAFOCOU_VERSION;
		// selectWoo (WC's select2 fork) + its style are registered by WooCommerce on the coupon
		// screen; depending on them makes the value pickers nice dropdowns instead of native
		// multi-row listboxes.
		$deps = array( 'jquery' );
		if ( wp_script_is( 'selectWoo', 'registered' ) ) {
			$deps[] = 'selectWoo';
		}
		// wc-enhanced-select carries wc_enhanced_select_params (ajax url + product/category search
		// nonces) so the product / category value pickers can use WooCommerce's own AJAX search.
		if ( wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
			$deps[] = 'wc-enhanced-select';
		}
		wp_enqueue_script( 'moksafocou-rules-builder', \MOKSAFOCOU_PLUGIN_URL . $rel, $deps, $ver, true );
		wp_register_style( 'moksafocou-rules-builder', false, array(), $ver );
		wp_enqueue_style( 'moksafocou-rules-builder' );
		wp_add_inline_style( 'moksafocou-rules-builder', self::css() );
		wp_localize_script(
			'moksafocou-rules-builder',
			'moksafocouRules',
			array(
				'types'     => self::type_spec(),
				'field'     => self::FIELD,
				'idLabels'  => self::rule_id_labels(),
				'countries' => ( function_exists( 'WC' ) && WC()->countries ) ? WC()->countries->get_countries() : array(),
				'gateways'  => self::gateways(),
				'roles'     => self::roles(),
				'weekdays'  => array(
					'0' => __( 'Sunday', 'moksafocou' ),
					'1' => __( 'Monday', 'moksafocou' ),
					'2' => __( 'Tuesday', 'moksafocou' ),
					'3' => __( 'Wednesday', 'moksafocou' ),
					'4' => __( 'Thursday', 'moksafocou' ),
					'5' => __( 'Friday', 'moksafocou' ),
					'6' => __( 'Saturday', 'moksafocou' ),
				),
				'zones'     => self::zones(),
				'stocks'    => array(
					'instock'     => __( 'In stock', 'moksafocou' ),
					'outofstock'  => __( 'Out of stock', 'moksafocou' ),
					'onbackorder' => __( 'On backorder', 'moksafocou' ),
				),
				'i18n'      => array(
					'matchAll'      => __( 'Match all', 'moksafocou' ),
					'matchAny'      => __( 'Match any', 'moksafocou' ),
					'ofGroups'      => __( 'Group:', 'moksafocou' ),
					'ofRules'       => __( 'This group matches:', 'moksafocou' ),
					'addRule'       => __( '+ Add condition', 'moksafocou' ),
					'addGroup'      => __( '+ Add group', 'moksafocou' ),
					'removeRule'    => __( 'Delete', 'moksafocou' ),
					'removeGroup'   => __( 'Delete group', 'moksafocou' ),
					'idsHint'       => __( 'Enter IDs, separated by commas', 'moksafocou' ),
					'codesHint'     => __( 'Enter coupon codes, separated by commas', 'moksafocou' ),
					'pairId'        => __( 'ID', 'moksafocou' ),
					'pairNum'       => __( 'Value', 'moksafocou' ),
					'taxSlug'       => __( 'Taxonomy slug', 'moksafocou' ),
					'taxTerms'      => __( 'Term IDs (comma-separated)', 'moksafocou' ),
					'metaKey'       => __( 'Meta key', 'moksafocou' ),
					'metaVal'       => __( 'Meta value', 'moksafocou' ),
					'pick'          => __( 'Select…', 'moksafocou' ),
					'searchProduct' => __( 'Search products…', 'moksafocou' ),
					'searchCat'     => __( 'Search categories…', 'moksafocou' ),
				),
			)
		);
	}

	/**
	 * Labels for the product / category ids already referenced in this coupon's saved rules, so
	 * the WooCommerce search dropdowns show names (not bare "#123") for the existing selections.
	 *
	 * @return array<string,string> id => label
	 */
	private static function rule_id_labels(): array {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}
		$labels = array();
		$set    = Rules::parse( (string) get_post_meta( (int) $post->ID, Keys::RULES, true ) );
		foreach ( $set['groups'] as $group ) {
			foreach ( ( is_array( $group ) ? ( $group['rules'] ?? array() ) : array() ) as $rule ) {
				$type  = is_array( $rule ) ? (string) ( $rule['type'] ?? '' ) : '';
				$value = is_array( $rule ) && isset( $rule['value'] ) && is_array( $rule['value'] ) ? $rule['value'] : array();
				if ( in_array( $type, array( 'product_in_cart', 'ordered_product' ), true ) ) {
					foreach ( $value as $pid ) {
						$pid     = (int) $pid;
						$product = ( $pid > 0 && ! isset( $labels[ (string) $pid ] ) && function_exists( 'wc_get_product' ) ) ? wc_get_product( $pid ) : null;
						if ( $product ) {
							$labels[ (string) $pid ] = wp_strip_all_tags( $product->get_formatted_name() );
						}
					}
				} elseif ( in_array( $type, array( 'category_in_cart', 'ordered_category' ), true ) ) {
					foreach ( $value as $tid ) {
						$tid  = (int) $tid;
						$term = ( $tid > 0 && ! isset( $labels[ (string) $tid ] ) ) ? get_term( $tid, 'product_cat' ) : null;
						if ( $term instanceof \WP_Term ) {
							$labels[ (string) $tid ] = $term->name;
						}
					}
				}
			}
		}
		return $labels;
	}

	/** Builder stylesheet — Advanced-Coupons-style group cards + clean two-line rule rows. */
	private static function css(): string {
		return '.moksafocou-rules-builder{font-size:13px;}'
			// WooCommerce's .woocommerce_options_panel floats every select/input; undo that
			// inside the builder so the flex rows lay out correctly.
			. '.moksafocou-rules-builder select,.moksafocou-rules-builder input,.moksafocou-rules-builder .select2-container{float:none!important;margin:0;}'
			. '.mfc-top{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:10px;font-weight:600;}'
			. '.mfc-group{position:relative;border:1px solid #c3c4c7;border-radius:6px;background:#fff;padding:10px;margin:0 0 10px;}'
			. '.mfc-group-head{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:0 0 8px;padding-bottom:8px;border-bottom:1px solid #f0f0f1;}'
			. '.mfc-del-group{margin-inline-start:auto;}'
			// One compact, wrapping row per rule (AC-style): Type · Operator · Value · remove.
			. '.mfc-rule{display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:7px 0;border-top:1px solid #f0f0f1;}'
			. '.mfc-rule:first-of-type{border-top:0;}'
			. '.mfc-type{flex:1 1 150px;min-width:130px;box-sizing:border-box;}'
			. '.mfc-op{flex:0 1 116px;min-width:92px;box-sizing:border-box;}'
			. '.mfc-val{flex:2 1 170px;min-width:120px;}'
			. '.mfc-val>select,.mfc-val>input{width:100%;box-sizing:border-box;}'
			. '.mfc-val>span{display:flex;gap:4px;width:100%;}'
			. '.mfc-val>span>input{flex:1 1 0;min-width:0;box-sizing:border-box;}'
			. '.mfc-val .select2-container{width:100%!important;}'
			. '.mfc-del{flex:0 0 auto;border:0;background:transparent;color:#b32d2e;cursor:pointer;font-size:18px;line-height:1;padding:0 4px;}'
			. '.mfc-del:hover{color:#8a1f20;}'
			. '.mfc-addrule{margin-top:4px;}'
			. '.moksafocou-rules-json{margin-top:6px;}';
	}

	/** @return array<string,string> gateway id => title. */
	private static function gateways(): array {
		$out = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
				if ( is_object( $gateway ) && isset( $gateway->id ) ) {
					$gid         = (string) $gateway->id;
					$title       = method_exists( $gateway, 'get_title' ) ? wp_strip_all_tags( (string) $gateway->get_title() ) : $gid;
					$out[ $gid ] = '' !== $title ? $title : $gid;
				}
			}
		}
		return $out;
	}

	/** @return array<string,string> shipping zone id => name (incl. the "rest of the world" zone 0). */
	private static function zones(): array {
		$out = array();
		if ( class_exists( '\WC_Shipping_Zones' ) ) {
			foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
				if ( isset( $zone['id'], $zone['zone_name'] ) ) {
					$out[ (string) $zone['id'] ] = (string) $zone['zone_name'];
				}
			}
			$out['0'] = __( 'Other regions (not covered)', 'moksafocou' );
		}
		return $out;
	}

	/** @return array<string,string> role slug => label. */
	private static function roles(): array {
		$out = array( 'guest' => __( 'Guest (not logged in)', 'moksafocou' ) );
		if ( function_exists( 'wp_roles' ) ) {
			foreach ( wp_roles()->roles as $slug => $role ) {
				$out[ (string) $slug ] = translate_user_role( $role['name'] );
			}
		}
		return $out;
	}

	/**
	 * @param int        $post_id
	 * @param \WC_Coupon $coupon
	 */
	public function save( $post_id, $coupon ): void {
		$post_id = (int) $post_id;
		if ( ! $this->verify_save( $post_id, self::NONCE ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
		update_post_meta( $post_id, Keys::RULES_ENABLED, isset( $_POST[ Keys::RULES_ENABLED ] ) ? 'yes' : '' );

		// The builder writes canonical JSON here; Rules::canonical_json parses + rebuilds it
		// (only known type/op/value survive), which IS the sanitization for this structured field.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- structural sanitisation via Rules::canonical_json below.
		$raw  = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : '';
		$json = Rules::canonical_json( is_string( $raw ) ? $raw : '' );
		if ( '' === $json ) {
			delete_post_meta( $post_id, Keys::RULES );
		} else {
			update_post_meta( $post_id, Keys::RULES, $json );
		}

		$msg = isset( $_POST[ Keys::RULES_MSG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::RULES_MSG ] ) ) : '';
		if ( '' === trim( $msg ) ) {
			delete_post_meta( $post_id, Keys::RULES_MSG );
		} else {
			update_post_meta( $post_id, Keys::RULES_MSG, $msg );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
