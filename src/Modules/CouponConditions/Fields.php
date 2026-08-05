<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponConditions;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Admin\FieldsSaveGuard;
use Moksafocou\Admin\FieldsHelpers;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon edit-screen UI for conditions, using WooCommerce-native coupon data tabs
 * + panels + woocommerce_coupon_options_save. Uses a dedicated nonce field (not
 * the shared _wpnonce) to survive other plugins rewriting it, mirroring Advanced
 * Coupons 4.7.3. Custom _moksafocou_* meta is written directly with
 * update_post_meta (WC_Coupon does not know these props).
 */
final class Fields {

	use FieldsSaveGuard;

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_conditions_nonce';

	private function action( int $id ): string {
		return 'moksafocou_save_coupon_' . $id;
	}

	/**
	 * Six coupon-condition sections for the CouponSections coordinator. Each renders
	 * as a coupon-data tab by default, or as its own metabox when enabled.
	 *
	 * @return array<int,array{id:string,title:string,render:callable}>
	 */
	public function sections(): array {
		return array(
			array(
				'id'     => 'moksafocou_schedule',
				'title'  => __( 'Schedule', 'moksa-coupons-for-woocommerce' ),
				'render' => function (): void {
					$this->render_schedule_panel();
				},
			),
			array(
				'id'     => 'moksafocou_roles',
				'title'  => __( 'Role restriction', 'moksa-coupons-for-woocommerce' ),
				'render' => function (): void {
					$this->render_roles_panel();
				},
			),
			array(
				'id'     => 'moksafocou_cart',
				'title'  => __( 'Cart conditions', 'moksa-coupons-for-woocommerce' ),
				'render' => function (): void {
					$this->render_cart_panel();
				},
			),
			array(
				'id'     => 'moksafocou_customer',
				'title'  => __( 'Customer conditions', 'moksa-coupons-for-woocommerce' ),
				'render' => function (): void {
					$this->render_customer_panel();
				},
			),
			array(
				'id'     => 'moksafocou_products',
				'title'  => __( 'Product conditions', 'moksa-coupons-for-woocommerce' ),
				'render' => function (): void {
					$this->render_products_panel();
				},
			),
			array(
				'id'     => 'moksafocou_shipregion',
				'title'  => __( 'Shipping region', 'moksa-coupons-for-woocommerce' ),
				'render' => function (): void {
					$this->render_shipregion_panel();
				},
			),
			array(
				'id'     => 'moksafocou_payment',
				'title'  => __( 'Payment method', 'moksa-coupons-for-woocommerce' ),
				'render' => function (): void {
					$this->render_payment_panel();
				},
			),
			array(
				'id'     => 'moksafocou_daytime',
				'title'  => __( 'Weekday / time window', 'moksa-coupons-for-woocommerce' ),
				'render' => function (): void {
					$this->render_daytime_panel();
				},
			),
		);
	}

