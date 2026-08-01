<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\CouponCore;

use Moksafocou\Coupon\CouponService;
use Moksafocou\Coupon\Meta\CouponSettings;
use Moksafocou\Support\GuardedOps;
use Moksafocou\Modules\AutoApply\AutoApplyMeta;
use Moksafocou\Modules\Templates\Applier;

defined( 'ABSPATH' ) || exit;

/**
 * Destructive coupon operations as propose/apply pairs. The Abilities point their
 * execute_callback at *_prepare (proposal only, no writes); *_apply performs the
 * real change and is invoked solely by the in-dashboard confirm flow / REST after
 * an explicit human confirmation. Both ends re-check the capability.
 */
final class CouponOps {

	use GuardedOps;

	/* ---------------- create ---------------- */

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input  = is_array( $input ) ? $input : [];
		$fields = CouponService::normalize_and_validate( $input, false );
		if ( $fields instanceof \WP_Error ) {
			return $fields;
		}
		if ( empty( $fields['code'] ) ) {
			return new \WP_Error( 'moksafocou_invalid_code', __( 'Coupon code cannot be empty.', 'moksafocou' ) );
		}
		if ( CouponService::find_id_by_code( $fields['code'] ) > 0 ) {
			return new \WP_Error(
				'moksafocou_duplicate',
				/* translators: %s: coupon code. */
				sprintf( __( 'Coupon code %s already exists; please use another.', 'moksafocou' ), $fields['code'] )
			);
		}
		return self::carry_extras(
			[
				'fields'  => $fields,
				'summary' => CouponService::build_summary( $fields, __( 'Create', 'moksafocou' ) ),
			],
			$input
		);
	}

	/**
	 * Carry optional cross-module extras from the AI input into the proposed params and
	 * note them on the confirm summary. Each is only carried when actually supplied:
	 * the full grouped `moksafocou` settings object (schedule / conditions / BOGO /
	 * gift / shipping…), plus the three convenience shortcuts auto_apply / discount_cap
	 * / exclude_coupons (which override the grouped values at apply time).
	 *
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	private static function carry_extras( array $result, array $input ): array {
		if ( array_key_exists( 'moksafocou', $input ) && is_array( $input['moksafocou'] ) ) {
			$result['settings'] = $input['moksafocou'];
			if ( isset( $result['summary'] ) ) {
				$result['summary'] .= __( ', with advanced settings', 'moksafocou' );
			}
		}
		if ( array_key_exists( 'auto_apply', $input ) ) {
			$enable               = (bool) filter_var( $input['auto_apply'], FILTER_VALIDATE_BOOLEAN );
			$result['auto_apply'] = $enable;
			if ( $enable && isset( $result['summary'] ) ) {
				$result['summary'] .= __( ', auto-apply', 'moksafocou' );
			}
		}
		if ( array_key_exists( 'discount_cap', $input ) && is_numeric( $input['discount_cap'] ) ) {
			$cap                    = max( 0.0, (float) $input['discount_cap'] );
			$result['discount_cap'] = $cap;
			if ( $cap > 0 && isset( $result['summary'] ) ) {
				/* translators: %s: max discount amount. */
				$result['summary'] .= sprintf( __( ', up to %s off', 'moksafocou' ), $cap );
			}
		}
		if ( array_key_exists( 'exclude_coupons', $input ) ) {
			$exclude                   = (bool) filter_var( $input['exclude_coupons'], FILTER_VALIDATE_BOOLEAN );
			$result['exclude_coupons'] = $exclude;
			if ( $exclude && isset( $result['summary'] ) ) {
				$result['summary'] .= __( ', cannot be combined with other coupons', 'moksafocou' );
			}
		}
		return $result;
	}

	/** Persist carried extras after the coupon is saved (shared writers). */
	private static function apply_extras( int $coupon_id, array $params ): void {
		// Apply the full grouped settings first; the shortcuts below then override.
		if ( array_key_exists( 'settings', $params ) && is_array( $params['settings'] ) ) {
			CouponSettings::write( $coupon_id, $params['settings'] );
		}
		if ( array_key_exists( 'auto_apply', $params ) ) {
			AutoApplyMeta::write( $coupon_id, (bool) $params['auto_apply'] );
		}
		if ( array_key_exists( 'discount_cap', $params ) ) {
			$cap = max( 0.0, (float) $params['discount_cap'] );
			if ( $cap > 0 ) {
				update_post_meta( $coupon_id, \Moksafocou\Coupon\Meta\Keys::DISCOUNT_CAP, (string) $cap );
			} else {
				delete_post_meta( $coupon_id, \Moksafocou\Coupon\Meta\Keys::DISCOUNT_CAP );
			}
		}
		if ( array_key_exists( 'exclude_coupons', $params ) ) {
			update_post_meta( $coupon_id, \Moksafocou\Coupon\Meta\Keys::STACK_EXCLUDE, $params['exclude_coupons'] ? 'yes' : '' );
		}
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$fields = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : [];
		$coupon = CouponService::save( $fields );
		if ( $coupon instanceof \WP_Error ) {
			return $coupon;
		}
		self::apply_extras( $coupon->get_id(), $params );
		return [
			'id'    => $coupon->get_id(),
			'reply' => sprintf(
				/* translators: 1: coupon code, 2: coupon id. */
				__( 'Created coupon %1$s (#%2$d).', 'moksafocou' ),
				$coupon->get_code(),
				$coupon->get_id()
			),
		];
	}

	/* ---------------- update ---------------- */

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function update_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input = is_array( $input ) ? $input : [];
		$id    = CouponService::resolve_id( $input['code_or_id'] ?? '' );
		if ( ! $id ) {
			return new \WP_Error( 'moksafocou_not_found', __( 'Coupon not found.', 'moksafocou' ) );
		}
		$fields = CouponService::normalize_and_validate( $input, true );
		if ( $fields instanceof \WP_Error ) {
			return $fields;
		}
		unset( $fields['code'] ); // Code changes are not allowed via update.
		if ( [] === $fields && ! array_key_exists( 'auto_apply', $input ) && ! array_key_exists( 'discount_cap', $input ) && ! array_key_exists( 'exclude_coupons', $input ) ) {
			return new \WP_Error( 'moksafocou_nothing', __( 'No fields to update.', 'moksafocou' ) );
		}
		return self::carry_extras(
			[
				'id'      => $id,
				'fields'  => $fields,
				'summary' => CouponService::build_summary(
					array_merge( [ 'code' => (string) ( CouponService::get( $id )['code'] ?? $id ) ], $fields ),
					__( 'Update', 'moksafocou' )
				),
			],
			$input
		);
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function update_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$id     = (int) ( $params['id'] ?? 0 );
		$fields = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : [];
		$coupon = CouponService::save( $fields, $id );
		if ( $coupon instanceof \WP_Error ) {
			return $coupon;
		}
		self::apply_extras( $coupon->get_id(), $params );
		return [
			'id'    => $coupon->get_id(),
			/* translators: %s: coupon code. */
			'reply' => sprintf( __( 'Updated coupon %s.', 'moksafocou' ), $coupon->get_code() ),
		];
	}

	/* ---------------- toggle ---------------- */

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function toggle_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input  = is_array( $input ) ? $input : [];
		$id     = CouponService::resolve_id( $input['code_or_id'] ?? '' );
		$enable = ! empty( $input['enable'] );
		if ( ! $id ) {
			return new \WP_Error( 'moksafocou_not_found', __( 'Coupon not found.', 'moksafocou' ) );
		}
		$data = CouponService::get( $id );
		return [
			'id'      => $id,
			'enable'  => $enable,
			'summary' => sprintf(
				/* translators: 1: enable/disable verb, 2: coupon code. */
				__( '%1$s coupon %2$s', 'moksafocou' ),
				$enable ? __( 'Enable', 'moksafocou' ) : __( 'Disable', 'moksafocou' ),
				(string) ( $data['code'] ?? $id )
			),
		];
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function toggle_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$id = (int) ( $params['id'] ?? 0 );
		if ( ! CouponService::set_status( $id, ! empty( $params['enable'] ) ) ) {
			return new \WP_Error( 'moksafocou_toggle_failed', __( 'Failed to toggle status.', 'moksafocou' ) );
		}
		return [
			'id'    => $id,
			'reply' => ! empty( $params['enable'] )
				? __( 'Coupon enabled.', 'moksafocou' )
				: __( 'Coupon disabled.', 'moksafocou' ),
		];
	}

	/* ---------------- delete ---------------- */

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function delete_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input = is_array( $input ) ? $input : [];
		$id    = CouponService::resolve_id( $input['code_or_id'] ?? '' );
		if ( ! $id ) {
			return new \WP_Error( 'moksafocou_not_found', __( 'Coupon not found.', 'moksafocou' ) );
		}
		$force = ! empty( $input['force'] );
		$data  = CouponService::get( $id );
		return [
			'id'      => $id,
			'force'   => $force,
			'summary' => sprintf(
				/* translators: 1: coupon code, 2: permanently/to trash. */
				__( 'Delete coupon %1$s (%2$s)', 'moksafocou' ),
				(string) ( $data['code'] ?? $id ),
				$force ? __( 'Permanently delete', 'moksafocou' ) : __( 'Move to trash', 'moksafocou' )
			),
		];
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function delete_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$id = (int) ( $params['id'] ?? 0 );
		if ( ! CouponService::delete( $id, ! empty( $params['force'] ) ) ) {
			return new \WP_Error( 'moksafocou_delete_failed', __( 'Deletion failed.', 'moksafocou' ) );
		}
		return [
			'id'    => $id,
			'reply' => __( 'Coupon deleted.', 'moksafocou' ),
		];
	}

	/* ---------------- bulk generate ---------------- */

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function bulk_generate_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input = is_array( $input ) ? $input : [];
		$count = (int) ( $input['count'] ?? 0 );
		if ( $count < 1 || $count > 500 ) {
			return new \WP_Error( 'moksafocou_bad_count', __( 'The quantity must be 1–500.', 'moksafocou' ) );
		}
		$fields = CouponService::normalize_and_validate( $input, true );
		if ( $fields instanceof \WP_Error ) {
			return $fields;
		}
		unset( $fields['code'] );
		$prefix = isset( $input['prefix'] ) ? strtoupper( sanitize_text_field( (string) $input['prefix'] ) ) : '';
		return self::carry_extras(
			[
				'count'   => $count,
				'prefix'  => $prefix,
				'fields'  => $fields,
				'summary' => sprintf(
					/* translators: 1: count, 2: prefix, 3: discount summary. */
					__( 'Bulk-generate %1$d coupons (prefix "%2$s"): %3$s', 'moksafocou' ),
					$count,
					$prefix,
					CouponService::build_summary( array_merge( [ 'code' => $prefix . '…' ], $fields ), '' )
				),
			],
			$input
		);
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function bulk_generate_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$count  = (int) ( $params['count'] ?? 0 );
		$prefix = (string) ( $params['prefix'] ?? '' );
		$fields = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : [];

		$created = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$code = self::unique_code( $prefix );
			if ( '' === $code ) {
				continue;
			}
			$coupon = CouponService::save( array_merge( $fields, [ 'code' => $code ] ) );
			if ( ! ( $coupon instanceof \WP_Error ) ) {
				self::apply_extras( $coupon->get_id(), $params );
				$created[] = $coupon->get_code();
			}
		}
		$made  = count( $created );
		$reply = sprintf(
			/* translators: %d: number of coupons created. */
			__( 'Bulk-generated %d coupons.', 'moksafocou' ),
			$made
		);
		// Don't silently under-deliver: surface the shortfall (code collisions / save errors).
		if ( $made < $count ) {
			$reply .= ' ' . sprintf(
				/* translators: 1: requested count, 2: shortfall count. */
				__( '(%1$d requested; %2$d were not created due to duplicate codes or save failures)', 'moksafocou' ),
				$count,
				$count - $made
			);
		}
		return [
			'codes' => $created,
			'reply' => $reply,
		];
	}

	/* ---------------- extend expiry ---------------- */

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function extend_expiry_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input = is_array( $input ) ? $input : [];
		$refs  = isset( $input['codes_or_ids'] ) && is_array( $input['codes_or_ids'] ) ? $input['codes_or_ids'] : [];
		$date  = isset( $input['date_expires'] ) ? sanitize_text_field( (string) $input['date_expires'] ) : '';
		$ts    = '' === $date ? false : strtotime( $date );
		if ( false === $ts ) {
			return new \WP_Error( 'moksafocou_invalid_date', __( 'Invalid expiry date format; use YYYY-MM-DD.', 'moksafocou' ) );
		}
		// "Extend" must move expiry forward — a past date would expire every coupon
		// immediately (and strtotime of odd input can land on 1970).
		if ( $ts < strtotime( 'today' ) ) {
			return new \WP_Error( 'moksafocou_past_date', __( 'The extended expiry date cannot be earlier than today.', 'moksafocou' ) );
		}
		$ids = [];
		foreach ( $refs as $ref ) {
			$id = CouponService::resolve_id( $ref );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		if ( [] === $ids ) {
			return new \WP_Error( 'moksafocou_not_found', __( 'No matching coupons found.', 'moksafocou' ) );
		}
		return [
			'ids'     => $ids,
			'date'    => gmdate( 'Y-m-d', $ts ),
			'summary' => sprintf(
				/* translators: 1: count, 2: date. */
				__( 'Extend the expiry date of %1$d coupons to %2$s', 'moksafocou' ),
				count( $ids ),
				gmdate( 'Y-m-d', $ts )
			),
		];
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function extend_expiry_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$ids  = isset( $params['ids'] ) && is_array( $params['ids'] ) ? $params['ids'] : [];
		$date = (string) ( $params['date'] ?? '' );
		$done = 0;
		foreach ( $ids as $id ) {
			$result = CouponService::save( [ 'date_expires' => $date ], (int) $id );
			if ( ! ( $result instanceof \WP_Error ) ) {
				++$done;
			}
		}
		return [
			'reply' => sprintf(
				/* translators: %d: number updated. */
				__( 'Updated the expiry date of %d coupon(s).', 'moksafocou' ),
				$done
			),
		];
	}

	/* ---------------- duplicate ---------------- */

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function duplicate_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input = is_array( $input ) ? $input : [];
		$id    = CouponService::resolve_id( $input['code_or_id'] ?? '' );
		if ( ! $id ) {
			return new \WP_Error( 'moksafocou_not_found', __( 'Coupon not found.', 'moksafocou' ) );
		}
		$data = CouponService::get( $id );
		$code = (string) ( $data['code'] ?? $id );
		return [
			'source_id'   => $id,
			'source_code' => $code,
			/* translators: %s: source coupon code. */
			'summary'     => sprintf( __( 'Copy coupon %s into a new draft (keeping all settings and conditions, resetting the usage count to zero)', 'moksafocou' ), $code ),
		];
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function duplicate_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$source_id = (int) ( $params['source_id'] ?? 0 );
		$src       = new \WC_Coupon( $source_id );
		if ( ! $src->get_id() ) {
			return new \WP_Error( 'moksafocou_not_found', __( 'Coupon not found.', 'moksafocou' ) );
		}

		$new_code = self::unique_code( strtoupper( $src->get_code() ) . '-COPY-' );
		if ( '' === $new_code ) {
			return new \WP_Error( 'moksafocou_duplicate_failed', __( 'Could not generate a unique new code.', 'moksafocou' ) );
		}

		// New duplicate starts disabled (draft) for review before going live.
		$new_id = CouponService::duplicate( $source_id, $new_code, false );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		return [
			'id'    => (int) $new_id,
			'code'  => $new_code,
			'reply' => sprintf(
				/* translators: 1: source code, 2: new draft code. */
				__( 'Copied %1$s into draft %2$s (the usage count has been reset to zero; you can review it before enabling).', 'moksafocou' ),
				$src->get_code(),
				$new_code
			),
		];
	}

	/* ---------------- create tiered (friendly shortcut over create) ---------------- */

	/**
	 * Build a tiered percent coupon from a flat tiers spec and route it through create.
	 *
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create_tiered_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input = is_array( $input ) ? $input : [];
		$rows  = isset( $input['tiers'] ) && is_array( $input['tiers'] ) ? $input['tiers'] : [];
		if ( [] === $rows ) {
			return new \WP_Error( 'moksafocou_no_tiers', __( 'Please provide at least one tier.', 'moksafocou' ) );
		}
		$mode  = (string) ( $input['target_mode'] ?? 'cart' );
		$mode  = in_array( $mode, [ 'products', 'categories' ], true ) ? $mode : 'all';
		$basis = \Moksafocou\Support\Tiers::basis( $input['basis'] ?? 'subtotal' );

		$create = [
			'code'          => (string) ( $input['code'] ?? '' ),
			'discount_type' => 'percent',
			'amount'        => 0,
			'moksafocou'    => [
				'tiers' => [
					'enabled'           => true,
					'rows'              => $rows,
					'basis'             => $basis,
					'target_mode'       => $mode,
					'target_products'   => isset( $input['target_products'] ) && is_array( $input['target_products'] ) ? $input['target_products'] : [],
					'target_categories' => isset( $input['target_categories'] ) && is_array( $input['target_categories'] ) ? $input['target_categories'] : [],
				],
			],
		];
		foreach ( [ 'date_expires', 'usage_limit', 'usage_limit_per_user', 'description' ] as $k ) {
			if ( array_key_exists( $k, $input ) ) {
				$create[ $k ] = $input[ $k ];
			}
		}
		$result = self::create_prepare( $create );
		if ( is_array( $result ) && isset( $result['summary'] ) ) {
			$result['summary'] = sprintf(
				/* translators: 1: coupon code, 2: number of tiers. */
				__( 'Create a tiered discount coupon %1$s (%2$d tiers, giving different percentages by cart threshold)', 'moksafocou' ),
				(string) ( $create['code'] ?: __( '(auto code)', 'moksafocou' ) ),
				count( $rows )
			);
		}
		return $result;
	}

	/* ---------------- apply template ---------------- */

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function apply_template_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input = is_array( $input ) ? $input : [];
		$id    = isset( $input['template_id'] ) ? (string) $input['template_id'] : '';
		$tpl   = \Moksafocou\Modules\Templates\Catalog::get( $id );
		if ( null === $tpl ) {
			return new \WP_Error( 'moksafocou_template_unknown', __( 'Coupon template not found (use list-templates to see available IDs).', 'moksafocou' ) );
		}
		$overrides = isset( $input['overrides'] ) && is_array( $input['overrides'] ) ? $input['overrides'] : [];
		return [
			'template_id' => $id,
			'overrides'   => $overrides,
			'summary'     => sprintf(
				/* translators: %s: template label. */
				__( 'Apply template "%s" to create a draft coupon', 'moksafocou' ),
				(string) ( $tpl['label'] ?? $id )
			),
		];
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function apply_template_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$id        = (string) ( $params['template_id'] ?? '' );
		$overrides = isset( $params['overrides'] ) && is_array( $params['overrides'] ) ? $params['overrides'] : [];
		$result    = Applier::apply( $id, $overrides );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$coupon = new \WC_Coupon( (int) $result );
		return [
			'id'    => (int) $result,
			'reply' => sprintf(
				/* translators: 1: coupon code, 2: coupon id. */
				__( 'Created a draft coupon from the template %1$s (#%2$d); you can review it before enabling.', 'moksafocou' ),
				$coupon->get_code(),
				(int) $result
			),
		];
	}

	/* ---------------- restore (untrash) ---------------- */

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function restore_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input = is_array( $input ) ? $input : [];
		$ref   = trim( (string) ( $input['code_or_id'] ?? '' ) );
		$id    = ctype_digit( $ref ) ? (int) $ref : 0;
		$post  = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || 'shop_coupon' !== $post->post_type || 'trash' !== $post->post_status ) {
			return new \WP_Error( 'moksafocou_not_trashed', __( 'The specified coupon was not found in the trash; please provide the numeric ID of a deleted coupon.', 'moksafocou' ) );
		}
		return [
			'id'      => $id,
			'summary' => sprintf(
				/* translators: %s: coupon code. */
				__( 'Restore coupon %s from the trash as a draft (you can review it before enabling)', 'moksafocou' ),
				(string) ( $post->post_title ?: $id )
			),
		];
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function restore_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$id = (int) ( $params['id'] ?? 0 );
		if ( $id <= 0 || ! wp_untrash_post( $id ) ) {
			return new \WP_Error( 'moksafocou_restore_failed', __( 'Restore failed.', 'moksafocou' ) );
		}
		// Restore as a draft for review rather than whatever status it had before trashing.
		wp_update_post(
			[
				'ID'          => $id,
				'post_status' => 'draft',
			]
		);
		return [
			'id'    => $id,
			'reply' => __( 'Restored from the trash as a draft.', 'moksafocou' ),
		];
	}

	/* ---------------- expire now ---------------- */

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function expire_now_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input = is_array( $input ) ? $input : [];
		$refs  = isset( $input['codes_or_ids'] ) && is_array( $input['codes_or_ids'] ) ? $input['codes_or_ids'] : [];
		$ids   = [];
		foreach ( $refs as $ref ) {
			$id = CouponService::resolve_id( $ref );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		if ( [] === $ids ) {
			return new \WP_Error( 'moksafocou_not_found', __( 'No matching coupons found.', 'moksafocou' ) );
		}
		$yesterday = gmdate( 'Y-m-d', (int) strtotime( 'yesterday' ) );
		return [
			'ids'     => $ids,
			'date'    => $yesterday,
			'summary' => sprintf(
				/* translators: 1: count, 2: date. */
				__( 'Immediately disable %1$d coupon(s) (expiry date set to %2$s, effective at once)', 'moksafocou' ),
				count( $ids ),
				$yesterday
			),
		];
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function expire_now_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$ids  = isset( $params['ids'] ) && is_array( $params['ids'] ) ? $params['ids'] : [];
		$date = (string) ( $params['date'] ?? gmdate( 'Y-m-d', (int) strtotime( 'yesterday' ) ) );
		$done = 0;
		foreach ( $ids as $id ) {
			$result = CouponService::save( [ 'date_expires' => $date ], (int) $id );
			if ( ! ( $result instanceof \WP_Error ) ) {
				++$done;
			}
		}
		return [
			'reply' => sprintf(
				/* translators: %d: number expired. */
				__( 'Set %d coupon(s) to expire immediately.', 'moksafocou' ),
				$done
			),
		];
	}

	/* ---------------- bulk reschedule expiry (campaign management) ---------------- */

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function bulk_reschedule_prepare( $input ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$input = is_array( $input ) ? $input : [];
		$refs  = isset( $input['codes_or_ids'] ) && is_array( $input['codes_or_ids'] ) ? $input['codes_or_ids'] : [];
		$date  = trim( (string) ( $input['date_expires'] ?? '' ) );
		$ids   = [];
		foreach ( $refs as $ref ) {
			$id = CouponService::resolve_id( $ref );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		if ( [] === $ids ) {
			return new \WP_Error( 'moksafocou_not_found', __( 'No matching coupons found.', 'moksafocou' ) );
		}
		if ( '' !== $date ) {
			$ts = strtotime( $date );
			if ( ! $ts ) {
				return new \WP_Error( 'moksafocou_bad_date', __( 'Invalid expiry date format (use YYYY-MM-DD).', 'moksafocou' ) );
			}
			$date = gmdate( 'Y-m-d', $ts );
		}
		return [
			'ids'     => $ids,
			'date'    => $date,
			'summary' => '' === $date
				? sprintf(
					/* translators: %d: number of coupons. */
					__( 'Clear the expiry date of %d coupon(s) (make them permanent)', 'moksafocou' ),
					count( $ids )
				)
				: sprintf(
					/* translators: 1: number of coupons, 2: new expiry date. */
					__( 'Set the expiry date of %1$d coupon(s) to %2$s', 'moksafocou' ),
					count( $ids ),
					$date
				),
		];
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function bulk_reschedule_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return self::denied();
		}
		$ids  = isset( $params['ids'] ) && is_array( $params['ids'] ) ? $params['ids'] : [];
		$date = (string) ( $params['date'] ?? '' );
		$done = 0;
		foreach ( $ids as $id ) {
			$result = CouponService::save( [ 'date_expires' => $date ], (int) $id );
			if ( ! ( $result instanceof \WP_Error ) ) {
				++$done;
			}
		}
		return [
			'reply' => sprintf(
				/* translators: %d: number updated. */
				__( 'Updated the expiry date of %d coupon(s).', 'moksafocou' ),
				$done
			),
		];
	}

	private static function unique_code( string $prefix ): string {
		return CouponService::unique_code( $prefix );
	}
}
