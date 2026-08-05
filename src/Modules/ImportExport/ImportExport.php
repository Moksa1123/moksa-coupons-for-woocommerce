<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\ImportExport;

use Moksafocou\Coupon\CouponService;
use Moksafocou\Coupon\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * CSV import / export of coupons — a backup / audit / bulk-edit surface WooCommerce lacks.
 * Exports native WC_Coupon fields plus the most-used plugin settings (including the tiered
 * and advanced-rule JSON) for a full round-trip; import creates new coupons or updates
 * existing ones matched by code. Capability- and nonce-checked on both paths.
 */
final class ImportExport {

	private const CAP          = 'manage_woocommerce';
	private const SLUG         = 'moksafocou-import-export';
	private const NONCE_EXPORT = 'moksafocou_export_coupons';
	private const NONCE_IMPORT = 'moksafocou_import_coupons';

	/** Native WC_Coupon fields (get_/set_). */
	private const NATIVE = array(
		'code',
		'discount_type',
		'amount',
		'description',
		'date_expires',
		'usage_limit',
		'usage_limit_per_user',
		'individual_use',
		'free_shipping',
		'minimum_amount',
		'maximum_amount',
		'product_ids',
		'excluded_product_ids',
		'product_categories',
		'status',
	);

	/** Extra plugin-meta columns: csv header => meta key. */
	private const META = array(
		'campaign'     => Keys::CAMPAIGN,
		'auto_apply'   => Keys::AUTO_APPLY,
		'discount_cap' => Keys::DISCOUNT_CAP,
		'min_subtotal' => Keys::MIN_SUBTOTAL,
		'tiers_json'   => Keys::TIERS,
		'rules_json'   => Keys::RULES,
	);

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_post_moksafocou_export_coupons', array( self::class, 'handle_export' ) );
		add_action( 'admin_post_moksafocou_import_coupons', array( self::class, 'handle_import' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'moksa-coupons-for-woocommerce',
			__( 'Import / Export', 'moksa-coupons-for-woocommerce' ),
			__( 'Import / Export', 'moksa-coupons-for-woocommerce' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	private static function columns(): array {
		return array_merge( self::NATIVE, array_keys( self::META ) );
	}

	/* ---------------- page ---------------- */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap"><div class="mowp-shell" data-ns="moksa-coupons-for-woocommerce">';
		echo '<div class="mowp-intro"><h1>' . esc_html__( 'Coupon import / export', 'moksa-coupons-for-woocommerce' ) . '</h1>';
		echo '<p>' . esc_html__( 'Back up, audit, or bulk-edit coupons: export to CSV, or upload a CSV to create / update many coupons at once.', 'moksa-coupons-for-woocommerce' ) . '</p></div>';

		echo '<div class="mowp-panel mowp-panel--wide"><div class="mowp-panel__head">' . esc_html__( 'Export', 'moksa-coupons-for-woocommerce' ) . '</div><div class="mowp-panel__body">';
		echo '<p class="description">' . esc_html__( 'Export all coupons (including tiered / advanced rule settings) to CSV, for backup, auditing, or bulk editing.', 'moksa-coupons-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="moksafocou_export_coupons">';
		wp_nonce_field( self::NONCE_EXPORT );
		submit_button( __( 'Download CSV', 'moksa-coupons-for-woocommerce' ), 'primary', 'submit', false );
		echo '</form>';
		echo '</div></div>';

		echo '<div class="mowp-panel mowp-panel--wide"><div class="mowp-panel__head">' . esc_html__( 'Import', 'moksa-coupons-for-woocommerce' ) . '</div><div class="mowp-panel__body">';
		echo '<p class="description">' . esc_html__( 'Upload a CSV in the same format. Matching is done by the "code" column: existing coupons are updated, new ones are created as drafts.', 'moksa-coupons-for-woocommerce' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="moksafocou_import_coupons">';
		wp_nonce_field( self::NONCE_IMPORT );
		echo '<input type="file" name="csv" accept=".csv,text/csv" required> ';
		submit_button( __( 'Import CSV', 'moksa-coupons-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div></div>';

		echo '</div></div>';
	}

	/* ---------------- export ---------------- */

	public static function handle_export(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksa-coupons-for-woocommerce' ) );
		}
		check_admin_referer( self::NONCE_EXPORT );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="moksafocou-coupons-' . gmdate( 'Ymd-His' ) . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV to the response, not the filesystem.
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM to php://output so Excel reads Chinese correctly.
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array_map( array( self::class, 'escape_cell' ), self::columns() ) );
		foreach ( self::all_coupon_ids() as $id ) {
			$coupon = new \WC_Coupon( $id );
			if ( $coupon->get_id() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- writing to php://output.
				fputcsv( $out, array_map( array( self::class, 'escape_cell' ), self::row( $coupon, $id ) ) );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing php://output stream.
		fclose( $out );
		exit;
	}

	/**
	 * Neutralize CSV / spreadsheet formula injection by prefixing a single quote to any cell
	 * that begins with a formula trigger, mirroring WooCommerce core WC_CSV_Exporter::escape_data().
	 */
	private static function escape_cell( string $value ): string {
		$triggers = array( '=', '+', '-', '@', "\t", "\r" );
		if ( '' !== $value && in_array( $value[0], $triggers, true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * @return array<int,string>
	 */
	private static function row( \WC_Coupon $coupon, int $id ): array {
		$row = array();
		foreach ( self::NATIVE as $field ) {
			$row[] = self::native_value( $coupon, $field );
		}
		foreach ( self::META as $meta_key ) {
			$value = get_post_meta( $id, $meta_key, true );
			$row[] = is_array( $value ) ? implode( '|', array_map( 'strval', $value ) ) : (string) $value;
		}
		return $row;
	}

	private static function native_value( \WC_Coupon $coupon, string $field ): string {
		switch ( $field ) {
			case 'date_expires':
				$date = $coupon->get_date_expires();
				return $date ? $date->date( 'Y-m-d' ) : '';
			case 'individual_use':
			case 'free_shipping':
				return $coupon->{"get_$field"}() ? 'yes' : 'no';
			case 'product_ids':
			case 'excluded_product_ids':
			case 'product_categories':
				return implode( '|', array_map( 'strval', (array) $coupon->{"get_$field"}() ) );
			case 'status':
				return (string) get_post_status( $coupon->get_id() );
			default:
				return (string) $coupon->{"get_$field"}();
		}
	}

	/**
	 * @return array<int,int>
	 */
	private static function all_coupon_ids(): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'shop_coupon',
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return array_map( 'intval', $query->posts );
	}

	/* ---------------- import ---------------- */

	public static function handle_import(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksa-coupons-for-woocommerce' ) );
		}
		check_admin_referer( self::NONCE_IMPORT );
		$back = add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );

		$tmp = isset( $_FILES['csv']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['csv']['tmp_name'] ) ) : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			wp_safe_redirect( add_query_arg( 'moksafocou_import', 'nofile', $back ) );
			exit;
		}

		// Server-side type whitelist: only accept a real CSV / plain-text upload (never trust the
		// client). The file is then read purely as CSV rows — never executed — and every value is
		// re-sanitized below.
		$file_name = isset( $_FILES['csv']['name'] ) ? sanitize_file_name( wp_unslash( (string) $_FILES['csv']['name'] ) ) : '';
		$file_type = wp_check_filetype_and_ext( $tmp, $file_name );
		if ( ! in_array( (string) ( $file_type['ext'] ?? '' ), array( 'csv', 'txt' ), true ) ) {
			wp_safe_redirect( add_query_arg( 'moksafocou_import', 'badtype', $back ) );
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading the just-uploaded temp file row by row.
		$handle = fopen( $tmp, 'r' );
		if ( false === $handle ) {
			wp_safe_redirect( add_query_arg( 'moksafocou_import', 'nofile', $back ) );
			exit;
		}

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_safe_redirect( add_query_arg( 'moksafocou_import', 'empty', $back ) );
			exit;
		}
		$header = array_map( static fn( $h ): string => trim( str_replace( "\xEF\xBB\xBF", '', (string) $h ) ), $header );

		$created = 0;
		$updated = 0;
		$failed  = 0;
		while ( true ) {
			$data = fgetcsv( $handle );
			if ( false === $data || null === $data ) {
				break;
			}
			if ( array( null ) === $data ) {
				continue; // blank line.
			}
			$assoc = self::associate( $header, $data );
			$code  = isset( $assoc['code'] ) ? trim( (string) $assoc['code'] ) : '';
			if ( '' === $code ) {
				continue;
			}
			$result = self::import_row( $assoc );
			if ( 'created' === $result ) {
				++$created;
			} elseif ( 'updated' === $result ) {
				++$updated;
			} else {
				++$failed;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		wp_safe_redirect(
			add_query_arg(
				array(
					'moksafocou_import' => 'ok',
					'c'                 => $created,
					'u'                 => $updated,
					'f'                 => $failed,
				),
				$back
			)
		);
		exit;
	}

	/**
	 * @param array<int,string> $header
	 * @param array<int,string> $data
	 * @return array<string,string>
	 */
	private static function associate( array $header, array $data ): array {
		$assoc = array();
		foreach ( $header as $i => $key ) {
			$assoc[ $key ] = isset( $data[ $i ] ) ? (string) $data[ $i ] : '';
		}
		return $assoc;
	}

	/**
	 * @param array<string,string> $assoc
	 * @return string created|updated|failed
	 */
	private static function import_row( array $assoc ): string {
		$code = trim( (string) $assoc['code'] );
		// Match across ALL statuses (wc_get_coupon_id_by_code only finds published) so
		// re-importing a draft updates it instead of creating a duplicate.
		$existing = self::find_any_status_by_code( $code );

		$fields = array( 'code' => $code );
		foreach ( self::NATIVE as $field ) {
			if ( 'code' === $field || ! array_key_exists( $field, $assoc ) ) {
				continue;
			}
			$raw = trim( (string) $assoc[ $field ] );
			switch ( $field ) {
				case 'individual_use':
				case 'free_shipping':
					$fields[ $field ] = ( 'yes' === strtolower( $raw ) );
					break;
				case 'product_ids':
				case 'excluded_product_ids':
				case 'product_categories':
					$fields[ $field ] = '' === $raw ? array() : array_values( array_filter( array_map( 'absint', explode( '|', $raw ) ) ) );
					break;
				case 'usage_limit':
				case 'usage_limit_per_user':
					$fields[ $field ] = (int) $raw;
					break;
				case 'date_expires':
					if ( '' !== $raw ) {
						$fields['date_expires'] = $raw;
					}
					break;
				case 'status':
					$fields['status'] = ( 'publish' === $raw ) ? 'publish' : 'draft';
					break;
				default:
					if ( '' !== $raw ) {
						$fields[ $field ] = $raw;
					}
			}
		}

		$coupon = CouponService::save( $fields, $existing > 0 ? $existing : 0 );
		if ( $coupon instanceof \WP_Error || ! $coupon->get_id() ) {
			return 'failed';
		}
		$id = (int) $coupon->get_id();

		// Plugin-meta columns run through the registered sanitize callbacks on write.
		foreach ( self::META as $column => $meta_key ) {
			if ( ! array_key_exists( $column, $assoc ) ) {
				continue;
			}
			$raw = trim( (string) $assoc[ $column ] );
			if ( '' === $raw ) {
				delete_post_meta( $id, $meta_key );
			} else {
				update_post_meta( $id, $meta_key, wp_slash( $raw ) );
			}
		}

		return $existing > 0 ? 'updated' : 'created';
	}

	/** Coupon id for a code across any post status (codes are stored lowercased). */
	private static function find_any_status_by_code( string $code ): int {
		$code = function_exists( 'wc_format_coupon_code' ) ? wc_format_coupon_code( $code ) : strtolower( trim( $code ) );
		if ( '' === $code ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'              => 'shop_coupon',
				'post_status'            => 'any',
				'title'                  => $code,
				'numberposts'            => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return ( is_array( $ids ) && isset( $ids[0] ) ) ? (int) $ids[0] : 0;
	}

	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::SLUG ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only result flags from a redirect.
		if ( ! isset( $_GET['moksafocou_import'] ) ) {
			return;
		}
		$state = sanitize_key( wp_unslash( $_GET['moksafocou_import'] ) );
		if ( 'ok' === $state ) {
			$created = isset( $_GET['c'] ) ? (int) $_GET['c'] : 0;
			$updated = isset( $_GET['u'] ) ? (int) $_GET['u'] : 0;
			$failed  = isset( $_GET['f'] ) ? (int) $_GET['f'] : 0;
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: 1: created count, 2: updated count, 3: failed count. */
					__( 'Import complete: %1$d created, %2$d updated, %3$d failed.', 'moksa-coupons-for-woocommerce' ),
					$created,
					$updated,
					$failed
				)
			) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Import failed: please choose a valid CSV file.', 'moksa-coupons-for-woocommerce' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
