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
				'title'  => __( 'Schedule', 'moksafocou' ),
				'render' => function (): void {
					$this->render_schedule_panel();
				},
			),
			array(
				'id'     => 'moksafocou_roles',
				'title'  => __( 'Role restriction', 'moksafocou' ),
				'render' => function (): void {
					$this->render_roles_panel();
				},
			),
			array(
				'id'     => 'moksafocou_cart',
				'title'  => __( 'Cart conditions', 'moksafocou' ),
				'render' => function (): void {
					$this->render_cart_panel();
				},
			),
			array(
				'id'     => 'moksafocou_customer',
				'title'  => __( 'Customer conditions', 'moksafocou' ),
				'render' => function (): void {
					$this->render_customer_panel();
				},
			),
			array(
				'id'     => 'moksafocou_products',
				'title'  => __( 'Product conditions', 'moksafocou' ),
				'render' => function (): void {
					$this->render_products_panel();
				},
			),
			array(
				'id'     => 'moksafocou_shipregion',
				'title'  => __( 'Shipping region', 'moksafocou' ),
				'render' => function (): void {
					$this->render_shipregion_panel();
				},
			),
			array(
				'id'     => 'moksafocou_payment',
				'title'  => __( 'Payment method', 'moksafocou' ),
				'render' => function (): void {
					$this->render_payment_panel();
				},
			),
			array(
				'id'     => 'moksafocou_daytime',
				'title'  => __( 'Weekday / time window', 'moksafocou' ),
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
				'label'       => __( 'Enable schedule', 'moksafocou' ),
				'description' => __( 'This coupon can only be used within the start and end times below (site time zone).', 'moksafocou' ),
			)
		);
		$this->datetime_field( Keys::SCHEDULE_START, __( 'Start time', 'moksafocou' ), (string) get_post_meta( $id, Keys::SCHEDULE_START, true ) );
		$this->datetime_field( Keys::SCHEDULE_END, __( 'End time', 'moksafocou' ), (string) get_post_meta( $id, Keys::SCHEDULE_END, true ) );
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::SCHEDULE_MSG_START,
				'value' => get_post_meta( $id, Keys::SCHEDULE_MSG_START, true ),
				'label' => __( 'Message before it starts', 'moksafocou' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::SCHEDULE_MSG_END,
				'value' => get_post_meta( $id, Keys::SCHEDULE_MSG_END, true ),
				'label' => __( 'Message when expired', 'moksafocou' ),
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
				'label' => __( 'Enable role restriction', 'moksafocou' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => Keys::ROLE_TYPE,
				'value'   => get_post_meta( $id, Keys::ROLE_TYPE, true ) ? get_post_meta( $id, Keys::ROLE_TYPE, true ) : 'allowed',
				'label'   => __( 'Restriction type', 'moksafocou' ),
				'options' => array(
					'allowed'    => __( 'Only allow these roles', 'moksafocou' ),
					'disallowed' => __( 'Exclude these roles', 'moksafocou' ),
				),
			)
		);
		$this->roles_field( (array) get_post_meta( $id, Keys::ROLE_LIST, true ) );
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::ROLE_MSG,
				'value' => get_post_meta( $id, Keys::ROLE_MSG, true ),
				'label' => __( 'Message when not permitted', 'moksafocou' ),
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
				'label'       => __( 'Minimum cart subtotal', 'moksafocou' ),
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'Note: this differs in meaning from WooCommerce\'s native "minimum spend" (you can choose whether tax is included), so do not set both at the same time.', 'moksafocou' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'    => Keys::MIN_SUBTOTAL_INCL_TAX,
				'value' => get_post_meta( $id, Keys::MIN_SUBTOTAL_INCL_TAX, true ),
				'label' => __( 'Calculate subtotal including tax', 'moksafocou' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::MIN_QTY,
				'value'             => get_post_meta( $id, Keys::MIN_QTY, true ),
				'label'             => __( 'Minimum item quantity', 'moksafocou' ),
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
				'label' => __( 'Message when conditions are not met', 'moksafocou' ),
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
				'label'       => __( 'Enable customer conditions', 'moksafocou' ),
				'description' => __( 'Restrict use by the customer\'s order history / total spend (applies only to logged-in customers; guests are treated as having no purchase history).', 'moksafocou' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::CUST_FIRST_ONLY,
				'value'       => get_post_meta( $id, Keys::CUST_FIRST_ONLY, true ),
				'label'       => __( 'First purchase only', 'moksafocou' ),
				'description' => __( 'Only customers who have not completed any order can use it. Note: guests who are not logged in are treated as first-time buyers (returning customers checking out as guests may bypass this).', 'moksafocou' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => Keys::CUST_MIN_ORDERS,
				'value'             => get_post_meta( $id, Keys::CUST_MIN_ORDERS, true ),
				'label'             => __( 'Minimum number of past orders', 'moksafocou' ),
				'type'              => 'number',
				'desc_tip'          => true,
				'description'       => __( 'Leave empty = no limit. The number of orders the customer has completed must reach this value.', 'moksafocou' ),
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
				'label'             => __( 'Maximum number of past orders', 'moksafocou' ),
				'type'              => 'number',
				'desc_tip'          => true,
				'description'       => __( 'Leave empty = no limit. The coupon becomes unavailable once the customer\'s order count exceeds this value.', 'moksafocou' ),
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
				'label'       => __( 'Minimum total spend', 'moksafocou' ),
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'Leave empty = no limit. The customer\'s total spend must reach this value.', 'moksafocou' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => Keys::CUST_MAX_SPENT,
				'value'       => get_post_meta( $id, Keys::CUST_MAX_SPENT, true ),
				'label'       => __( 'Maximum total spend', 'moksafocou' ),
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'Leave empty = no limit. The coupon becomes unavailable once the customer\'s total spend exceeds this value.', 'moksafocou' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::CUST_MSG,
				'value' => get_post_meta( $id, Keys::CUST_MSG, true ),
				'label' => __( 'Message when conditions are not met', 'moksafocou' ),
			)
		);
	}

	private function render_products_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id = (int) $post->ID;

		echo '<p class="description" style="margin:8px 12px;">' . esc_html__( 'The cart must contain the following products / categories to use this coupon (unlike WooCommerce\'s native "applicable products": this is a usage requirement, not the discount scope). Leave empty = no limit.', 'moksafocou' ) . '</p>';
		FieldsHelpers::product_select( Keys::REQ_PRODUCTS, __( 'Required products', 'moksafocou' ), FieldsHelpers::int_list( get_post_meta( $id, Keys::REQ_PRODUCTS, true ) ) );
		woocommerce_wp_select(
			array(
				'id'      => Keys::REQ_PRODUCTS_MODE,
				'value'   => 'all' === get_post_meta( $id, Keys::REQ_PRODUCTS_MODE, true ) ? 'all' : 'any',
				'label'   => __( 'Product match type', 'moksafocou' ),
				'options' => array(
					'any' => __( 'Match any', 'moksafocou' ),
					'all' => __( 'Match all', 'moksafocou' ),
				),
			)
		);
		FieldsHelpers::category_select( Keys::REQ_CATEGORIES, __( 'Required categories', 'moksafocou' ), FieldsHelpers::int_list( get_post_meta( $id, Keys::REQ_CATEGORIES, true ) ) );
		woocommerce_wp_select(
			array(
				'id'      => Keys::REQ_CATEGORIES_MODE,
				'value'   => 'all' === get_post_meta( $id, Keys::REQ_CATEGORIES_MODE, true ) ? 'all' : 'any',
				'label'   => __( 'Category match type', 'moksafocou' ),
				'options' => array(
					'any' => __( 'Match any', 'moksafocou' ),
					'all' => __( 'Match all', 'moksafocou' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::PRODUCT_MSG,
				'value' => get_post_meta( $id, Keys::PRODUCT_MSG, true ),
				'label' => __( 'Message shown when product conditions are not met', 'moksafocou' ),
			)
		);
		echo '<p class="description" style="margin:8px 12px;">' . esc_html__( 'This coupon cannot be used when the cart "contains" the following products / categories (e.g. certain items are not eligible).', 'moksafocou' ) . '</p>';
		FieldsHelpers::product_select( Keys::EXCL_PRODUCTS, __( 'Exclude products', 'moksafocou' ), FieldsHelpers::int_list( get_post_meta( $id, Keys::EXCL_PRODUCTS, true ) ) );
		FieldsHelpers::category_select( Keys::EXCL_CATEGORIES, __( 'Exclude categories', 'moksafocou' ), FieldsHelpers::int_list( get_post_meta( $id, Keys::EXCL_CATEGORIES, true ) ) );
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::EXCL_MSG,
				'value' => get_post_meta( $id, Keys::EXCL_MSG, true ),
				'label' => __( 'Message shown when excluded products are present', 'moksafocou' ),
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
				'label'       => __( 'Enable day / time restrictions', 'moksafocou' ),
				'description' => __( 'This coupon can only be used on the following days and time slots (site time zone).', 'moksafocou' ),
			)
		);
		$this->days_field( FieldsHelpers::int_list( get_post_meta( $id, Keys::DAYTIME_DAYS, true ) ) );
		$this->time_field( Keys::DAYTIME_START, __( 'Start time', 'moksafocou' ), (string) get_post_meta( $id, Keys::DAYTIME_START, true ) );
		$this->time_field( Keys::DAYTIME_END, __( 'End time', 'moksafocou' ), (string) get_post_meta( $id, Keys::DAYTIME_END, true ) );
		echo '<p class="description" style="margin:8px 12px;">' . esc_html__( 'Leave the time slot empty = all day. An end earlier than the start is treated as overnight (e.g. 22:00–02:00). No day selected = no limit.', 'moksafocou' ) . '</p>';
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::DAYTIME_MSG,
				'value' => get_post_meta( $id, Keys::DAYTIME_MSG, true ),
				'label' => __( 'Message shown outside the allowed time slot', 'moksafocou' ),
			)
		);
	}

	/**
	 * @param array<int,int> $selected Selected weekday numbers (0=Sun..6=Sat).
	 */
	private function days_field( array $selected ): void {
		echo '<p class="form-field"><label for="' . esc_attr( Keys::DAYTIME_DAYS ) . '">' . esc_html__( 'Allowed days', 'moksafocou' ) . '</label>';
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
		echo '<p class="form-field"><label for="' . esc_attr( Keys::ROLE_LIST ) . '">' . esc_html__( 'User role', 'moksafocou' ) . '</label>';
		echo '<select id="' . esc_attr( Keys::ROLE_LIST ) . '" name="' . esc_attr( Keys::ROLE_LIST ) . '[]" multiple="multiple" class="wc-enhanced-select" style="width:50%;">';
		foreach ( $this->roles() as $slug => $name ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( in_array( $slug, $selected, true ), true, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';
	}

	/** @return array<string,string> role slug => label (incl. a synthetic guest role). */
	private function roles(): array {
		$out = array( 'guest' => __( 'Guest (not logged in)', 'moksafocou' ) );
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
		echo '<p class="description" style="margin:8px 12px;">' . esc_html__( 'Restrict this coupon by the "country of the shipping address" (validated at the cart stage). Unlike "Shipping override" — that changes shipping, this restricts the region.', 'moksafocou' ) . '</p>';
		woocommerce_wp_checkbox(
			array(
				'id'    => Keys::SHIPREGION_ENABLED,
				'value' => get_post_meta( $id, Keys::SHIPREGION_ENABLED, true ),
				'label' => __( 'Enable shipping region restriction', 'moksafocou' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => Keys::SHIPREGION_MODE,
				'value'   => 'disallow' === get_post_meta( $id, Keys::SHIPREGION_MODE, true ) ? 'disallow' : 'allow',
				'label'   => __( 'Restriction type', 'moksafocou' ),
				'options' => array(
					'allow'    => __( 'Only allow the following countries / regions', 'moksafocou' ),
					'disallow' => __( 'Exclude the following countries / regions', 'moksafocou' ),
				),
			)
		);
		$this->countries_field( $this->upper_codes( get_post_meta( $id, Keys::SHIPREGION_COUNTRIES, true ) ) );
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::SHIPREGION_MSG,
				'value' => get_post_meta( $id, Keys::SHIPREGION_MSG, true ),
				'label' => __( 'Message shown when not met', 'moksafocou' ),
			)
		);
	}

	private function render_payment_panel(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$id = (int) $post->ID;
		echo '<p class="description" style="margin:8px 12px;">' . esc_html__( 'Restrict the payment methods this coupon can be used with. The payment method is only determined at checkout, so it is validated at checkout (blocks checkout if not met).', 'moksafocou' ) . '</p>';
		woocommerce_wp_checkbox(
			array(
				'id'    => Keys::PAYMENT_ENABLED,
				'value' => get_post_meta( $id, Keys::PAYMENT_ENABLED, true ),
				'label' => __( 'Enable payment method restriction', 'moksafocou' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => Keys::PAYMENT_MODE,
				'value'   => 'disallow' === get_post_meta( $id, Keys::PAYMENT_MODE, true ) ? 'disallow' : 'allow',
				'label'   => __( 'Restriction type', 'moksafocou' ),
				'options' => array(
					'allow'    => __( 'Only allow the following payment methods', 'moksafocou' ),
					'disallow' => __( 'Exclude the following payment methods', 'moksafocou' ),
				),
			)
		);
		$this->gateways_field( $this->string_codes( get_post_meta( $id, Keys::PAYMENT_METHODS, true ) ) );
		woocommerce_wp_text_input(
			array(
				'id'    => Keys::PAYMENT_MSG,
				'value' => get_post_meta( $id, Keys::PAYMENT_MSG, true ),
				'label' => __( 'Message shown when not met', 'moksafocou' ),
			)
		);
	}

	/**
	 * @param array<int,string> $selected Uppercase country codes.
	 */
	private function countries_field( array $selected ): void {
		$countries = ( function_exists( 'WC' ) && WC()->countries ) ? WC()->countries->get_countries() : array();
		echo '<p class="form-field"><label for="' . esc_attr( Keys::SHIPREGION_COUNTRIES ) . '">' . esc_html__( 'Country / region', 'moksafocou' ) . '</label>';
		echo '<select id="' . esc_attr( Keys::SHIPREGION_COUNTRIES ) . '" name="' . esc_attr( Keys::SHIPREGION_COUNTRIES ) . '[]" multiple="multiple" class="wc-enhanced-select" style="width:50%;" data-placeholder="' . esc_attr__( 'Select a country / region…', 'moksafocou' ) . '">';
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
		echo '<p class="form-field"><label for="' . esc_attr( Keys::PAYMENT_METHODS ) . '">' . esc_html__( 'Payment method', 'moksafocou' ) . '</label>';
		echo '<select id="' . esc_attr( Keys::PAYMENT_METHODS ) . '" name="' . esc_attr( Keys::PAYMENT_METHODS ) . '[]" multiple="multiple" class="wc-enhanced-select" style="width:50%;" data-placeholder="' . esc_attr__( 'Select a payment method…', 'moksafocou' ) . '">';
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
		if ( ! $this->verify_save( $post_id, self::NONCE ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
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
		$this->save_text( $post_id, Keys::SCHEDULE_MSG_START );
		$this->save_text( $post_id, Keys::SCHEDULE_MSG_END );

		// Role.
		update_post_meta( $post_id, Keys::ROLE_ENABLED, isset( $_POST[ Keys::ROLE_ENABLED ] ) ? 'yes' : '' );
		$type = isset( $_POST[ Keys::ROLE_TYPE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::ROLE_TYPE ] ) ) : 'allowed';
		update_post_meta( $post_id, Keys::ROLE_TYPE, in_array( $type, array( 'allowed', 'disallowed' ), true ) ? $type : 'allowed' );
		$valid_roles = array_keys( $this->roles() );
		$roles       = ( isset( $_POST[ Keys::ROLE_LIST ] ) && is_array( $_POST[ Keys::ROLE_LIST ] ) )
			? array_values( array_intersect( array_map( 'sanitize_key', wp_unslash( $_POST[ Keys::ROLE_LIST ] ) ), $valid_roles ) )
			: array();
		update_post_meta( $post_id, Keys::ROLE_LIST, $roles );
		$this->save_text( $post_id, Keys::ROLE_MSG );

		// Cart.
		$subtotal = isset( $_POST[ Keys::MIN_SUBTOTAL ] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ Keys::MIN_SUBTOTAL ] ) ) ) : '';
		if ( '' === $subtotal ) {
			delete_post_meta( $post_id, Keys::MIN_SUBTOTAL );
		} else {
			update_post_meta( $post_id, Keys::MIN_SUBTOTAL, $subtotal );
		}
		update_post_meta( $post_id, Keys::MIN_SUBTOTAL_INCL_TAX, isset( $_POST[ Keys::MIN_SUBTOTAL_INCL_TAX ] ) ? 'yes' : '' );
		update_post_meta( $post_id, Keys::MIN_QTY, isset( $_POST[ Keys::MIN_QTY ] ) ? max( 0, absint( wp_unslash( $_POST[ Keys::MIN_QTY ] ) ) ) : 0 );
		$this->save_text( $post_id, Keys::CART_MSG );

		// Customer history.
		update_post_meta( $post_id, Keys::CUST_ENABLED, isset( $_POST[ Keys::CUST_ENABLED ] ) ? 'yes' : '' );
		update_post_meta( $post_id, Keys::CUST_FIRST_ONLY, isset( $_POST[ Keys::CUST_FIRST_ONLY ] ) ? 'yes' : '' );
		$this->save_int( $post_id, Keys::CUST_MIN_ORDERS );
		$this->save_int( $post_id, Keys::CUST_MAX_ORDERS );
		$this->save_price( $post_id, Keys::CUST_MIN_SPENT );
		$this->save_price( $post_id, Keys::CUST_MAX_SPENT );
		$this->save_text( $post_id, Keys::CUST_MSG );

		// Product / category cart-presence conditions.
		$this->save_id_list( $post_id, Keys::REQ_PRODUCTS );
		$this->save_mode( $post_id, Keys::REQ_PRODUCTS_MODE );
		$this->save_id_list( $post_id, Keys::REQ_CATEGORIES );
		$this->save_mode( $post_id, Keys::REQ_CATEGORIES_MODE );
		$this->save_text( $post_id, Keys::PRODUCT_MSG );
		$this->save_id_list( $post_id, Keys::EXCL_PRODUCTS );
		$this->save_id_list( $post_id, Keys::EXCL_CATEGORIES );
		$this->save_text( $post_id, Keys::EXCL_MSG );

		// Shipping-region condition.
		update_post_meta( $post_id, Keys::SHIPREGION_ENABLED, isset( $_POST[ Keys::SHIPREGION_ENABLED ] ) ? 'yes' : '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in save().
		$sr_mode = isset( $_POST[ Keys::SHIPREGION_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::SHIPREGION_MODE ] ) ) : 'allow';
		update_post_meta( $post_id, Keys::SHIPREGION_MODE, 'disallow' === $sr_mode ? 'disallow' : 'allow' );
		$this->save_code_list( $post_id, Keys::SHIPREGION_COUNTRIES );
		$this->save_text( $post_id, Keys::SHIPREGION_MSG );

		// Payment-method condition.
		update_post_meta( $post_id, Keys::PAYMENT_ENABLED, isset( $_POST[ Keys::PAYMENT_ENABLED ] ) ? 'yes' : '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in save().
		$pm_mode = isset( $_POST[ Keys::PAYMENT_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::PAYMENT_MODE ] ) ) : 'allow';
		update_post_meta( $post_id, Keys::PAYMENT_MODE, 'disallow' === $pm_mode ? 'disallow' : 'allow' );
		$this->save_key_list( $post_id, Keys::PAYMENT_METHODS );
		$this->save_text( $post_id, Keys::PAYMENT_MSG );

		// Day-of-week / time-of-day window.
		update_post_meta( $post_id, Keys::DAYTIME_ENABLED, isset( $_POST[ Keys::DAYTIME_ENABLED ] ) ? 'yes' : '' );
		$this->save_days( $post_id );
		$this->save_time( $post_id, Keys::DAYTIME_START );
		$this->save_time( $post_id, Keys::DAYTIME_END );
		$this->save_text( $post_id, Keys::DAYTIME_MSG );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private function save_days( int $post_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in save(); each element is cast to int and range-checked (0–6) below.
		$raw  = ( isset( $_POST[ Keys::DAYTIME_DAYS ] ) && is_array( $_POST[ Keys::DAYTIME_DAYS ] ) ) ? wp_unslash( $_POST[ Keys::DAYTIME_DAYS ] ) : array();
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

	private function save_time( int $post_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in save().
		$raw = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		if ( null === Validator::hhmm_to_min( $raw ) ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $raw );
		}
	}

	private function save_id_list( int $post_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in save(); every element is cast through absint on the next line.
		$raw = ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) ? wp_unslash( $_POST[ $key ] ) : array();
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
		if ( array() === $ids ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $ids );
		}
	}

	private function save_code_list( int $post_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in save(); each element is reduced to an uppercase 2-letter code below.
		$raw   = ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) ? wp_unslash( $_POST[ $key ] ) : array();
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

	private function save_key_list( int $post_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in save(); every element runs through sanitize_key below.
		$raw  = ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) ? wp_unslash( $_POST[ $key ] ) : array();
		$keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', $raw ) ) ) );
		if ( array() === $keys ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $keys );
		}
	}

	private function save_mode( int $post_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in save().
		$raw = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : 'any';
		update_post_meta( $post_id, $key, 'all' === $raw ? 'all' : 'any' );
	}

	private function save_int( int $post_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in save().
		$raw = isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
		if ( '' === $raw ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, (string) max( 0, (int) $raw ) );
		}
	}

	private function save_price( int $post_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in save().
		$raw = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		if ( '' === trim( $raw ) ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, wc_format_decimal( $raw ) );
		}
	}

	private function save_text( int $post_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in save().
		$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
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
