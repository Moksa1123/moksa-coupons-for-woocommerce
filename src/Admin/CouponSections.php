<?php

declare( strict_types=1 );

namespace Moksafocou\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Presentation coordinator for the plugin's coupon-settings UI.
 *
 * Each feature module describes its settings as one or more "sections" — an id, a
 * title, an optional tab class, and a render callback that echoes ONLY the inner
 * fields (no wrapping panel). This coordinator renders those sections in one of two
 * ways, chosen by the moksafocou_metaboxes_enabled option:
 *
 *  - default  : WooCommerce coupon-data tabs + panels (the original look) — one tab
 *               per section, registered per-module.
 *  - metaboxes: ONE consolidated 'Moksa coupon settings' metabox on the shop_coupon edit
 *               screen, with every section stacked inside it under its own sub-heading
 *               (matching the Advanced Coupons single-panel layout — not a box per
 *               section). Modules register independently; the coordinator gathers all
 *               their sections into the single box.
 *
 * Save logic is unchanged and stays in each module — this class only relocates the
 * input MARKUP. The fields keep the same name attributes, the same dedicated nonce,
 * and the same woocommerce_coupon_options_save handler in both modes, so a coupon
 * saves identically whichever presentation is active.
 */
final class CouponSections {

	/** The single consolidated metabox id (metabox mode). */
	private const BOX_ID = 'moksafocou_coupon_settings';

	/**
	 * Sections gathered for the consolidated metabox, one entry per module.
	 *
	 * NOTE: request-scoped static state. Safe under request-per-process runtimes
	 * (standard PHP-FPM / mod_php) where statics reset each request. Under persistent
	 * workers (Swoole / RoadRunner / FrankenPHP worker mode) this would accumulate
	 * across requests; if this plugin is ever deployed there, reset it per request.
	 *
	 * @var array<int,array{priority:int,nonce:callable,sections:callable}>
	 */
	private static array $registry = array();

	/**
	 * Whether the single add_meta_box hook has been wired yet.
	 *
	 * @var bool
	 */
	private static bool $box_hooked = false;

	/** True when the merchant opted into the metabox presentation. */
	public static function metaboxes_enabled(): bool {
		return 'yes' === get_option( 'moksafocou_metaboxes_enabled', 'no' );
	}

	/**
	 * Register a module's coupon-settings sections in whichever presentation is active.
	 *
	 * @param int      $priority     Hook / order priority — controls section ordering in
	 *                               both the tabs and the consolidated metabox.
	 * @param callable $render_nonce Echoes the module's dedicated nonce field.
	 * @param callable $sections     Returns a list of section descriptors, each shaped
	 *                               array{id:string,title:string,class?:array<int,string>,render:callable}.
	 *                               Called lazily (only on the coupon screen).
	 */
	public static function register( int $priority, callable $render_nonce, callable $sections ): void {
		if ( self::metaboxes_enabled() ) {
			self::$registry[] = array(
				'priority' => $priority,
				'nonce'    => $render_nonce,
				'sections' => $sections,
			);
			if ( ! self::$box_hooked ) {
				self::$box_hooked = true;
				add_action( 'add_meta_boxes_shop_coupon', array( self::class, 'add_box' ) );
			}
		} else {
			self::register_tabs( $priority, $render_nonce, $sections );
		}
	}

	/** Register the one consolidated metabox (metabox mode). */
	public static function add_box(): void {
		add_meta_box(
			self::BOX_ID,
			__( 'Moksa coupon settings', 'moksa-coupons-for-woocommerce' ),
			array( self::class, 'render_box' ),
			'shop_coupon',
			'normal',
			'default'
		);
	}

	/**
	 * Render the consolidated metabox as a WooCommerce-style tabbed panel: a vertical
	 * tab list on the left and one switchable panel per section on the right (matching
	 * the native coupon-data layout instead of one long stack). All module nonces are
	 * rendered up front so every section's save validates regardless of which panel is
	 * visible.
	 */
	public static function render_box(): void {
		$entries = self::$registry;
		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return $a['priority'] <=> $b['priority'];
			}
		);

		// All module nonces up front (each module verifies its own on save), then flatten
		// every module's sections into one ordered list for the tabbed UI.
		$sections = array();
		foreach ( $entries as $entry ) {
			( $entry['nonce'] )();
			foreach ( ( $entry['sections'] )() as $section ) {
				$sections[] = $section;
			}
		}

		if ( array() === $sections ) {
			echo '<p class="description">' . esc_html__( 'No coupon settings sections are enabled yet.', 'moksa-coupons-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<div class="moksafocou-panel-wrap">';

		// Left: vertical tab list. The "<id>_options" class drives the section icon
		// (shared with the native tabs); any per-section class (e.g. the BOGO JS hook)
		// rides along.
		echo '<ul class="moksafocou-settings-tabs">';
		$first = true;
		foreach ( $sections as $section ) {
			$classes = $section['id'] . '_options';
			if ( isset( $section['class'] ) ) {
				$classes .= ' ' . implode( ' ', array_map( 'sanitize_html_class', (array) $section['class'] ) );
			}
			printf(
				'<li class="%1$s%2$s"><a href="#%3$s"><span>%4$s</span></a></li>',
				esc_attr( $classes ),
				$first ? ' active' : '',
				esc_attr( $section['id'] ),
				esc_html( $section['title'] )
			);
			$first = false;
		}
		echo '</ul>';

		// Right: one panel per section; only the first is visible until a tab is clicked.
		// We use our own .moksafocou-panel class (NOT WC's .panel) so WooCommerce's
		// coupon-data tab JS never touches these.
		echo '<div class="moksafocou-settings-panels">';
		$first = true;
		foreach ( $sections as $section ) {
			printf(
				'<div id="%1$s" class="moksafocou-panel woocommerce_options_panel"%2$s>',
				esc_attr( $section['id'] ),
				$first ? ' style="display:block;"' : ''
			);
			( $section['render'] )();
			echo '</div>';
			$first = false;
		}
		echo '</div>';

		echo '</div>';
	}

	/** Original presentation: WooCommerce coupon-data tabs + panels. */
	private static function register_tabs( int $priority, callable $render_nonce, callable $sections ): void {
		add_filter(
			'woocommerce_coupon_data_tabs',
			static function ( $tabs ) use ( $sections ) {
				if ( ! is_array( $tabs ) ) {
					return $tabs;
				}
				foreach ( $sections() as $section ) {
					$tabs[ $section['id'] ] = array(
						'label'  => $section['title'],
						'target' => $section['id'],
						'class'  => isset( $section['class'] ) ? (array) $section['class'] : array(),
					);
				}
				return $tabs;
			},
			$priority
		);

		add_action( 'woocommerce_coupon_data_panels', $render_nonce, 1 );

		add_action(
			'woocommerce_coupon_data_panels',
			static function () use ( $sections ) {
				foreach ( $sections() as $section ) {
					echo '<div id="' . esc_attr( $section['id'] ) . '" class="panel woocommerce_options_panel">';
					( $section['render'] )();
					echo '</div>';
				}
			}
		);
	}
}
