<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Templates;

use Moksafocou\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * 'Coupon template' admin page, modelled on the Advanced Coupons templates UX: a single fast
 * client-rendered screen with a 'Recently used' strip, a left category sidebar that filters
 * the card grid with no reload, and a "先預填、可微調再建立" quick-configure modal (code /
 * amount / expiry) — so you tune the key values up front instead of digging into the
 * editor afterwards. Apply posts to admin-post.php, creates a draft coupon, and
 * redirects to its editor.
 */
final class TemplatePage {

	private const SLUG        = 'moksafocou-templates';
	private const CAP         = 'edit_shop_coupons';
	private const NONCE       = 'moksafocou_apply_template';
	private const ACTION      = 'moksafocou_apply_template';
	private const RECENT_META = 'moksafocou_recent_templates';
	private const RECENT_MAX  = 4;

	/** Public accessor so the AdminMenu module can reparent this page. */
	public static function slug(): string {
		return self::SLUG;
	}

	/** Legacy registration under WooCommerce (used only when AdminMenu is off). */
	public static function register(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Coupon template', 'moksafocou' ),
			__( 'Coupon template', 'moksafocou' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$notice = isset( $_GET['moksafocou_tpl_error'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_text_field( wp_unslash( (string) $_GET['moksafocou_tpl_error'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		$all    = Catalog::all();
		$by_cat = array();
		foreach ( $all as $tpl ) {
			$cat              = (string) ( $tpl['category'] ?? 'other' );
			$by_cat[ $cat ][] = $tpl;
		}
		$recent = self::recent_templates();

		echo '<div class="wrap"><div class="mowp-shell moksafocou-templates" data-ns="moksafocou">';
		echo '<div class="mowp-intro"><h1>' . esc_html__( 'Coupon template', 'moksafocou' ) . '</h1>';
		echo '<p>' . esc_html__( 'Pick a template to quickly create a coupon. Click "Apply" to first fine-tune the code, discount amount and expiry date, then create a draft coupon in one click.', 'moksafocou' ) . '</p></div>';

		if ( '' !== $notice ) {
			echo '<div class="notice notice-error is-dismissible" role="alert"><p>' . esc_html( $notice ) . '</p></div>';
		}

		// ── 最近使用 ───────────────────────────────────────────────────
		if ( ! empty( $recent ) ) {
			echo '<section class="moksafocou-tpl-recent">';
			echo '<h2 class="moksafocou-tpl-h2">' . esc_html__( 'Recently used', 'moksafocou' ) . '</h2>';
			echo '<div class="moksafocou-tpl-grid">';
			foreach ( $recent as $tpl ) {
				self::card( $tpl );
			}
			echo '</div>';
			echo '</section>';
		}

		// ── 可用範本:左分類側欄 + 右卡片 ──────────────────────────────
		echo '<section class="moksafocou-tpl-available">';
		echo '<h2 class="moksafocou-tpl-h2">' . esc_html__( 'Available templates', 'moksafocou' ) . '</h2>';
		echo '<div class="moksafocou-tpl-layout">';

		self::sidebar( $by_cat, count( $all ) );

		echo '<div class="moksafocou-tpl-main">';
		echo '<div class="moksafocou-tpl-search" style="margin:0 0 14px;">'
			. '<input type="search" class="regular-text" style="width:100%;max-width:420px;" placeholder="'
			. esc_attr__( 'Search templates (name / description)…', 'moksafocou' ) . '" aria-label="'
			. esc_attr__( 'Search templates', 'moksafocou' ) . '"></div>';
		echo '<div class="moksafocou-tpl-grid">';
		foreach ( $all as $tpl ) {
			self::card( $tpl );
		}
		echo '</div>';
		echo '<p class="moksafocou-tpl-empty" hidden>' . esc_html__( 'No matching templates found.', 'moksafocou' ) . '</p>';
		echo '</div>'; // .moksafocou-tpl-main

		echo '</div>'; // .moksafocou-tpl-layout
		echo '</section>';

		self::modal();

		echo '</div></div>'; // .mowp-shell + .wrap
	}

	/**
	 * Left category sidebar (AC-style). Filtering is client-side via data-cat, so picking
	 * a category never reloads the page.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $by_cat
	 */
	private static function sidebar( array $by_cat, int $total ): void {
		echo '<aside class="moksafocou-tpl-cats">';
		echo '<ul>';
		echo '<li><a href="#" class="current" data-filter="all">'
			. esc_html__( 'All', 'moksafocou' )
			. ' <span class="count">' . esc_html( (string) $total ) . '</span></a></li>';

		foreach ( Catalog::categories() as $cat_key => $cat_label ) {
			$items = $by_cat[ $cat_key ] ?? array();
			if ( empty( $items ) ) {
				continue;
			}
			echo '<li><a href="#" data-filter="' . esc_attr( $cat_key ) . '">'
				. esc_html( $cat_label )
				. ' <span class="count">' . esc_html( (string) count( $items ) ) . '</span></a></li>';
		}
		echo '</ul>';
		echo '</aside>';
	}

	/**
	 * @param array<string,mixed> $tpl
	 */
	private static function card( array $tpl ): void {
		$type_key = (string) ( $tpl['type_key'] ?? 'other' );
		$cat      = (string) ( $tpl['category'] ?? 'other' );
		$missing  = array();
		foreach ( Catalog::required_modules( $tpl ) as $slug ) {
			if ( ! Plugin::instance()->modules()->is_enabled( $slug ) ) {
				$missing[] = Catalog::module_label( $slug );
			}
		}
		$blocked = ! empty( $missing );

		$native      = is_array( $tpl['native'] ?? null ) ? $tpl['native'] : array();
		$amount      = (float) ( $native['amount'] ?? 0 );
		$is_native   = in_array( $type_key, array( 'percent', 'fixed_cart', 'fixed_product' ), true );
		$amount_ed   = $is_native && $amount > 0; // only show amount field where the value IS the amount.
		$unit        = ( 'percent' === $type_key ) ? '%' : get_woocommerce_currency_symbol();
		$usage_limit = isset( $native['usage_limit'] ) ? (string) (int) $native['usage_limit'] : '';
		$usage_pu    = isset( $native['usage_limit_per_user'] ) ? (string) (int) $native['usage_limit_per_user'] : '';
		$individual  = empty( $native['individual_use'] ) ? '0' : '1';
		$description = (string) ( $native['description'] ?? '' );

		$search = strtolower(
			trim(
				(string) ( $tpl['label'] ?? '' ) . ' '
				. (string) ( $tpl['desc'] ?? '' ) . ' '
				. (string) ( $tpl['id'] ?? '' ) . ' '
				. self::type_label( $type_key )
			)
		);
		echo '<div class="moksafocou-tpl-card" data-cat="' . esc_attr( $cat ) . '" data-search="' . esc_attr( $search ) . '">';
		echo '<span class="badge">' . esc_html( self::type_label( $type_key ) ) . '</span>';
		echo '<h3>' . esc_html( (string) ( $tpl['label'] ?? '' ) ) . '</h3>';
		echo '<div class="d">' . esc_html( (string) ( $tpl['desc'] ?? '' ) ) . '</div>';

		if ( $blocked ) {
			echo '<p class="req">' . esc_html(
				sprintf(
					/* translators: %s: comma-separated required module labels. */
					__( 'The "%s" module must be enabled first', 'moksafocou' ),
					implode( '、', $missing )
				)
			) . '</p>';
			echo '<button class="button" disabled>' . esc_html__( 'Apply this template', 'moksafocou' ) . '</button>';
		} else {
			printf(
				'<button type="button" class="button button-primary moksafocou-tpl-apply"'
					. ' data-id="%1$s" data-label="%2$s" data-prefix="%3$s"'
					. ' data-amount="%4$s" data-amount-editable="%5$s" data-unit="%6$s"'
					. ' data-usage-limit="%7$s" data-usage-pu="%8$s" data-individual="%9$s" data-description="%10$s">%11$s</button>',
				esc_attr( (string) ( $tpl['id'] ?? '' ) ),
				esc_attr( (string) ( $tpl['label'] ?? '' ) ),
				esc_attr( (string) ( $tpl['prefix'] ?? '' ) ),
				esc_attr( (string) $amount ),
				esc_attr( $amount_ed ? '1' : '0' ),
				esc_attr( $unit ),
				esc_attr( $usage_limit ),
				esc_attr( $usage_pu ),
				esc_attr( $individual ),
				esc_attr( $description ),
				esc_html__( 'Apply this template', 'moksafocou' )
			);
		}

		echo '</div>';
	}

	/** Shared quick-configure modal — one per page, populated by JS from the clicked card. */
	private static function modal(): void {
		echo '<div class="moksafocou-tpl-modal" hidden>';
		echo '<div class="moksafocou-tpl-backdrop"></div>';
		echo '<div class="moksafocou-tpl-dialog" role="dialog" aria-modal="true" aria-labelledby="moksafocou-tpl-modal-title">';
		echo '<h2 id="moksafocou-tpl-modal-title"></h2>';
		echo '<p class="description">' . esc_html__( 'You can adjust the following fields first, then create a coupon draft.', 'moksafocou' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="template_id" value="">';
		wp_nonce_field( self::NONCE );

		echo '<div class="moksafocou-tpl-fields">';

		echo '<div class="f"><label for="moksafocou-tpl-code">' . esc_html__( 'Coupon code', 'moksafocou' ) . '</label>';
		echo '<input type="text" id="moksafocou-tpl-code" name="code" autocomplete="off" placeholder="'
			. esc_attr__( 'Leave blank to generate automatically', 'moksafocou' ) . '"></div>';

		echo '<div class="f f-amount"><label for="moksafocou-tpl-amount">' . esc_html__( 'Discount amount', 'moksafocou' ) . ' <span class="unit"></span></label>';
		echo '<input type="number" id="moksafocou-tpl-amount" name="amount" min="0" step="0.01"></div>';

		echo '<div class="f-row">';
		echo '<div class="f"><label for="moksafocou-tpl-ul">' . esc_html__( 'Total usage limit', 'moksafocou' ) . '</label>';
		echo '<input type="number" id="moksafocou-tpl-ul" name="usage_limit" min="0" step="1" placeholder="'
			. esc_attr__( 'No limit', 'moksafocou' ) . '"></div>';
		echo '<div class="f"><label for="moksafocou-tpl-ulpu">' . esc_html__( 'Usage limit per user', 'moksafocou' ) . '</label>';
		echo '<input type="number" id="moksafocou-tpl-ulpu" name="usage_limit_per_user" min="0" step="1" placeholder="'
			. esc_attr__( 'No limit', 'moksafocou' ) . '"></div>';
		echo '</div>';

		echo '<div class="f"><label for="moksafocou-tpl-exp">' . esc_html__( 'Expiry date (optional)', 'moksafocou' ) . '</label>';
		echo '<input type="date" id="moksafocou-tpl-exp" name="date_expires"></div>';

		echo '<div class="f"><label for="moksafocou-tpl-desc">' . esc_html__( 'Coupon description (optional)', 'moksafocou' ) . '</label>';
		echo '<textarea id="moksafocou-tpl-desc" name="description" rows="2"></textarea></div>';

		echo '<div class="f f-check"><label><input type="checkbox" name="individual_use" value="yes"> '
			. esc_html__( 'Cannot be combined with other coupons', 'moksafocou' ) . '</label></div>';

		echo '</div>'; // .moksafocou-tpl-fields

		echo '<p class="moksafocou-tpl-actions">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Create coupon', 'moksafocou' ) . '</button> ';
		echo '<button type="button" class="button moksafocou-tpl-cancel">' . esc_html__( 'Cancel', 'moksafocou' ) . '</button>';
		echo '</p>';
		echo '</form>';
		echo '</div>'; // dialog
		echo '</div>'; // modal
	}

	/** admin_post handler: validate, create the draft coupon, record recent, redirect. */
	public static function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksafocou' ) );
		}
		check_admin_referer( self::NONCE );

		$template_id = isset( $_POST['template_id'] ) ? sanitize_key( (string) wp_unslash( $_POST['template_id'] ) ) : '';
		$overrides   = array(
			'code'                 => isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['code'] ) ) : '',
			'amount'               => isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['amount'] ) ) : '',
			'date_expires'         => isset( $_POST['date_expires'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date_expires'] ) ) : '',
			'usage_limit'          => isset( $_POST['usage_limit'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['usage_limit'] ) ) : '',
			'usage_limit_per_user' => isset( $_POST['usage_limit_per_user'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['usage_limit_per_user'] ) ) : '',
			'description'          => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['description'] ) ) : '',
			// Checkbox: present only when ticked → explicit yes/no so unticking also takes effect.
			'individual_use'       => isset( $_POST['individual_use'] ) ? 'yes' : 'no',
		);

		$result = Applier::apply( $template_id, $overrides );

		if ( is_wp_error( $result ) ) {
			// add_query_arg already URL-encodes the value; do not pre-encode it.
			wp_safe_redirect(
				add_query_arg( 'moksafocou_tpl_error', $result->get_error_message(), self::page_url() )
			);
			exit;
		}

		self::record_recent( $template_id );
		wp_safe_redirect( admin_url( 'post.php?post=' . (int) $result . '&action=edit' ) );
		exit;
	}

