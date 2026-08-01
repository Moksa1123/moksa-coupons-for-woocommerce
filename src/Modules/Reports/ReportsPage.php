<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Standalone 'Coupon report' admin page (submenu under WooCommerce). Read-only table of
 * per-coupon performance from ReportService. This page also seeds the future
 * standalone coupon-management screen.
 */
final class ReportsPage {

	private const SLUG  = 'moksafocou-reports';
	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'moksafocou_reports_refresh';

	/** Public accessor so the AdminMenu module can reparent this page. */
	public static function slug(): string {
		return self::SLUG;
	}

	public static function register(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Coupon report', 'moksafocou' ),
			__( 'Coupon report', 'moksafocou' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap"><div class="mowp-shell" data-ns="moksafocou">';
		echo '<div class="mowp-intro"><h1>' . esc_html__( 'Coupon report', 'moksafocou' ) . '</h1>';
		echo '<p>' . esc_html__( 'Track orders used and total discount per coupon based on paid orders (cached hourly).', 'moksafocou' ) . '</p></div>';
		self::render_table( admin_url( 'admin.php?page=' . self::SLUG ) );
		echo '</div></div>';
	}

	/**
	 * Render the report summary + table. Reused by the standalone page and by the
	 * dashboard (where reports now live). $page_url is the base for the refresh link.
	 */
	public static function render_table( string $page_url ): void {
		$rows           = ReportService::compute( self::refresh_requested() );
		$total_discount = 0.0;
		$total_orders   = 0;
		foreach ( $rows as $row ) {
			$total_discount += (float) $row['discount'];
			$total_orders   += (int) $row['orders'];
		}

		$refresh_url = wp_nonce_url( add_query_arg( 'refresh', '1', $page_url ), self::NONCE );

		echo '<p>';
		echo '<a href="' . esc_url( $refresh_url ) . '" class="button">' . esc_html__( 'Refresh', 'moksafocou' ) . '</a> ';
		printf(
			/* translators: 1: number of coupons, 2: total orders, 3: total discount. */
			esc_html__( 'A total of %1$s coupon(s) used, %2$s order(s), with %3$s in cumulative discount.', 'moksafocou' ),
			'<strong>' . esc_html( (string) count( $rows ) ) . '</strong>',
			'<strong>' . esc_html( (string) $total_orders ) . '</strong>',
			'<strong>' . wp_kses_post( wc_price( $total_discount ) ) . '</strong>'
		);
		echo '</p>';

		self::render_overview();
		self::render_campaigns();

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		foreach (
			array(
				__( 'Code', 'moksafocou' ),
				__( 'Type', 'moksafocou' ),
				__( 'Discount amount', 'moksafocou' ),
				__( 'Status', 'moksafocou' ),
				__( 'Orders used', 'moksafocou' ),
				__( 'Total discount', 'moksafocou' ),
				__( 'Usage count / limit', 'moksafocou' ),
				__( 'Expiry date', 'moksafocou' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No coupon usage records yet.', 'moksafocou' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$limit = (int) $row['usage_limit'] > 0 ? (string) (int) $row['usage_limit'] : '∞';
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $row['code'] ) . '</code></td>';
			echo '<td>' . esc_html( self::type_label( (string) $row['type'] ) ) . '</td>';
			$amount_display = ( '' !== $row['amount'] && 0.0 !== (float) $row['amount'] ) ? (string) $row['amount'] : '—';
			echo '<td>' . esc_html( $amount_display ) . '</td>';
			echo '<td>' . esc_html( self::status_label( (string) $row['status'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['orders'] ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( (float) $row['discount'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['usage_count'] . ' / ' . $limit ) . '</td>';
			echo '<td>' . esc_html( '' !== $row['expires'] ? (string) $row['expires'] : '—' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/** Top-line "coupons drove this much business" overview + a compact recent-days trend. */
	private static function render_overview(): void {
		$ov = ReportService::overview( 30 );

		echo '<div class="mowp-tiles">';
		$cards = array(
			array( __( 'Coupon orders in the last 30 days', 'moksafocou' ), esc_html( (string) $ov['coupon_orders'] ) ),
			array( __( 'Coupon order revenue', 'moksafocou' ), wp_kses_post( wc_price( (float) $ov['coupon_revenue'] ) ) ),
			array( __( 'Total discount', 'moksafocou' ), wp_kses_post( wc_price( (float) $ov['total_discount'] ) ) ),
			array( __( 'Average order value (with coupon)', 'moksafocou' ), wp_kses_post( wc_price( (float) $ov['avg_order_value'] ) ) ),
		);
		foreach ( $cards as $card ) {
			echo '<div class="mowp-tile">';
			echo '<div class="mowp-tile__num">' . $card[1] . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each value escaped above (esc_html / wc_price via wp_kses_post).
			echo '<div class="mowp-tile__label">' . esc_html( (string) $card[0] ) . '</div>';
			echo '</div>';
		}
		echo '</div>';

		$recent = array_slice( $ov['daily'], -14 );
		if ( array() !== $recent ) {
			echo '<table class="wp-list-table widefat fixed striped" style="margin-bottom:18px;max-width:560px;">';
			echo '<thead><tr><th>' . esc_html__( 'Date', 'moksafocou' ) . '</th><th>' . esc_html__( 'Coupon orders', 'moksafocou' )
				. '</th><th>' . esc_html__( 'Discount', 'moksafocou' ) . '</th><th>' . esc_html__( 'Revenue', 'moksafocou' ) . '</th></tr></thead><tbody>';
			foreach ( $recent as $day ) {
				echo '<tr><td>' . esc_html( (string) $day['date'] ) . '</td>';
				echo '<td>' . esc_html( (string) (int) $day['orders'] ) . '</td>';
				echo '<td>' . wp_kses_post( wc_price( (float) $day['discount'] ) ) . '</td>';
				echo '<td>' . wp_kses_post( wc_price( (float) $day['revenue'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/** Per-campaign rollup table (only shown when coupons carry campaign tags). */
	private static function render_campaigns(): void {
		$rows = ReportService::by_campaign();
		if ( array() === $rows ) {
			return;
		}
		echo '<h2 class="mowp-h2">' . esc_html__( 'Campaign performance', 'moksafocou' ) . '</h2>';
		echo '<table class="wp-list-table widefat fixed striped" style="max-width:680px;margin-bottom:18px;">';
		echo '<thead><tr><th>' . esc_html__( 'Campaign', 'moksafocou' ) . '</th><th>' . esc_html__( 'Coupons', 'moksafocou' )
			. '</th><th>' . esc_html__( 'Orders used', 'moksafocou' ) . '</th><th>' . esc_html__( 'Total discount', 'moksafocou' )
			. '</th><th>' . esc_html__( 'Coupon revenue', 'moksafocou' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td><strong>' . esc_html( (string) $row['campaign'] ) . '</strong></td>';
			echo '<td>' . esc_html( (string) (int) $row['coupons'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['orders'] ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( (float) $row['discount'] ) ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( (float) $row['revenue'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function refresh_requested(): bool {
		if ( isset( $_GET['refresh'], $_GET['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
			return (bool) wp_verify_nonce( $nonce, self::NONCE );
		}
		return false;
	}

	private static function type_label( string $type ): string {
		return \Moksafocou\Support\CouponType::label( $type );
	}

	private static function status_label( string $status ): string {
		$map = array(
			'publish' => __( 'Enable', 'moksafocou' ),
			'draft'   => __( 'Disable', 'moksafocou' ),
			'trash'   => __( 'Deleted', 'moksafocou' ),
			'deleted' => __( 'Deleted', 'moksafocou' ),
		);
		return $map[ $status ] ?? $status;
	}
}