	private function render_schedule_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id = (int) $post->ID;

		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::SCHEDULE_ENABLED,
				'value'       => get_post_meta( $id, Keys::SCHEDULE_ENABLED, true ),
				'label'       => __( 'Enable schedule', 'moksa-coupons-for-woocommerce' ),
				'description' => __( 'This coupon can only be used within the start and end times below (site time zone).', 'moksa-coupons-for-woocommerce' ),
			)
		);
		$this->datetime_field( Keys::SCHEDULE_START, __( 'Start time', 'moksa-coupons-for-woocommerce' ), (string) get_post_meta( $id, Keys::SCHEDULE_START, true ) );
		$this->datetime_field( Keys::SCHEDULE_END, __( 'End time', 'moksa-coupons-for-woocommerce' ), (string) get_post_meta( $id, Keys::SCHEDULE_END, true ) );
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::SCHEDULE_MSG_START,
				'value' => get_post_meta( $id, Keys::SCHEDULE_MSG_START, true ),
				'label' => __( 'Message before it starts', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::SCHEDULE_MSG_END,
				'value' => get_post_meta( $id, Keys::SCHEDULE_MSG_END, true ),
				'label' => __( 'Message when expired', 'moksa-coupons-for-woocommerce' ),
			)
		);
	}

	private function render_roles_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id = (int) $post->ID;

		woocommerce_wp_checkbox(
			array(
				'id'    => Keys::ROLE_ENABLED,
				'value' => get_post_meta( $id, Keys::ROLE_ENABLED, true ),
				'label' => __( 'Enable role restriction', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => Keys::ROLE_TYPE,
				'value'   => get_post_meta( $id, Keys::ROLE_TYPE, true ) ? get_post_meta( $id, Keys::ROLE_TYPE, true ) : 'allowed',
				'label'   => __( 'Restriction type', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'allowed'    => __( 'Only allow these roles', 'moksa-coupons-for-woocommerce' ),
					'disallowed' => __( 'Exclude these roles', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		$this->roles_field( (array) get_post_meta( $id, Keys::ROLE_LIST, true ) );
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::ROLE_MSG,
				'value' => get_post_meta( $id, Keys::ROLE_MSG, true ),
				'label' => __( 'Message when not permitted', 'moksa-coupons-for-woocommerce' ),
			)
		);
	}

	private function render_cart_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id = (int) $post->ID;

		woocommerce_wp_text_input(
			array(
				'id'          => Keys::MIN_SUBTOTAL,
				'value'       => get_post_meta( $id, Keys::MIN_SUBTOTAL, true ),
				'label'       => __( 'Minimum cart subtotal', 'moksa-coupons-for-woocommerce' ),
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'Note: this differs in meaning from WooCommerce\'s native "minimum spend" (you can choose whether tax is included), so do not set both at the same time.', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'    => Keys::MIN_SUBTOTAL_INCL_TAX,
				'value' => get_post_meta( $id, Keys::MIN_SUBTOTAL_INCL_TAX, true ),
				'label' => __( 'Calculate subtotal including tax', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::MIN_QTY,
				'value'             => get_post_meta( $id, Keys::MIN_QTY, true ),
				'label'             => __( 'Minimum item quantity', 'moksa-coupons-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::CART_MSG,
				'value' => get_post_meta( $id, Keys::CART_MSG, true ),
				'label' => __( 'Message when conditions are not met', 'moksa-coupons-for-woocommerce' ),
			)
		);
	}

	private function render_customer_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id = (int) $post->ID;

		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::CUST_ENABLED,
				'value'       => get_post_meta( $id, Keys::CUST_ENABLED, true ),
				'label'       => __( 'Enable customer conditions', 'moksa-coupons-for-woocommerce' ),
				'description' => __( 'Restrict use by the customer\'s order history / total spend (applies only to logged-in customers; guests are treated as having no purchase history).', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::CUST_FIRST_ONLY,
				'value'       => get_post_meta( $id, Keys::CUST_FIRST_ONLY, true ),
				'label'       => __( 'First purchase only', 'moksa-coupons-for-woocommerce' ),
				'description' => __( 'Only customers who have not completed any order can use it. Note: guests who are not logged in are treated as first-time buyers (returning customers checking out as guests may bypass this).', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::CUST_MIN_ORDERS,
				'value'             => get_post_meta( $id, Keys::CUST_MIN_ORDERS, true ),
				'label'             => __( 'Minimum number of past orders', 'moksa-coupons-for-woocommerce' ),
				'type'              => 'number',
				'desc_tip'          => true,
				'description'       => __( 'Leave empty = no limit. The number of orders the customer has completed must reach this value.', 'moksa-coupons-for-woocommerce' ),
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::CUST_MAX_ORDERS,
				'value'             => get_post_meta( $id, Keys::CUST_MAX_ORDERS, true ),
				'label'             => __( 'Maximum number of past orders', 'moksa-coupons-for-woocommerce' ),
				'type'              => 'number',
				'desc_tip'          => true,
				'description'       => __( 'Leave empty = no limit. The coupon becomes unavailable once the customer\'s order count exceeds this value.', 'moksa-coupons-for-woocommerce' ),
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => Keys::CUST_MIN_SPENT,
				'value'       => get_post_meta( $id, Keys::CUST_MIN_SPENT, true ),
				'label'       => __( 'Minimum total spend', 'moksa-coupons-for-woocommerce' ),
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'Leave empty = no limit. The customer\'s total spend must reach this value.', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => Keys::CUST_MAX_SPENT,
				'value'       => get_post_meta( $id, Keys::CUST_MAX_SPENT, true ),
				'label'       => __( 'Maximum total spend', 'moksa-coupons-for-woocommerce' ),
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'Leave empty = no limit. The coupon becomes unavailable once the customer\'s total spend exceeds this value.', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::CUST_MSG,
				'value' => get_post_meta( $id, Keys::CUST_MSG, true ),
				'label' => __( 'Message when conditions are not met', 'moksa-coupons-for-woocommerce' ),
			)
		);
	}

	private function render_products_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id = (int) $post->ID;

		echo '<p class="description" style="margin:8px 12px;">' . esc_html__( 'The cart must contain the following products / categories to use this coupon (unlike WooCommerce\'s native "applicable products": this is a usage requirement, not the discount scope). Leave empty = no limit.', 'moksa-coupons-for-woocommerce' ) . '</p>';
		FieldsHelpers::product_select( Keys::REQ_PRODUCTS, __( 'Required products', 'moksa-coupons-for-woocommerce' ), FieldsHelpers::int_list( get_post_meta( $id, Keys::REQ_PRODUCTS, true ) ) );
		woocommerce_wp_select(
			array(
				'id'      => Keys::REQ_PRODUCTS_MODE,
				'value'   => 'all' === get_post_meta( $id, Keys::REQ_PRODUCTS_MODE, true ) ? 'all' : 'any',
				'label'   => __( 'Product match type', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'any' => __( 'Match any', 'moksa-coupons-for-woocommerce' ),
					'all' => __( 'Match all', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		FieldsHelpers::category_select( Keys::REQ_CATEGORIES, __( 'Required categories', 'moksa-coupons-for-woocommerce' ), FieldsHelpers::int_list( get_post_meta( $id, Keys::REQ_CATEGORIES, true ) ) );
		woocommerce_wp_select(
			array(
				'id'      => Keys::REQ_CATEGORIES_MODE,
				'value'   => 'all' === get_post_meta( $id, Keys::REQ_CATEGORIES_MODE, true ) ? 'all' : 'any',
				'label'   => __( 'Category match type', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'any' => __( 'Match any', 'moksa-coupons-for-woocommerce' ),
					'all' => __( 'Match all', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::PRODUCT_MSG,
				'value' => get_post_meta( $id, Keys::PRODUCT_MSG, true ),
				'label' => __( 'Message shown when product conditions are not met', 'moksa-coupons-for-woocommerce' ),
			)
		);
		echo '<p class="description" style="margin:8px 12px;">' . esc_html__( 'This coupon cannot be used when the cart "contains" the following products / categories (e.g. certain items are not eligible).', 'moksa-coupons-for-woocommerce' ) . '</p>';
		FieldsHelpers::product_select( Keys::EXCL_PRODUCTS, __( 'Exclude products', 'moksa-coupons-for-woocommerce' ), FieldsHelpers::int_list( get_post_meta( $id, Keys::EXCL_PRODUCTS, true ) ) );
		FieldsHelpers::category_select( Keys::EXCL_CATEGORIES, __( 'Exclude categories', 'moksa-coupons-for-woocommerce' ), FieldsHelpers::int_list( get_post_meta( $id, Keys::EXCL_CATEGORIES, true ) ) );
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::EXCL_MSG,
				'value' => get_post_meta( $id, Keys::EXCL_MSG, true ),
				'label' => __( 'Message shown when excluded products are present', 'moksa-coupons-for-woocommerce' ),
			)
		);
	}

	private function render_daytime_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id = (int) $post->ID;

		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::DAYTIME_ENABLED,
				'value'       => get_post_meta( $id, Keys::DAYTIME_ENABLED, true ),
				'label'       => __( 'Enable day / time restrictions', 'moksa-coupons-for-woocommerce' ),
				'description' => __( 'This coupon can only be used on the following days and time slots (site time zone).', 'moksa-coupons-for-woocommerce' ),
			)
		);
		$this->days_field( FieldsHelpers::int_list( get_post_meta( $id, Keys::DAYTIME_DAYS, true ) ) );
		$this->time_field( Keys::DAYTIME_START, __( 'Start time', 'moksa-coupons-for-woocommerce' ), (string) get_post_meta( $id, Keys::DAYTIME_START, true ) );
		$this->time_field( Keys::DAYTIME_END, __( 'End time', 'moksa-coupons-for-woocommerce' ), (string) get_post_meta( $id, Keys::DAYTIME_END, true ) );
		echo '<p class="description" style="margin:8px 12px;">' . esc_html__( 'Leave the time slot empty = all day. An end earlier than the start is treated as overnight (e.g. 22:00–02:00). No day selected = no limit.', 'moksa-coupons-for-woocommerce' ) . '</p>';
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::DAYTIME_MSG,
				'value' => get_post_meta( $id, Keys::DAYTIME_MSG, true ),
				'label' => __( 'Message shown outside the allowed time slot', 'moksa-coupons-for-woocommerce' ),
			)
		);
	}

	/**
	 * @param array<int,int> $selected Selected weekday numbers (0=Sun..6=Sat).
	 */
	private function days_field( array $selected ): void {
		echo '<p class="form-field"><label for="' . esc_attr( Keys::DAYTIME_DAYS ) . '">' . esc_html__( 'Allowed days', 'moksa-coupons-for-woocommerce' ) . '</label>';
		echo '<select id="' . esc_attr( Keys::DAYTIME_DAYS ) . '" name="' . esc_attr( Keys::DAYTIME_DAYS ) . '[]" multiple="multiple" class="wc-enhanced-select" style="width:50%;">';
		foreach ( $this->weekdays() as $num => $name ) {
			echo '<option value="' . esc_attr( (string) $num ) . '"' . selected( in_array( $num, $selected, true ), true, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';
	}

	private function time_field( string $key, string $label, string $value ): void {
		echo '<p class="form-field"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<input type="time" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" /></p>';
	}

	/** @return array<int,string> weekday number (0=Sun..6=Sat) => localized label. */
	private function weekdays(): array {
		global $wp_locale;
		$out = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$out[ $i ] = ( $wp_locale instanceof \WP_Locale ) ? $wp_locale->get_weekday( $i ) : (string) $i;
		}
		return $out;
	}


	private function datetime_field( string $key, string $label, string $value ): void {
		$attr  = esc_attr( $key );
		$shown = '' !== $value ? esc_attr( str_replace( ' ', 'T', substr( $value, 0, 16 ) ) ) : '';
		echo '<p class="form-field"><label for="' . esc_attr( $attr ) . '">' . esc_html( $label ) . '</label>';
		echo '<input type="datetime-local" id="' . esc_attr( $attr ) . '" name="' . esc_attr( $attr ) . '" value="' . esc_attr( $shown ) . '" /></p>';
	}

	/**
	 * @param array<int,string> $selected
	 */
	private function roles_field( array $selected ): void {
		echo '<p class="form-field"><label for="' . esc_attr( Keys::ROLE_LIST ) . '">' . esc_html__( 'User role', 'moksa-coupons-for-woocommerce' ) . '</label>';
		echo '<select id="' . esc_attr( Keys::ROLE_LIST ) . '" name="' . esc_attr( Keys::ROLE_LIST ) . '[]" multiple="multiple" class="wc-enhanced-select" style="width:50%;">';
		foreach ( $this->roles() as $slug => $name ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( in_array( $slug, $selected, true ), true, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';
	}

	/** @return array<string,string> role slug => label (incl. a synthetic guest role). */
	private function roles(): array {
		$out = array( 'guest' => __( 'Guest (not logged in)', 'moksa-coupons-for-woocommerce' ) );
		if ( function_exists( 'wp_roles' ) ) {
			foreach ( wp_roles()->roles as $slug => $role ) {
				$out[ (string) $slug ] = translate_user_role( $role['name'] );
			}
		}
		return $out;
	}

	private function render_shipregion_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id = (int) $post->ID;
		echo '<p class="description" style="margin:8px 12px;">' . esc_html__( 'Restrict this coupon by the "country of the shipping address" (validated at the cart stage). Unlike "Shipping override" — that changes shipping, this restricts the region.', 'moksa-coupons-for-woocommerce' ) . '</p>';
		woocommerce_wp_checkbox(
			array(
				'id'    => Keys::SHIPREGION_ENABLED,
				'value' => get_post_meta( $id, Keys::SHIPREGION_ENABLED, true ),
				'label' => __( 'Enable shipping region restriction', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => Keys::SHIPREGION_MODE,
				'value'   => 'disallow' === get_post_meta( $id, Keys::SHIPREGION_MODE, true ) ? 'disallow' : 'allow',
				'label'   => __( 'Restriction type', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'allow'    => __( 'Only allow the following countries / regions', 'moksa-coupons-for-woocommerce' ),
					'disallow' => __( 'Exclude the following countries / regions', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		$this->countries_field( $this->upper_codes( get_post_meta( $id, Keys::SHIPREGION_COUNTRIES, true ) ) );
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::SHIPREGION_MSG,
				'value' => get_post_meta( $id, Keys::SHIPREGION_MSG, true ),
				'label' => __( 'Message shown when not met', 'moksa-coupons-for-woocommerce' ),
			)
		);
	}

	private function render_payment_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id = (int) $post->ID;
		echo '<p class="description" style="margin:8px 12px;">' . esc_html__( 'Restrict the payment methods this coupon can be used with. The payment method is only determined at checkout, so it is validated at checkout (blocks checkout if not met).', 'moksa-coupons-for-woocommerce' ) . '</p>';
		woocommerce_wp_checkbox(
			array(
				'id'    => Keys::PAYMENT_ENABLED,
				'value' => get_post_meta( $id, Keys::PAYMENT_ENABLED, true ),
				'label' => __( 'Enable payment method restriction', 'moksa-coupons-for-woocommerce' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => Keys::PAYMENT_MODE,
				'value'   => 'disallow' === get_post_meta( $id, Keys::PAYMENT_MODE, true ) ? 'disallow' : 'allow',
				'label'   => __( 'Restriction type', 'moksa-coupons-for-woocommerce' ),
				'options' => array(
					'allow'    => __( 'Only allow the following payment methods', 'moksa-coupons-for-woocommerce' ),
					'disallow' => __( 'Exclude the following payment methods', 'moksa-coupons-for-woocommerce' ),
				),
			)
		);
		$this->gateways_field( $this->string_codes( get_post_meta( $id, Keys::PAYMENT_METHODS, true ) ) );
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::PAYMENT_MSG,
				'value' => get_post_meta( $id, Keys::PAYMENT_MSG, true ),
				'label' => __( 'Message shown when not met', 'moksa-coupons-for-woocommerce' ),
			)
		);
	}

	/**
	 * @param array<int,string> $selected Uppercase country codes.
	 */
	private function countries_field( array $selected ): void {
		$countries = ( function_exists( 'WC' ) && WC()->countries ) ? WC()->countries->get_countries() : array();
		echo '<p class="form-field"><label for="' . esc_attr( Keys::SHIPREGION_COUNTRIES ) . '">' . esc_html__( 'Country / region', 'moksa-coupons-for-woocommerce' ) . '</label>';
		echo '<select id="' . esc_attr( Keys::SHIPREGION_COUNTRIES ) . '" name="' . esc_attr( Keys::SHIPREGION_COUNTRIES ) . '[]" multiple="multiple" class="wc-enhanced-select" style="width:50%;" data-placeholder="' . esc_attr__( 'Select a country / region…', 'moksa-coupons-for-woocommerce' ) . '">';
		foreach ( $countries as $code => $name ) {
			echo '<option value="' . esc_attr( (string) $code ) . '"' . selected( in_array( (string) $code, $selected, true ), true, false ) . '>' . esc_html( (string) $name ) . '</option>';
		}
		echo '</select></p>';
	}

	/**
	 * @param array<int,string> $selected Gateway ids.
	 */
	private function gateways_field( array $selected ): void {
		$gateways = ( function_exists( 'WC' ) && WC()->payment_gateways() ) ? WC()->payment_gateways()->payment_gateways() : array();
		echo '<p class="form-field"><label for="' . esc_attr( Keys::PAYMENT_METHODS ) . '">' . esc_html__( 'Payment method', 'moksa-coupons-for-woocommerce' ) . '</label>';
		echo '<select id="' . esc_attr( Keys::PAYMENT_METHODS ) . '" name="' . esc_attr( Keys::PAYMENT_METHODS ) . '[]" multiple="multiple" class="wc-enhanced-select" style="width:50%;" data-placeholder="' . esc_attr__( 'Select a payment method…', 'moksa-coupons-for-woocommerce' ) . '">';
		foreach ( $gateways as $gateway ) {
			if ( ! is_object( $gateway ) || ! isset( $gateway->id ) ) {
				continue;
			}
			$gid   = (string) $gateway->id;
			$title = method_exists( $gateway, 'get_title' ) ? wp_strip_all_tags( (string) $gateway->get_title() ) : $gid;
			echo '<option value="' . esc_attr( $gid ) . '"' . selected( in_array( $gid, $selected, true ), true, false ) . '>' . esc_html( '' !== $title ? $title : $gid ) . '</option>';
		}
		echo '</select></p>';
	}

	/**
	 * @param mixed $value
	 * @return array<int,string>
	 */
	private function upper_codes( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( static fn( $v ): string => strtoupper( (string) $v ), $value ), static fn( string $v ): bool => '' !== $v ) );
	}

	/**
	 * @param mixed $value
	 * @return array<int,string>
	 */
	private function string_codes( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $value ), static fn( string $v ): bool => '' !== $v ) );
	}

	/**
	 * @param int $post_id
	 * @param \WC_Coupon $coupon
	 */
	public function save( $post_id, $coupon ): void {
		$post_id = (int) $post_id;
		if ( ! current_user_can( self::CAP, $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), $this->action( $post_id ) ) ) {
			return;
		}

		// Schedule.
		update_post_meta( $post_id, Keys::SCHEDULE_ENABLED, isset( $_POST[ Keys::SCHEDULE_ENABLED ] ) ? 'yes' : '' );
		foreach ( array( Keys::SCHEDULE_START, Keys::SCHEDULE_END ) as $key ) {
			$raw  = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			$norm = $this->normalize_datetime( $raw );
			if ( '' === $norm ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $norm );
			}
		}
		$this->save_text( $post_id, Keys::SCHEDULE_MSG_START, isset( $_POST[ Keys::SCHEDULE_MSG_START ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::SCHEDULE_MSG_START ] ) ) : '' );
		$this->save_text( $post_id, Keys::SCHEDULE_MSG_END, isset( $_POST[ Keys::SCHEDULE_MSG_END ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::SCHEDULE_MSG_END ] ) ) : '' );

		// Role.
		update_post_meta( $post_id, Keys::ROLE_ENABLED, isset( $_POST[ Keys::ROLE_ENABLED ] ) ? 'yes' : '' );
		$type = isset( $_POST[ Keys::ROLE_TYPE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::ROLE_TYPE ] ) ) : 'allowed';
		update_post_meta( $post_id, Keys::ROLE_TYPE, in_array( $type, array( 'allowed', 'disallowed' ), true ) ? $type : 'allowed' );
		$valid_roles = array_keys( $this->roles() );
		$roles       = ( isset( $_POST[ Keys::ROLE_LIST ] ) && is_array( $_POST[ Keys::ROLE_LIST ] ) )
			? array_values( array_intersect( array_map( 'sanitize_key', wp_unslash( $_POST[ Keys::ROLE_LIST ] ) ), $valid_roles ) )
			: array();
		update_post_meta( $post_id, Keys::ROLE_LIST, $roles );
		$this->save_text( $post_id, Keys::ROLE_MSG, isset( $_POST[ Keys::ROLE_MSG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::ROLE_MSG ] ) ) : '' );

		// Cart.
		$subtotal = isset( $_POST[ Keys::MIN_SUBTOTAL ] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ Keys::MIN_SUBTOTAL ] ) ) ) : '';
		if ( '' === $subtotal ) {
			delete_post_meta( $post_id, Keys::MIN_SUBTOTAL );
		} else {
			update_post_meta( $post_id, Keys::MIN_SUBTOTAL, $subtotal );
		}
		update_post_meta( $post_id, Keys::MIN_SUBTOTAL_INCL_TAX, isset( $_POST[ Keys::MIN_SUBTOTAL_INCL_TAX ] ) ? 'yes' : '' );
		update_post_meta( $post_id, Keys::MIN_QTY, isset( $_POST[ Keys::MIN_QTY ] ) ? max( 0, absint( wp_unslash( $_POST[ Keys::MIN_QTY ] ) ) ) : 0 );
		$this->save_text( $post_id, Keys::CART_MSG, isset( $_POST[ Keys::CART_MSG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::CART_MSG ] ) ) : '' );

		// Customer history.
		update_post_meta( $post_id, Keys::CUST_ENABLED, isset( $_POST[ Keys::CUST_ENABLED ] ) ? 'yes' : '' );
		update_post_meta( $post_id, Keys::CUST_FIRST_ONLY, isset( $_POST[ Keys::CUST_FIRST_ONLY ] ) ? 'yes' : '' );
		$this->save_int( $post_id, Keys::CUST_MIN_ORDERS, isset( $_POST[ Keys::CUST_MIN_ORDERS ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::CUST_MIN_ORDERS ] ) ) : '' );
		$this->save_int( $post_id, Keys::CUST_MAX_ORDERS, isset( $_POST[ Keys::CUST_MAX_ORDERS ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::CUST_MAX_ORDERS ] ) ) : '' );
		$this->save_price( $post_id, Keys::CUST_MIN_SPENT, isset( $_POST[ Keys::CUST_MIN_SPENT ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::CUST_MIN_SPENT ] ) ) : '' );
		$this->save_price( $post_id, Keys::CUST_MAX_SPENT, isset( $_POST[ Keys::CUST_MAX_SPENT ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::CUST_MAX_SPENT ] ) ) : '' );
		$this->save_text( $post_id, Keys::CUST_MSG, isset( $_POST[ Keys::CUST_MSG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::CUST_MSG ] ) ) : '' );

		// Product / category cart-presence conditions.
		$this->save_id_list( $post_id, Keys::REQ_PRODUCTS, ( isset( $_POST[ Keys::REQ_PRODUCTS ] ) && is_array( $_POST[ Keys::REQ_PRODUCTS ] ) ) ? array_map( 'absint', (array) wp_unslash( $_POST[ Keys::REQ_PRODUCTS ] ) ) : array() );
		$this->save_mode( $post_id, Keys::REQ_PRODUCTS_MODE, isset( $_POST[ Keys::REQ_PRODUCTS_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::REQ_PRODUCTS_MODE ] ) ) : 'any' );
		$this->save_id_list( $post_id, Keys::REQ_CATEGORIES, ( isset( $_POST[ Keys::REQ_CATEGORIES ] ) && is_array( $_POST[ Keys::REQ_CATEGORIES ] ) ) ? array_map( 'absint', (array) wp_unslash( $_POST[ Keys::REQ_CATEGORIES ] ) ) : array() );
		$this->save_mode( $post_id, Keys::REQ_CATEGORIES_MODE, isset( $_POST[ Keys::REQ_CATEGORIES_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::REQ_CATEGORIES_MODE ] ) ) : 'any' );
		$this->save_text( $post_id, Keys::PRODUCT_MSG, isset( $_POST[ Keys::PRODUCT_MSG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::PRODUCT_MSG ] ) ) : '' );
		$this->save_id_list( $post_id, Keys::EXCL_PRODUCTS, ( isset( $_POST[ Keys::EXCL_PRODUCTS ] ) && is_array( $_POST[ Keys::EXCL_PRODUCTS ] ) ) ? array_map( 'absint', (array) wp_unslash( $_POST[ Keys::EXCL_PRODUCTS ] ) ) : array() );
		$this->save_id_list( $post_id, Keys::EXCL_CATEGORIES, ( isset( $_POST[ Keys::EXCL_CATEGORIES ] ) && is_array( $_POST[ Keys::EXCL_CATEGORIES ] ) ) ? array_map( 'absint', (array) wp_unslash( $_POST[ Keys::EXCL_CATEGORIES ] ) ) : array() );
		$this->save_text( $post_id, Keys::EXCL_MSG, isset( $_POST[ Keys::EXCL_MSG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::EXCL_MSG ] ) ) : '' );

		// Shipping-region condition.
		update_post_meta( $post_id, Keys::SHIPREGION_ENABLED, isset( $_POST[ Keys::SHIPREGION_ENABLED ] ) ? 'yes' : '' );
		$sr_mode = isset( $_POST[ Keys::SHIPREGION_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::SHIPREGION_MODE ] ) ) : 'allow';
		update_post_meta( $post_id, Keys::SHIPREGION_MODE, 'disallow' === $sr_mode ? 'disallow' : 'allow' );
		$this->save_code_list( $post_id, Keys::SHIPREGION_COUNTRIES, ( isset( $_POST[ Keys::SHIPREGION_COUNTRIES ] ) && is_array( $_POST[ Keys::SHIPREGION_COUNTRIES ] ) ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST[ Keys::SHIPREGION_COUNTRIES ] ) ) : array() );
		$this->save_text( $post_id, Keys::SHIPREGION_MSG, isset( $_POST[ Keys::SHIPREGION_MSG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::SHIPREGION_MSG ] ) ) : '' );

		// Payment-method condition.
		update_post_meta( $post_id, Keys::PAYMENT_ENABLED, isset( $_POST[ Keys::PAYMENT_ENABLED ] ) ? 'yes' : '' );
		$pm_mode = isset( $_POST[ Keys::PAYMENT_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::PAYMENT_MODE ] ) ) : 'allow';
		update_post_meta( $post_id, Keys::PAYMENT_MODE, 'disallow' === $pm_mode ? 'disallow' : 'allow' );
		$this->save_key_list( $post_id, Keys::PAYMENT_METHODS, ( isset( $_POST[ Keys::PAYMENT_METHODS ] ) && is_array( $_POST[ Keys::PAYMENT_METHODS ] ) ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST[ Keys::PAYMENT_METHODS ] ) ) : array() );
		$this->save_text( $post_id, Keys::PAYMENT_MSG, isset( $_POST[ Keys::PAYMENT_MSG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::PAYMENT_MSG ] ) ) : '' );

		// Day-of-week / time-of-day window.
		update_post_meta( $post_id, Keys::DAYTIME_ENABLED, isset( $_POST[ Keys::DAYTIME_ENABLED ] ) ? 'yes' : '' );
		$this->save_days( $post_id, ( isset( $_POST[ Keys::DAYTIME_DAYS ] ) && is_array( $_POST[ Keys::DAYTIME_DAYS ] ) ) ? array_map( 'absint', (array) wp_unslash( $_POST[ Keys::DAYTIME_DAYS ] ) ) : array() );
		$this->save_time( $post_id, Keys::DAYTIME_START, isset( $_POST[ Keys::DAYTIME_START ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::DAYTIME_START ] ) ) : '' );
		$this->save_time( $post_id, Keys::DAYTIME_END, isset( $_POST[ Keys::DAYTIME_END ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::DAYTIME_END ] ) ) : '' );
		$this->save_text( $post_id, Keys::DAYTIME_MSG, isset( $_POST[ Keys::DAYTIME_MSG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Keys::DAYTIME_MSG ] ) ) : '' );
	}

	/**
	 * @param array<int,int> $raw
	 */
	private function save_days( int $post_id, array $raw ): void {
		$days = array();
		foreach ( $raw as $d ) {
			$n = (int) $d;
			if ( $n >= 0 && $n <= 6 && ! in_array( $n, $days, true ) ) {
				$days[] = $n;
			}
		}
		sort( $days );
		if ( array() === $days ) {
			delete_post_meta( $post_id, Keys::DAYTIME_DAYS );
		} else {
			update_post_meta( $post_id, Keys::DAYTIME_DAYS, $days );
		}
	}

	private function save_time( int $post_id, string $key, string $raw ): void {
		if ( null === Validator::hhmm_to_min( $raw ) ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $raw );
		}
	}

	/**
	 * @param array<int,int> $raw
	 */
	private function save_id_list( int $post_id, string $key, array $raw ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
		if ( array() === $ids ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $ids );
		}
	}

	/**
	 * @param array<int,string> $raw
	 */
	private function save_code_list( int $post_id, string $key, array $raw ): void {
		$codes = array();
		foreach ( $raw as $v ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $v ) ?? '' );
			if ( 2 === strlen( $code ) && ! in_array( $code, $codes, true ) ) {
				$codes[] = $code;
			}
		}
		if ( array() === $codes ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $codes );
		}
	}

	/**
	 * @param array<int,string> $raw
	 */
	private function save_key_list( int $post_id, string $key, array $raw ): void {
		$keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', $raw ) ) ) );
		if ( array() === $keys ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $keys );
		}
	}

	private function save_mode( int $post_id, string $key, string $value ): void {
		update_post_meta( $post_id, $key, 'all' === $value ? 'all' : 'any' );
	}

	private function save_int( int $post_id, string $key, string $value ): void {
		$raw = trim( $value );
		if ( '' === $raw ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, (string) max( 0, (int) $raw ) );
		}
	}

	private function save_price( int $post_id, string $key, string $raw ): void {
		if ( '' === trim( $raw ) ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, wc_format_decimal( $raw ) );
		}
	}

	private function save_text( int $post_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Normalize the datetime-local value to 'Y-m-d H:i:s' wall-clock (site tz) via the
	 * shared SiteTime parser — single source of truth with the REST/AI write path.
	 */
	private function normalize_datetime( string $raw ): string {
		return \Moksafocou\Support\SiteTime::normalize( $raw );
	}
}
