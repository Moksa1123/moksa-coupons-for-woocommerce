<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Templates;

use Moksafocou\Plugin;
use Moksafocou\Coupon\CouponService;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a Catalog template into a real (draft) coupon. Reuses CouponService for the
 * native WC_Coupon fields, then writes the template's whitelisted plugin meta. The
 * coupon is left as a draft so the admin finalizes the code and publishes.
 */
final class Applier {

	/**
	 * @param string              $template_id Catalog template id.
	 * @param array<string,mixed> $overrides   Optional admin tweaks made on the templates
	 *                                          page before creation: 'code' (blank = auto),
	 *                                          'amount' (native percent/fixed only),
	 *                                          'date_expires' (Y-m-d or ''), 'usage_limit' &
	 *                                          'usage_limit_per_user' (ints, '' = keep),
	 *                                          'description' (string), 'individual_use' (yes/no).
	 * @return int|\WP_Error New coupon post ID, or error.
	 */
	public static function apply( string $template_id, array $overrides = array() ) {
		$tpl = Catalog::get( $template_id );
		if ( null === $tpl ) {
			return new \WP_Error( 'moksafocou_template_unknown', __( 'The specified coupon template was not found.', 'moksafocou' ) );
		}

		$missing = array();
		foreach ( Catalog::required_modules( $tpl ) as $slug ) {
			if ( ! Plugin::instance()->modules()->is_enabled( $slug ) ) {
				$missing[] = Catalog::module_label( $slug );
			}
		}
		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'moksafocou_template_requires',
				sprintf(
					/* translators: %s: comma-separated required feature module labels. */
					__( 'This template requires the "%s" feature module to be enabled first.', 'moksafocou' ),
					implode( '、', $missing )
				)
			);
		}

		$native         = is_array( $tpl['native'] ?? null ) ? $tpl['native'] : array();
		$type           = (string) ( $native['discount_type'] ?? 'fixed_cart' );
		$is_native_type = in_array( $type, CouponService::DISCOUNT_TYPES, true );

		$fields = $native;

		// Optional admin override: custom code (must be unique), else auto-generate.
		$code = isset( $overrides['code'] ) ? trim( (string) $overrides['code'] ) : '';
		if ( '' !== $code ) {
			if ( CouponService::find_id_by_code( $code ) > 0 ) {
				return new \WP_Error(
					'moksafocou_template_code_exists',
					/* translators: %s: the coupon code. */
					sprintf( __( 'Coupon code "%s" already exists; please use another.', 'moksafocou' ), $code )
				);
			}
			$fields['code'] = $code;
		} else {
			$fields['code'] = self::unique_code( (string) ( $tpl['prefix'] ?? 'COUPON' ) );
		}

		// Optional admin override: discount amount (only for native percent/fixed templates;
		// BOGO/free-ship carry their value in meta, so their amount stays as the template set it).
		if ( $is_native_type && isset( $overrides['amount'] ) && '' !== (string) $overrides['amount'] && is_numeric( $overrides['amount'] ) ) {
			$fields['amount'] = (float) $overrides['amount'];
		}

		// Optional admin override: expiry date (native WC field; '' clears / keeps none).
		if ( isset( $overrides['date_expires'] ) && '' !== trim( (string) $overrides['date_expires'] ) ) {
			$fields['date_expires'] = trim( (string) $overrides['date_expires'] );
		}

		// Optional admin overrides: usage caps (native ints). Empty = keep the template's.
		foreach ( array( 'usage_limit', 'usage_limit_per_user' ) as $cap_field ) {
			if ( isset( $overrides[ $cap_field ] ) && '' !== (string) $overrides[ $cap_field ] && is_numeric( $overrides[ $cap_field ] ) ) {
				$fields[ $cap_field ] = max( 0, (int) $overrides[ $cap_field ] );
			}
		}

		// Optional admin override: description (native; only when the admin typed something).
		if ( isset( $overrides['description'] ) && '' !== trim( (string) $overrides['description'] ) ) {
			$fields['description'] = (string) $overrides['description'];
		}

		// Optional admin override: individual-use flag (checkbox always sends yes/no).
		if ( isset( $overrides['individual_use'] ) ) {
			$fields['individual_use'] = 'yes' === $overrides['individual_use'];
		}

		if ( ! $is_native_type ) {
			// normalize_and_validate only accepts native types; set the custom type after.
			unset( $fields['discount_type'] );
		}

		$normalized = CouponService::normalize_and_validate( $fields );
		if ( $normalized instanceof \WP_Error ) {
			return $normalized;
		}

		// Create the coupon straight as a draft via the WooCommerce CRUD — WC inserts it
		// with post_status = the coupon's status (never briefly published), and writes
		// date_created / meta correctly. The admin reviews and publishes it afterwards.
		$normalized['status'] = 'draft';

		$coupon = CouponService::save( $normalized );
		if ( $coupon instanceof \WP_Error ) {
			return $coupon;
		}
		$id = (int) $coupon->get_id();

		// Custom discount type (e.g. BOGO) — set directly; its module is on (requires gate).
		// The status prop stays 'draft', so the update keeps it a draft.
		if ( ! $is_native_type ) {
			$coupon->set_discount_type( $type );
			if ( ! $coupon->save() ) {
				wp_delete_post( $id, true );
				return new \WP_Error( 'moksafocou_template_type_failed', __( 'The discount type cannot be set when applying a template.', 'moksafocou' ) );
			}
		}

		// Write the template's plugin meta (whitelisted to known keys).
		$meta = is_array( $tpl['meta'] ?? null ) ? $tpl['meta'] : array();
		foreach ( Catalog::sanitize_meta( $meta ) as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	private static function unique_code( string $prefix ): string {
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) ?? '' );
		if ( '' === $prefix ) {
			$prefix = 'COUPON';
		}
		// Grow entropy as we go; every candidate (including the last) is uniqueness-checked.
		$length = 4;
		for ( $i = 0; $i < 30; $i++ ) {
			$code = $prefix . '-' . strtoupper( wp_generate_password( $length, false, false ) );
			if ( 0 === CouponService::find_id_by_code( $code ) ) {
				return $code;
			}
			if ( 9 === $i % 10 ) {
				++$length;
			}
		}
		// Astronomically unlikely to reach here; use maximum entropy as a final value.
		return $prefix . '-' . strtoupper( wp_generate_password( 12, false, false ) );
	}
}