	// === Recently-used (per-user) ===

	/**
	 * Recently-applied templates for the current user, most-recent-first, filtered to
	 * templates that still exist.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function recent_templates(): array {
		$ids = get_user_meta( get_current_user_id(), self::RECENT_META, true );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $id ) {
			$tpl = Catalog::get( (string) $id );
			if ( null !== $tpl ) {
				$out[] = $tpl;
			}
		}
		return $out;
	}

	/** Push a template id onto the current user's recently-used list (dedup, capped). */
	private static function record_recent( string $template_id ): void {
		if ( '' === $template_id ) {
			return;
		}
		$user_id = get_current_user_id();
		$ids     = get_user_meta( $user_id, self::RECENT_META, true );
		$ids     = is_array( $ids ) ? array_values( array_filter( array_map( 'strval', $ids ) ) ) : array();

		array_unshift( $ids, $template_id );
		$ids = array_values( array_unique( $ids ) );
		$ids = array_slice( $ids, 0, self::RECENT_MAX );

		update_user_meta( $user_id, self::RECENT_META, $ids );
	}

	private static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	private static function type_label( string $type_key ): string {
		$labels = \Moksafocou\Support\CouponType::labels();
		return $labels[ $type_key ] ?? __( 'Other', 'moksafocou' );
	}

