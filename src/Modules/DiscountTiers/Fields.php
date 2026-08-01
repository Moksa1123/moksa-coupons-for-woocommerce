<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\DiscountTiers;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Admin\FieldsSaveGuard;
use Moksafocou\Support\Tiers;
use Moksafocou\Admin\FieldsHelpers;

defined( 'ABSPATH' ) || exit;

/**
 * 'Tiered discount' coupon edit-screen tab: one percent coupon, different percent-off per cart tier,
 * optionally limited to chosen products / categories. Dedicated nonce. Rows are added/removed
 * with a small enqueued script (graceful no-JS fallback: the saved rows + a few starter rows
 * still render and submit); blank rows are dropped on save. The engine has no row cap.
 */
final class Fields {

	use FieldsSaveGuard;

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_tiers_nonce';

	private function action( int $id ): string {
		return 'moksafocou_save_tiers_coupon_' . $id;
	}

	/**
	 * @return array<int,array{id:string,title:string,render:callable}>
	 */
	public function sections(): array {
		return array(
			array(
				'id'     => 'moksafocou_tiers',
				'title'  => __( 'Tiered discount', 'moksafocou' ),
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
		$id    = (int) $post->ID;
		$tiers = Tiers::parse( (string) get_post_meta( $id, Keys::TIERS, true ) );

		echo '<p class="description" style="margin:8px 0;">'
			. esc_html__( 'The same coupon gives different discounts by tier according to the "Tier basis" (cart subtotal / quantity / weight); each tier can be a percentage or a fixed amount, and they can be mixed. Once enabled, the discount is calculated entirely from the table below and the coupon\'s original discount amount is ignored.', 'moksafocou' )
			. '</p>';

		woocommerce_wp_checkbox(
			array(
				'id'          => Keys::TIERS_ENABLED,
				'value'       => get_post_meta( $id, Keys::TIERS_ENABLED, true ),
				'label'       => __( 'Enable tiered discount', 'moksafocou' ),
				'description' => __( 'When checked, this coupon calculates the discount according to the tiers below.', 'moksafocou' ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'      => Keys::TIERS_BASIS,
				'value'   => Tiers::basis( get_post_meta( $id, Keys::TIERS_BASIS, true ) ),
				'label'   => __( 'Tier basis', 'moksafocou' ),
				'options' => array(
					'subtotal' => __( 'Cart subtotal', 'moksafocou' ),
					'quantity' => __( 'Cart quantity', 'moksafocou' ),
					'weight'   => __( 'Cart weight (kg)', 'moksafocou' ),
				),
			)
		);

		echo '<div class="moksafocou-tiers-builder">';
		echo '<table class="widefat striped moksafocou-tiers-table" style="margin:8px 0;width:100%;">'
			. '<caption class="screen-reader-text">' . esc_html__( 'Tiered discount table', 'moksafocou' ) . '</caption><thead><tr>'
			. '<th scope="col" style="width:42px;">' . esc_html__( 'Tier', 'moksafocou' ) . '</th>'
			. '<th scope="col" class="moksafocou-tier-th-threshold">' . esc_html__( 'Threshold ≥ (per the basis above)', 'moksafocou' ) . '</th>'
			. '<th scope="col" style="width:120px;">' . esc_html__( 'Discount type', 'moksafocou' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Discount amount', 'moksafocou' ) . '</th>'
			. '<th scope="col" style="width:36px;"><span class="screen-reader-text">' . esc_html__( 'Action', 'moksafocou' ) . '</span></th>'
			. '</tr></thead><tbody class="moksafocou-tiers-rows">';

		$render_rows = $tiers;
		if ( array() === $render_rows ) {
			$initial = max( 1, (int) apply_filters( 'moksafocou_tiers_ui_initial_rows', 3 ) );
			for ( $i = 0; $i < $initial; $i++ ) {
				$render_rows[] = array(
					'threshold' => '',
					'kind'      => 'percent',
					'value'     => '',
				);
			}
		}
		$num = 1;
		foreach ( $render_rows as $row ) {
			$this->print_tier_row( $num, $row );
			++$num;
		}
		echo '</tbody></table>';
		echo '<p style="margin:0 0 10px;"><button type="button" class="button moksafocou-tier-add">'
			. esc_html__( '+ Add tier', 'moksafocou' ) . '</button></p>';
		// Inert template the script clones to add a row (its contents are never submitted).
		echo '<template class="moksafocou-tier-template">';
		$this->print_tier_row(
			0,
			array(
				'threshold' => '',
				'kind'      => 'percent',
				'value'     => '',
			)
		);
		echo '</template>';
		echo '</div>';
		echo '<p class="description" style="margin:0 0 8px;">'
			. esc_html__( 'The tier applies only when the threshold is reached; when multiple tiers match, the one with the "largest discount amount" is used. Leave the discount amount empty = that tier is disabled. Enter 10 for percentage = 10% off; enter 200 for fixed amount = 200 off.', 'moksafocou' )
			. '</p>';

		woocommerce_wp_select(
			array(
				'id'      => Keys::TIERS_TARGET_MODE,
				'value'   => (string) get_post_meta( $id, Keys::TIERS_TARGET_MODE, true ),
				'label'   => __( 'Discount application scope', 'moksafocou' ),
				'options' => array(
					'all'        => __( 'Whole cart', 'moksafocou' ),
					'products'   => __( 'Specific products only', 'moksafocou' ),
					'categories' => __( 'Specific categories only', 'moksafocou' ),
				),
			)
		);
		FieldsHelpers::product_select( Keys::TIERS_TARGET_PRODUCTS, __( 'Specific products', 'moksafocou' ), FieldsHelpers::int_list( get_post_meta( $id, Keys::TIERS_TARGET_PRODUCTS, true ) ) );
		FieldsHelpers::category_select( Keys::TIERS_TARGET_CATEGORIES, __( 'Specific categories', 'moksafocou' ), FieldsHelpers::int_list( get_post_meta( $id, Keys::TIERS_TARGET_CATEGORIES, true ) ) );
		echo '<p class="description" style="margin:8px 0;">'
			. esc_html__( 'The threshold is always judged by the whole cart\'s "Tier basis"; the "Discount application scope" only determines which products the discount applies to (a fixed amount is distributed proportionally across the products in scope). For user role restrictions, use the "Conditions" tab.', 'moksafocou' )
			. '</p>';
	}

	/**
	 * Print one tier row. Index 0 is used for the JS clone template; the script renumbers
	 * on add/remove. Values are escaped; empty / zero thresholds render blank.
	 *
	 * @param int                                       $index Display index (1-based; 0 for the template).
	 * @param array{threshold:mixed,kind:mixed,value:mixed} $row Tier row.
	 */
	private function print_tier_row( int $index, array $row ): void {
		$threshold = ( '' === $row['threshold'] || 0.0 === (float) $row['threshold'] ) ? '' : (string) $row['threshold'];
		$kind      = ( ( $row['kind'] ?? 'percent' ) === 'fixed' ) ? 'fixed' : 'percent';
		$value     = ( '' === $row['value'] ) ? '' : (string) $row['value'];
		printf(
			'<tr class="moksafocou-tier-row"><td class="moksafocou-tier-idx">%1$d</td>'
			. '<td><input type="number" min="0" step="0.01" name="moksafocou_tier_threshold[]" value="%2$s" aria-label="%10$s" /></td>'
			. '<td><select name="moksafocou_tier_kind[]" aria-label="%11$s">'
			. '<option value="percent"%3$s>%4$s</option>'
			. '<option value="fixed"%5$s>%6$s</option></select></td>'
			. '<td><input type="number" min="0" step="0.01" name="moksafocou_tier_value[]" value="%7$s" placeholder="%8$s" aria-label="%12$s" /></td>'
			. '<td class="moksafocou-tier-actions"><button type="button" class="button-link moksafocou-tier-remove" title="%9$s" aria-label="%9$s">&times;</button></td></tr>',
			(int) $index,
			esc_attr( $threshold ),
			selected( $kind, 'percent', false ),
			esc_html__( 'Percentage %', 'moksafocou' ),
			selected( $kind, 'fixed', false ),
			esc_html__( 'Fixed amount', 'moksafocou' ),
			esc_attr( $value ),
			esc_attr__( '10 = 10% off / 200 = 200 off', 'moksafocou' ),
			esc_attr__( 'Delete this tier', 'moksafocou' ),
			esc_attr__( 'Threshold', 'moksafocou' ),
			esc_attr__( 'Discount type', 'moksafocou' ),
			esc_attr__( 'Discount amount', 'moksafocou' )
		);
	}

	/** Enqueue the row add/remove script on the coupon edit screen only. */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'shop_coupon' !== $screen->id ) {
			return;
		}
		$rel  = 'src/Modules/DiscountTiers/assets/js/tiers.js';
		$path = \MOKSAFOCOU_PLUGIN_DIR . $rel;
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : \MOKSAFOCOU_VERSION;
		wp_enqueue_script( 'moksafocou-tiers', \MOKSAFOCOU_PLUGIN_URL . $rel, array(), $ver, true );
		wp_localize_script(
			'moksafocou-tiers',
			'moksafocouTiers',
			array(
				'maxRows'      => (int) apply_filters( 'moksafocou_tiers_ui_max_rows', 50 ),
				'basisId'      => Keys::TIERS_BASIS,
				'thresholdHdr' => array(
					/* translators: %s: the basis unit (cart subtotal). */
					'subtotal' => sprintf( __( 'Threshold ≥ (%s)', 'moksafocou' ), __( 'Cart subtotal', 'moksafocou' ) ),
					/* translators: %s: the basis unit (item count). */
					'quantity' => sprintf( __( 'Threshold ≥ (%s)', 'moksafocou' ), __( 'Quantity', 'moksafocou' ) ),
					/* translators: %s: the basis unit (weight kg). */
					'weight'   => sprintf( __( 'Threshold ≥ (%s)', 'moksafocou' ), __( 'Weight kg', 'moksafocou' ) ),
				),
			)
		);
		wp_register_style( 'moksafocou-tiers', false, array(), $ver );
		wp_enqueue_style( 'moksafocou-tiers' );
		wp_add_inline_style(
			'moksafocou-tiers',
			'.moksafocou-tier-idx{text-align:center;}'
			. '.moksafocou-tier-actions{text-align:center;}'
			// box-sizing so the full-width inputs/selects never overflow the narrow <td> under
			// WooCommerce's input padding.
			. '.moksafocou-tier-row input,.moksafocou-tier-row select{box-sizing:border-box;width:100%;}'
			. '.moksafocou-tier-remove{color:#b32d2e;font-size:20px;line-height:1;text-decoration:none;cursor:pointer;}'
			. '.moksafocou-tier-remove:hover{color:#8a2424;}'
		);
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
		// Store 'yes' / '' (not 'no') to match every other feature's enable flag convention.
		update_post_meta( $post_id, Keys::TIERS_ENABLED, isset( $_POST[ Keys::TIERS_ENABLED ] ) ? 'yes' : '' );

		$basis = isset( $_POST[ Keys::TIERS_BASIS ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::TIERS_BASIS ] ) ) : 'subtotal';
		update_post_meta( $post_id, Keys::TIERS_BASIS, Tiers::basis( $basis ) );

		$thresholds = isset( $_POST['moksafocou_tier_threshold'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['moksafocou_tier_threshold'] ) ) : array();
		$kinds      = isset( $_POST['moksafocou_tier_kind'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['moksafocou_tier_kind'] ) ) : array();
		$values     = isset( $_POST['moksafocou_tier_value'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['moksafocou_tier_value'] ) ) : array();

		$rows = array();
		foreach ( $values as $i => $value ) {
			$rows[] = array(
				'threshold' => (float) wc_format_decimal( (string) ( $thresholds[ $i ] ?? '' ) ),
				'kind'      => ( 'fixed' === ( $kinds[ $i ] ?? 'percent' ) ) ? 'fixed' : 'percent',
				'value'     => (float) wc_format_decimal( (string) $value ),
			);
		}
		$json = Tiers::canonical_json( $rows );
		if ( '' === $json ) {
			delete_post_meta( $post_id, Keys::TIERS );
		} else {
			update_post_meta( $post_id, Keys::TIERS, $json );
		}

		$mode = isset( $_POST[ Keys::TIERS_TARGET_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ Keys::TIERS_TARGET_MODE ] ) ) : 'all';
		update_post_meta( $post_id, Keys::TIERS_TARGET_MODE, in_array( $mode, array( 'products', 'categories' ), true ) ? $mode : 'all' );

		$this->save_id_list( $post_id, Keys::TIERS_TARGET_PRODUCTS );
		$this->save_id_list( $post_id, Keys::TIERS_TARGET_CATEGORIES );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private function save_id_list( int $post_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verified the nonce.
		$raw  = isset( $_POST[ $key ] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST[ $key ] ) ) : array();
		$list = FieldsHelpers::int_list( $raw );
		if ( array() === $list ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $list );
		}
	}
}
