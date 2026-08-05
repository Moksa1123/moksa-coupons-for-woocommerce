<?php

declare( strict_types=1 );

namespace Moksafocou\Settings;

use Moksafocou\Plugin;
use Moksafocou\Support\ModuleDependencies;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's OWN settings screen — a card-styled page under the 'Moksa coupon' menu,
 * NOT a tab buried under WooCommerce → Settings. Renders the module toggles grouped
 * into cards (matching the dashboard / templates look) and saves through our own
 * admin-post handler. The field ids ARE the option keys, so writes go straight to the
 * same `moksafocou_*` options every module reads — no schema indirection.
 *
 * Always-on (registered by the core Plugin) so it stays reachable even when the
 * AdminMenu module is off — otherwise you could never reach the toggle that turns the
 * independent menu on. When AdminMenu is on, Menu.php renders this same screen as a
 * submenu of the top-level menu; when off, register_fallback() puts it under WooCommerce.
 */
final class SettingsScreen {

	public const SLUG   = 'moksafocou-settings';
	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_save_settings';
	public const ACTION = 'moksafocou_save_settings';

	/** @var array<string,string> redirect query flag => notice text */
	private const NOTICES = array(
		'1' => 'Settings saved.',
	);

	public static function slug(): string {
		return self::SLUG;
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/** Fallback menu placement when the independent AdminMenu module is off. */
	public static function register_fallback(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Coupon settings', 'moksa-coupons-for-woocommerce' ),
			__( 'Coupon settings', 'moksa-coupons-for-woocommerce' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
		// The other sub-pages get their own visible WooCommerce submenu entries too, so the sidebar
		// is the single, authoritative way to move between settings pages — there is no duplicate
		// in-page page-switcher. (With the independent「Moksa …」top-level menu on, they live there.)
		foreach ( self::pages() as $key => $spec ) {
			if ( 'main' === $key ) {
				continue;
			}
			add_submenu_page( 'woocommerce', (string) $spec['label'], (string) $spec['menu'], self::CAP, (string) $spec['slug'], $spec['cb'] );
		}
	}

	/**
	 * The ordered tab list (slug => label). Every group in {@see groups()} declares which tab it
	 * belongs to; the tabs are spread across the sub-pages declared in {@see pages()} and render as
	 * pill tabs within each page (all panes stay in the DOM — JS only flips visibility).
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array(
			'core'        => __( 'Core features', 'moksa-coupons-for-woocommerce' ),
			'url'         => __( 'URL / QR', 'moksa-coupons-for-woocommerce' ),
			'admin'       => __( 'Interface and display', 'moksa-coupons-for-woocommerce' ),
			'ai'          => __( 'AI and openness', 'moksa-coupons-for-woocommerce' ),
			'frontend'    => __( 'Front-end and shipping', 'moksa-coupons-for-woocommerce' ),
			'remarketing' => __( 'Remarketing / win-back coupon', 'moksa-coupons-for-woocommerce' ),
		);
	}

	/**
	 * The settings sub-pages (page key => spec). Each is a REAL admin page — its own submenu item
	 * under the independent menu, or a hidden-but-linked page when falling back under WooCommerce —
	 * rendering only its own tabs so every page stays short. Saving is scoped per page: the form
	 * posts a `moksafocou_scope` field and {@see handle()} only writes the fields belonging to that
	 * page's tabs, so a toggle that lives on ANOTHER page is never misread as "off".
	 *
	 * @return array<string,array{slug:string,label:string,menu:string,desc:string,cb:callable,tabs:array<int,string>}>
	 */
	public static function pages(): array {
		return array(
			'main'      => array(
				'slug'  => self::SLUG,
				'label' => __( 'Feature settings', 'moksa-coupons-for-woocommerce' ),
				'menu'  => __( 'Settings', 'moksa-coupons-for-woocommerce' ),
				'desc'  => __( 'Choose the coupon features you want to enable. Click a section heading to collapse it, so you can focus on the settings you need.', 'moksa-coupons-for-woocommerce' ),
				'cb'    => array( self::class, 'render' ),
				'tabs'  => array( 'core', 'url', 'admin', 'ai' ),
			),
			'marketing' => array(
				'slug'  => self::SLUG . '-marketing',
				'label' => __( 'Front-end and marketing', 'moksa-coupons-for-woocommerce' ),
				'menu'  => __( 'Front-end and marketing', 'moksa-coupons-for-woocommerce' ),
				'desc'  => __( 'Display coupons on the front end, send them to customers, and automatically issue win-back coupons after an order is completed.', 'moksa-coupons-for-woocommerce' ),
				'cb'    => array( self::class, 'render_marketing' ),
				'tabs'  => array( 'frontend', 'remarketing' ),
			),
		);
	}

	/** Admin URL of one settings sub-page (unknown key falls back to the main page). */
	public static function page_url( string $page ): string {
		$pages = self::pages();
		$slug  = (string) ( $pages[ $page ]['slug'] ?? self::SLUG );
		return admin_url( 'admin.php?page=' . $slug );
	}

	/**
	 * Grouped setting definitions — single source for both render and save. Each field:
	 * id (option key) / type (checkbox|text|select) / default / title / desc / options.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function groups(): array {
		return array(
			array(
				'tab'    => 'core',
				'title'  => __( 'Core discount features', 'moksa-coupons-for-woocommerce' ),
				'desc'   => __( 'The coupon\'s discount mechanics and checkout validation. Each is disabled by default; enable only what you need.', 'moksa-coupons-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafocou_conditions_enabled', __( 'Coupon conditions', 'moksa-coupons-for-woocommerce' ), __( 'Add conditions to the coupon edit page such as schedule, role restrictions, minimum cart total, products / categories, shipping region, payment method, and day / time slots, and check them automatically at checkout.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_advrules_enabled', __( 'Advanced rules (AND/OR)', 'moksa-coupons-for-woocommerce' ), __( 'An advanced rule builder that freely combines conditions with groups and AND/OR (subtotal / item count / products / categories / region / payment / role / weekday / time), gatekeeping beyond individual conditions.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_discountcap_enabled', __( 'Maximum discount', 'moksa-coupons-for-woocommerce' ), __( 'Set a maximum discount amount for percentage-discount coupons (e.g. 20% off, but at most 500).', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_discounttiers_enabled', __( 'Tiered discount', 'moksa-coupons-for-woocommerce' ), __( 'Give the same percentage coupon different discounts based on cart thresholds (subtotal / item count) (e.g. 10% off below 1000, 20% off at 1000 or more); can be limited to specific products / categories.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_shipping_enabled', __( 'Shipping discount / free shipping', 'moksa-coupons-for-woocommerce' ), __( 'Adjust shipping when the coupon is applied: free shipping, a shipping discount, or a fixed amount off (this replaces the shipping cost of all original shipping methods).', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_freegift_enabled', __( 'Add-on / free gift', 'moksa-coupons-for-woocommerce' ), __( 'Automatically add a specified gift to the cart when the coupon is applied (free or discounted); customers cannot change the quantity or remove it.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_bogo_enabled', __( 'Buy X Get Y (BOGO)', 'moksa-coupons-for-woocommerce' ), __( 'Add a "Buy X Get Y" discount type: buy a set quantity of specified products / categories to get the gift free / discounted. Can be created with natural language.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_nthitem_enabled', __( 'Nth-item discount', 'moksa-coupons-for-woocommerce' ), __( 'Add an "Nth-item discount" type: for the same group of products (or the whole store), every N items qualifies the Nth item for free / percentage / fixed-per-item discount (e.g. 40% off the second item), repeatable. Can be created with natural language.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_mixmatch_enabled', __( 'Mix & Match', 'moksa-coupons-for-woocommerce' ), __( 'Add a "Mix & Match" discount type: for a specified group of products (or the whole store), pick any N items settled at a fixed group total or a group percentage discount (e.g. any 3 for $299), repeatable. Can be created with natural language.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_stacking_enabled', __( 'Stacking control', 'moksa-coupons-for-woocommerce' ), __( 'Control whether the coupon can be combined with other coupons: mutually exclusive coupons, allow / deny lists of coupon codes, checked automatically at checkout.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_autoapply_enabled', __( 'Auto-apply coupon', 'moksa-coupons-for-woocommerce' ), __( 'Coupons with "Auto-apply" checked are added automatically once the customer\'s cart meets the conditions, with no code to enter.', 'moksa-coupons-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'url',
				'title'  => __( 'URL / QR apply', 'moksa-coupons-for-woocommerce' ),
				'desc'   => __( 'Let customers apply coupons with one tap using a dedicated link, QR, or query string.', 'moksa-coupons-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafocou_url_enabled', __( 'Coupon URL / QR', 'moksa-coupons-for-woocommerce' ), __( 'Generate a dedicated link /coupon/code and a server-side QR code for the coupon; customers apply it automatically by clicking or scanning.', 'moksa-coupons-for-woocommerce' ) ),
					array(
						'id'       => 'moksafocou_url_endpoint',
						'type'     => 'text',
						'default'  => 'coupon',
						'sanitize' => 'slug',
						'title'    => __( 'Coupon URL path', 'moksa-coupons-for-woocommerce' ),
						'desc'     => __( 'The leading path segment of the dedicated link, e.g. coupon → /coupon/code. Permalinks are refreshed after a change.', 'moksa-coupons-for-woocommerce' ),
					),
					self::toggle( 'moksafocou_url_query_enabled', __( 'Allow apply via query string', 'moksa-coupons-for-woocommerce' ), __( 'Allow appending ?coupon=code to any page URL to apply the coupon. Disabled by default.', 'moksa-coupons-for-woocommerce' ) ),
					array(
						'id'      => 'moksafocou_url_query_redirect',
						'type'    => 'select',
						'default' => 'same_page',
						'title'   => __( 'Redirect after query-string apply', 'moksa-coupons-for-woocommerce' ),
						'desc'    => __( 'Where to redirect the customer after a successful ?coupon= apply.', 'moksa-coupons-for-woocommerce' ),
						'options' => array(
							'same_page' => __( 'Original page', 'moksa-coupons-for-woocommerce' ),
							'cart'      => __( 'Cart', 'moksa-coupons-for-woocommerce' ),
							'checkout'  => __( 'Checkout page', 'moksa-coupons-for-woocommerce' ),
						),
					),
				),
			),
			array(
				'tab'    => 'admin',
				'title'  => __( 'Admin interface and display', 'moksa-coupons-for-woocommerce' ),
				'desc'   => __( 'Layout and display of the admin menu, templates, reports, and the coupon edit page.', 'moksa-coupons-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafocou_adminmenu_enabled', __( 'Standalone coupon management menu', 'moksa-coupons-for-woocommerce' ), __( 'Move coupon management into a standalone top-level menu "Moksa Coupon" (All coupons / Add / Reports / Settings), no longer buried under WooCommerce.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_templates_enabled', __( 'Coupon template', 'moksa-coupons-for-woocommerce' ), __( 'Add a "Coupon templates" page offering ready-made coupon templates (new-customer first purchase / spend-threshold discount / free shipping / buy two get one…) to create draft coupons in one click.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_reports_enabled', __( 'Coupon report', 'moksa-coupons-for-woocommerce' ), __( 'Track each coupon\'s orders used and total discount (embedded in the dashboard when the standalone menu is enabled).', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_tabicons_enabled', __( 'Coupon settings icon', 'moksa-coupons-for-woocommerce' ), __( 'Add uniform single-color icons to each settings section of the coupon edit page (schedule / role / cart / shipping…), suitable for both the tabbed and centralized panel layouts.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_metaboxes_enabled', __( 'Standalone settings panel', 'moksa-coupons-for-woocommerce' ), __( 'Consolidate the coupon\'s settings sections (conditions / BOGO / gift / shipping / stacking / URL / front end) into a single WooCommerce-native tabbed settings panel.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_couponlist_enabled', __( 'Coupon list tools', 'moksa-coupons-for-woocommerce' ), __( 'Add management tools to the coupon list such as an enable / disable status column, batch enable/disable, and one-click copy.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_importexport_enabled', __( 'Import / Export (CSV)', 'moksa-coupons-for-woocommerce' ), __( 'Import / export coupons via CSV (including tiers, advanced rules JSON, and campaigns), for easy backup, auditing, and batch editing.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_summary_enabled', __( 'Live summary on the edit page', 'moksa-coupons-for-woocommerce' ), __( 'Show a live summary panel on the coupon edit page: discount mechanics, enabled features, and conflict warnings, updating in real time as you type.', 'moksa-coupons-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'frontend',
				'title'  => __( 'Front-end and shipping', 'moksa-coupons-for-woocommerce' ),
				'desc'   => __( 'Display available coupons on the front end, or send coupons to customers.', 'moksa-coupons-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafocou_frontend_enabled', __( 'Front-end coupon display', 'moksa-coupons-for-woocommerce' ), __( 'Provide a [moksafocou_coupons] shortcode that displays the available coupons the merchant selected as cards on the front end (with copy and apply).', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_savings_enabled', __( 'Cart savings hint', 'moksa-coupons-for-woocommerce' ), __( 'Show a "You saved a total of NT$X" hint in the cart / checkout to reinforce the sense of the discount.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_nudge_enabled', __( 'Free-shipping threshold hint', 'moksa-coupons-for-woocommerce' ), __( 'Show "Spend NT$X more for free shipping" in the cart / checkout to drive up order value (reads the store\'s configured free-shipping threshold).', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_send_enabled', __( 'Coupon delivery', 'moksa-coupons-for-woocommerce' ), __( 'Add a "Send coupon" ability: send a coupon to a customer\'s email using natural language, and optionally lock it to that email only.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_myaccount_enabled', __( 'My Account coupons', 'moksa-coupons-for-woocommerce' ), __( 'Add a "My coupons" tab under WooCommerce "My Account" that lists the dedicated coupons issued to that customer (bound to their account or email), which can be copied or applied in one click.', 'moksa-coupons-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'remarketing',
				'title'  => __( 'Remarketing / win-back coupon', 'moksa-coupons-for-woocommerce' ),
				'desc'   => __( 'After an order is completed, automatically copy a "template coupon" into a customer-specific coupon (reusing the template\'s discount and all conditions), place it in the customer\'s My Account, and optionally send it out to encourage repeat purchases. You must also enable "My Account coupons" above for it to be visible.', 'moksa-coupons-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafocou_remarketing_enabled', __( 'Enable automatic coupon issuance after an order is completed', 'moksa-coupons-for-woocommerce' ), __( 'When an order\'s status changes to "Completed", issue a win-back coupon according to the settings below.', 'moksa-coupons-for-woocommerce' ) ),
					array(
						'id'      => 'moksafocou_remarketing_source',
						'type'    => 'text',
						'default' => '',
						'title'   => __( 'Template coupon code', 'moksa-coupons-for-woocommerce' ),
						'desc'    => __( 'First create a normal coupon as the "template" (any coupon type / conditions), and enter its code here. The system copies it into a customer-specific unique coupon after the order is completed. We recommend setting the template to "Limit to one use per customer".', 'moksa-coupons-for-woocommerce' ),
					),
					array(
						'id'      => 'moksafocou_remarketing_condition',
						'type'    => 'select',
						'default' => 'all',
						'title'   => __( 'Issuance conditions', 'moksa-coupons-for-woocommerce' ),
						'desc'    => __( 'Which completed orders should issue a coupon.', 'moksa-coupons-for-woocommerce' ),
						'options' => array(
							'all'         => __( 'Every completed order', 'moksa-coupons-for-woocommerce' ),
							'first_order' => __( 'Only the customer\'s first order (customer must be logged in)', 'moksa-coupons-for-woocommerce' ),
							'min_total'   => __( 'Order amount reaches a threshold', 'moksa-coupons-for-woocommerce' ),
						),
					),
					array(
						'id'      => 'moksafocou_remarketing_min_total',
						'type'    => 'text',
						'default' => '0',
						'title'   => __( 'Order amount threshold', 'moksa-coupons-for-woocommerce' ),
						'desc'    => __( 'Takes effect when the issuance condition is "reaches a threshold": the order total must be ≥ this amount.', 'moksa-coupons-for-woocommerce' ),
					),
					array(
						'id'      => 'moksafocou_remarketing_expiry_days',
						'type'    => 'text',
						'default' => '30',
						'title'   => __( 'Win-back coupon validity days', 'moksa-coupons-for-woocommerce' ),
						'desc'    => __( 'How many days it is valid after being issued (counted from the moment of issuance). Enter 0 = use the template\'s own expiry settings.', 'moksa-coupons-for-woocommerce' ),
					),
					self::toggle( 'moksafocou_remarketing_email', __( 'Also notify the customer by email', 'moksa-coupons-for-woocommerce' ), __( 'When the coupon is issued, also send the customer an email containing the code and a one-click apply link (requires working site email settings).', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_expiry_enabled', __( 'Coupon expiry reminder', 'moksa-coupons-for-woocommerce' ), __( 'Check customers\' dedicated coupons automatically every day, and email a reminder when they are about to expire, encouraging use before the deadline.', 'moksa-coupons-for-woocommerce' ) ),
					array(
						'id'      => 'moksafocou_expiry_days',
						'type'    => 'text',
						'default' => '3',
						'title'   => __( 'Days before expiry to remind', 'moksa-coupons-for-woocommerce' ),
						'desc'    => __( 'Send the reminder a number of days before the coupon\'s expiry date (1–60, default 3).', 'moksa-coupons-for-woocommerce' ),
					),
				),
			),
			array(
				'tab'    => 'ai',
				'title'  => __( 'AI and external openness (MCP)', 'moksa-coupons-for-woocommerce' ),
				'desc'   => __( 'Create / query coupons with natural language in the admin, and optionally open them up to external AI tools. All disabled by default; enable when needed.', 'moksa-coupons-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafocou_ai_enabled', __( 'AI coupon assistant', 'moksa-coupons-for-woocommerce' ), __( 'Create / query coupons with natural language in the admin (requires the AI Client of WordPress 7.0+ and a configured Connector).', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_mcp_server_enabled', __( 'External MCP server', 'moksa-coupons-for-woocommerce' ), __( 'Open up coupon capabilities to external AI tools (MCP). Disabled by default.', 'moksa-coupons-for-woocommerce' ) ),
					self::toggle( 'moksafocou_mcp_expose_destructive', __( 'Allow external MCP to make changes', 'moksa-coupons-for-woocommerce' ), __( 'Allow destructive capabilities (create / update / delete coupons) to be exposed to external MCP. Disabled by default (read-only).', 'moksa-coupons-for-woocommerce' ) ),
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function toggle( string $id, string $title, string $desc ): array {
		return array(
			'id'      => $id,
			'type'    => 'checkbox',
			'default' => 'no',
			'title'   => $title,
			'desc'    => $desc,
		);
	}

	/**
	 * Whether the module behind a `moksafocou_<key>_enabled` toggle exists in this build. Lets a
	 * trimmed package (a deferred module's files excluded) hide the toggle instead of showing a
	 * control that can never boot. Non-module options (no matching registry key) always pass.
	 */
	private static function module_available( string $option ): bool {
		if ( ! str_starts_with( $option, 'moksafocou_' ) || ! str_ends_with( $option, '_enabled' ) ) {
			return true;
		}
		$key     = substr( $option, 12, -8 ); // strip 'moksafocou_' (12) + '_enabled' (8).
		$modules = Plugin::instance()->modules()->all();
		return ! isset( $modules[ $key ] ) || class_exists( $modules[ $key ] );
	}

	public static function render(): void {
		self::render_page( 'main' );
	}

	public static function render_marketing(): void {
		self::render_page( 'marketing' );
	}

	/** Render one settings sub-page: intro + page switcher + this page's pill tabs / panes + save. */
	private static function render_page( string $page ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$pages = self::pages();
		$spec  = $pages[ $page ] ?? $pages['main'];

		echo '<div class="wrap moksafocou-settings-screen">';

		$flag = isset( $_GET['moksafocou_saved'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['moksafocou_saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( self::NOTICES[ $flag ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'moksa-coupons-for-woocommerce' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="moksafocou_scope" value="' . esc_attr( $page ) . '">';
		wp_nonce_field( self::NONCE );

		echo '<div class="mowp-shell" data-ns="moksa-coupons-for-woocommerce">';
		echo '<div class="mowp-intro"><h1>' . esc_html( (string) $spec['label'] ) . '</h1>'
			. '<p>' . esc_html( (string) $spec['desc'] ) . '</p></div>';

		self::dependency_notices();

		// Only this page's tabs render here; bucket the groups by their tab.
		$tabs   = array_intersect_key( self::tabs(), array_flip( (array) $spec['tabs'] ) );
		$by_tab = array_fill_keys( array_keys( $tabs ), array() );
		foreach ( self::groups() as $group ) {
			$tab = (string) ( $group['tab'] ?? '' );
			if ( isset( $by_tab[ $tab ] ) ) {
				$by_tab[ $tab ][] = $group;
			}
		}

		// Pill tabs (JS flips [data-pane] visibility; every pane of THIS page stays in the DOM
		// inside the single form, and handle() saves only this page's fields via moksafocou_scope).
		if ( count( $tabs ) > 1 ) {
			echo '<div class="mowp-tabs nav-tab-wrapper">';
			$first = true;
			foreach ( $tabs as $slug => $label ) {
				echo '<a href="#" class="nav-tab' . ( $first ? ' nav-tab-active' : '' ) . '" data-tab="' . esc_attr( $slug ) . '">'
					. esc_html( $label ) . '</a>';
				$first = false;
			}
			echo '</div>';
		}

		$first = true;
		foreach ( $tabs as $slug => $label ) {
			echo '<div class="mowp-pane" data-pane="' . esc_attr( $slug ) . '"' . ( $first ? '' : ' style="display:none"' ) . '>';
			foreach ( $by_tab[ $slug ] as $group ) {
				self::section_card( $group );
			}
			echo '</div>';
			$first = false;
		}

		echo '<p class="submit mowp-save"><button type="submit" class="button button-primary button-hero">'
			. esc_html__( 'Save settings', 'moksa-coupons-for-woocommerce' ) . '</button></p>';
		echo '</div>'; // .mowp-shell
		echo '</form>';

		echo '</div>';
	}

	/** Warn when an enabled module's recommended companion module is off (soft advisory, not a block). */
	private static function dependency_notices(): void {
		$gaps = ModuleDependencies::unmet( Plugin::instance()->modules() );
		foreach ( $gaps as $gap ) {
			echo '<div class="mowp-note mowp-note--warn">' . esc_html(
				sprintf(
					/* translators: 1: enabled module name, 2: comma-separated names of the modules it needs. */
					__( '"%1$s" is enabled; we recommend also enabling "%2$s", otherwise some features will not take effect.', 'moksa-coupons-for-woocommerce' ),
					(string) $gap['module'],
					implode( '、', $gap['missing'] )
				)
			) . '</div>';
		}
	}

	/**
	 * Render one group as a collapsible mowp section-card: module toggles become iOS-toggle cards,
	 * other fields render as consistent field rows (order preserved).
	 *
	 * @param array<string,mixed> $group
	 */
	private static function section_card( array $group ): void {
		$fields = is_array( $group['fields'] ?? null ) ? $group['fields'] : array();
		// Skip a section whose every field is for a module absent from this (possibly trimmed) build.
		$visible = array_filter( $fields, static fn( $f ): bool => self::module_available( (string) ( $f['id'] ?? '' ) ) );
		if ( array() === $visible ) {
			return;
		}
		$title = (string) ( $group['title'] ?? '' );
		// A section with many value fields (the display / appearance walls) starts collapsed so the
		// page reads as a tidy accordion instead of one endless scroll; the state then persists per
		// user. Toggle-only sections stay open. An explicit 'collapsed' flag on the group overrides.
		$value_fields = 0;
		foreach ( $visible as $f ) {
			if ( 'checkbox' !== (string) ( $f['type'] ?? 'checkbox' ) ) {
				++$value_fields;
			}
		}
		$collapsed = array_key_exists( 'collapsed', $group ) ? (bool) $group['collapsed'] : ( $value_fields >= 6 );
		echo '<section class="mowp-section-card' . ( $collapsed ? ' is-collapsed' : '' ) . '" data-key="' . esc_attr( $title ) . '">';
		echo '<div class="mowp-section-card__head"><span class="mowp-section-card__title">' . esc_html( $title )
			. '</span><span class="mowp-section-card__chev" aria-hidden="true"></span></div>';
		echo '<div class="mowp-section-card__body">';
		if ( ! empty( $group['desc'] ) ) {
			echo '<p class="mowp-section-card__desc">' . esc_html( (string) $group['desc'] ) . '</p>';
		}
		foreach ( $visible as $field ) {
			if ( 'checkbox' === (string) ( $field['type'] ?? 'checkbox' ) ) {
				self::toggle_card( $field );
			} else {
				self::field_row( $field );
			}
		}
		echo '</div></section>';
	}

	/**
	 * A module on/off toggle as a mowp card (name + description + iOS switch).
	 *
	 * @param array<string,mixed> $field
	 */
	private static function toggle_card( array $field ): void {
		$id = (string) ( $field['id'] ?? '' );
		if ( ! self::module_available( $id ) ) {
			return;
		}
		$title   = (string) ( $field['title'] ?? '' );
		$desc    = (string) ( $field['desc'] ?? '' );
		$enabled = 'yes' === get_option( $id, $field['default'] ?? 'no' );
		echo '<div class="mowp-card' . ( $enabled ? ' is-on' : '' ) . '">';
		echo '<div class="mowp-card__main">';
		echo '<div class="mowp-card__head"><span class="mowp-card__name">' . esc_html( $title ) . '</span></div>';
		if ( '' !== $desc ) {
			echo '<div class="mowp-card__tagline">' . esc_html( $desc ) . '</div>';
		}
		echo '</div>';
		echo '<div class="mowp-card__action"><label class="mowp-toggle">'
			. '<input type="checkbox" name="' . esc_attr( $id ) . '" value="yes"' . checked( $enabled, true, false ) . '>'
			. '<span class="mowp-toggle__slider"></span></label></div>';
		echo '</div>';
	}

	/**
	 * A non-toggle config field (text / select) as a consistent mowp field row.
	 *
	 * @param array<string,mixed> $field
	 */
	private static function field_row( array $field ): void {
		$id = (string) ( $field['id'] ?? '' );
		if ( ! self::module_available( $id ) ) {
			return;
		}
		$type  = (string) ( $field['type'] ?? 'text' );
		$title = (string) ( $field['title'] ?? '' );
		$desc  = (string) ( $field['desc'] ?? '' );
		$value = get_option( $id, $field['default'] ?? '' );

		echo '<div class="mowp-field mowp-field--' . esc_attr( $type ) . '">';
		echo '<label class="mowp-field__label" for="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</label>';
		if ( '' !== $desc ) {
			echo '<span class="mowp-field__desc">' . esc_html( $desc ) . '</span>';
		}
		if ( 'select' === $type ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
			$options = is_array( $field['options'] ?? null ) ? $field['options'] : array();
			foreach ( $options as $opt_val => $opt_label ) {
				echo '<option value="' . esc_attr( (string) $opt_val ) . '"' . selected( (string) $value, (string) $opt_val, false ) . '>'
					. esc_html( (string) $opt_label ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		echo '</div>';
	}

	/** admin_post handler: verify, whitelist-save the submitting sub-page's fields, redirect with notice. */
	public static function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksa-coupons-for-woocommerce' ) );
		}
		check_admin_referer( self::NONCE );

		// 存檔範圍:表單只送出自己那個子頁的欄位,所以只寫該頁 tabs 底下的欄位 —
		// 其他子頁上「沒被送出的 checkbox」才不會被誤存成關閉。
		$pages = self::pages();
		$scope = isset( $_POST['moksafocou_scope'] ) ? sanitize_key( wp_unslash( (string) $_POST['moksafocou_scope'] ) ) : 'main';
		if ( ! isset( $pages[ $scope ] ) ) {
			$scope = 'main';
		}
		$scope_tabs = (array) $pages[ $scope ]['tabs'];

		foreach ( self::groups() as $group ) {
			if ( ! in_array( (string) ( $group['tab'] ?? '' ), $scope_tabs, true ) ) {
				continue; // Lives on another sub-page → not part of this submit.
			}
			$fields = is_array( $group['fields'] ?? null ) ? $group['fields'] : array();
			foreach ( $fields as $field ) {
				$fid   = (string) ( $field['id'] ?? '' );
				$value = ( '' !== $fid && isset( $_POST[ $fid ] ) ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $fid ] ) ) : null;
				self::save_field( $field, $value );
			}
		}

		wp_safe_redirect( add_query_arg( 'moksafocou_saved', '1', self::page_url( $scope ) ) );
		exit;
	}

	/**
	 * @param array<string,mixed> $field
	 * @param string|null         $value Submitted, already-sanitized value for this field (null if absent from POST).
	 */
	private static function save_field( array $field, ?string $value ): void {
		$id   = (string) ( $field['id'] ?? '' );
		$type = (string) ( $field['type'] ?? 'checkbox' );
		if ( '' === $id ) {
			return;
		}

		if ( 'checkbox' === $type ) {
			// Unchecked boxes are absent from POST (value null) → store 'no'.
			update_option( $id, 'yes' === $value ? 'yes' : 'no' );
			return;
		}

		$raw = (string) ( $value ?? '' );

		if ( 'select' === $type ) {
			$options = is_array( $field['options'] ?? null ) ? $field['options'] : array();
			$clean   = array_key_exists( $raw, $options ) ? $raw : (string) ( $field['default'] ?? '' );
			update_option( $id, $clean );
			return;
		}

		// text
		$clean = ( 'slug' === ( $field['sanitize'] ?? '' ) ) ? sanitize_title( $raw ) : sanitize_text_field( $raw );
		if ( '' === $clean ) {
			$clean = (string) ( $field['default'] ?? '' );
		}
		update_option( $id, $clean );
	}

	/** Enqueue the shared Moksa settings-UI CSS + behaviour JS on this page (no raw <style>/<script>). */
	public static function enqueue_admin( string $hook = '' ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::SLUG ) ) {
			return;
		}
		wp_add_inline_style( 'common', SettingsUi::css() );
		wp_register_script( 'moksafocou-settings-ui', false, array(), '1.0.0', true );
		wp_enqueue_script( 'moksafocou-settings-ui' );
		wp_add_inline_script( 'moksafocou-settings-ui', SettingsUi::js() );
	}
}