	private static function css(): string {
		return '.moksafocou-tpl-h2{font-size:15px;margin:22px 0 12px;color:#0f172a}'
			. '.moksafocou-tpl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px}'
			. '.moksafocou-tpl-layout{display:flex;gap:20px;align-items:flex-start}'
			. '.moksafocou-tpl-cats{flex:0 0 180px;position:sticky;top:46px}'
			. '.moksafocou-tpl-cats ul{margin:0}'
			. '.moksafocou-tpl-cats li{margin:0}'
			. '.moksafocou-tpl-cats a{display:flex;justify-content:space-between;align-items:center;text-decoration:none;color:#2c3338;padding:7px 12px;border-radius:6px;border-left:3px solid transparent}'
			. '.moksafocou-tpl-cats a:hover{background:#f0f0f1}'
			. '.moksafocou-tpl-cats a.current{background:#f0f6fc;border-left-color:#2271b1;color:#0a4b78;font-weight:600}'
			. '.moksafocou-tpl-cats .count{font-size:11px;color:#64748b;background:#f0f0f1;border-radius:9px;padding:0 7px;min-width:18px;text-align:center}'
			. '.moksafocou-tpl-cats a.current .count{background:#c5d9ed;color:#0a4b78}'
			. '.moksafocou-tpl-main{flex:1 1 auto;min-width:0}'
			. '.moksafocou-tpl-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px 20px;display:flex;flex-direction:column;transition:box-shadow .15s,border-color .15s}'
			. '.moksafocou-tpl-card:hover{border-color:#c3c4c7;box-shadow:0 1px 4px rgba(0,0,0,.06)}'
			. '.moksafocou-tpl-card .badge{display:inline-block;align-self:flex-start;font-size:12px;font-weight:600;color:#3c434a;background:#f0f0f1;border:1px solid #e2e8f0;border-radius:4px;padding:2px 8px;margin-bottom:8px}'
			. '.moksafocou-tpl-card h3{font-size:16px;margin:0 0 6px;color:#0f172a}'
			. '.moksafocou-tpl-card .d{color:#64748b;font-size:13px;line-height:1.5;flex:1;margin-bottom:14px}'
			. '.moksafocou-tpl-card .req{color:#b32d2e;font-size:12px;margin:0 0 10px}'
			. '.moksafocou-tpl-card .button{align-self:flex-start}'
			. '.moksafocou-tpl-empty{color:#64748b;padding:24px 0}'
			// modal
			// NOTE: keep display off the base rule — an explicit display here would override the
			// [hidden] attribute (UA display:none) and the modal would show on page load. Only
			// apply flex when NOT hidden so the modal stays closed until a card opens it.
			. '.moksafocou-tpl-modal{position:fixed;inset:0;z-index:100000;align-items:center;justify-content:center}'
			. '.moksafocou-tpl-modal[hidden]{display:none}'
			. '.moksafocou-tpl-modal:not([hidden]){display:flex}'
			. '.moksafocou-tpl-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.5)}'
			. '.moksafocou-tpl-dialog{position:relative;background:#fff;border-radius:8px;padding:24px 26px;width:440px;max-width:94vw;max-height:90vh;overflow:auto;box-shadow:0 8px 30px rgba(0,0,0,.25)}'
			. '.moksafocou-tpl-dialog h2{margin:0 0 4px;font-size:17px}'
			. '.moksafocou-tpl-dialog>.description{margin:0 0 6px;color:#64748b}'
			. '.moksafocou-tpl-fields .f{margin:16px 0}'
			// the fix: label is a block with breathing room above the input (was flush).
			. '.moksafocou-tpl-fields label{display:block;font-weight:600;color:#0f172a;margin-bottom:7px}'
			. '.moksafocou-tpl-fields input,.moksafocou-tpl-fields textarea{width:100%;box-sizing:border-box}'
			. '.moksafocou-tpl-fields .unit{color:#64748b;font-weight:400}'
			. '.moksafocou-tpl-fields .f-row{display:flex;gap:14px}'
			. '.moksafocou-tpl-fields .f-row .f{flex:1;margin-top:0}'
			. '.moksafocou-tpl-fields .f-check{margin:14px 0 4px}'
			. '.moksafocou-tpl-fields .f-check label{display:flex;align-items:center;gap:8px;margin:0;font-weight:500}'
			. '.moksafocou-tpl-fields .f-check input{width:auto;margin:0}'
			. '.moksafocou-tpl-actions{margin:6px 0 0}'
			. '@media(max-width:782px){.moksafocou-tpl-layout{flex-direction:column}.moksafocou-tpl-cats{position:static;flex-basis:auto;width:100%}.moksafocou-tpl-cats ul{display:flex;flex-wrap:wrap;gap:6px}}';
	}

	/** Enqueue this page's CSS (inline on the core 'common' handle) + the filter/modal JS, screen-gated. */
	public static function enqueue_admin( string $hook = '' ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::SLUG ) ) {
			return;
		}
		wp_add_inline_style( 'common', self::css() );
		$rel = 'src/Modules/Templates/assets/js/templates-admin.js';
		$ver = file_exists( MOKSAFOCOU_PLUGIN_DIR . $rel ) ? (string) filemtime( MOKSAFOCOU_PLUGIN_DIR . $rel ) : MOKSAFOCOU_VERSION;
		wp_enqueue_script( 'moksafocou-templates-admin', MOKSAFOCOU_PLUGIN_URL . $rel, array(), $ver, true );
		wp_localize_script( 'moksafocou-templates-admin', 'moksafocouTpl', array( 'autoGen' => __( 'Leave blank to generate automatically', 'moksafocou' ) ) );
	}
}
