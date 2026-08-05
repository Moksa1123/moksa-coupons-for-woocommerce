<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\StackingControl;

use Moksafocou\Coupon\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a coupon's stacking rules and decides — purely — whether the coupon may be
 * combined with the OTHER coupons already applied to the cart. The verdict logic
 * (stack_conflict) is WooCommerce-free and unit-tested.
 */
final class StackConfig {

	/**
	 * @param \WC_Coupon $coupon Coupon to read rules from.
	 * @return array{no_stack:bool,allowed:array<int,string>,disallowed:array<int,string>,msg:string}
	 */
	public static function read( \WC_Coupon $coupon ): array {
		return array(
			'no_stack'   => 'yes' === $coupon->get_meta( Keys::STACK_EXCLUDE, true ),
			'allowed'    => self::parse_codes( (string) $coupon->get_meta( Keys::STACK_ALLOWED, true ) ),
			'disallowed' => self::parse_codes( (string) $coupon->get_meta( Keys::STACK_DISALLOWED, true ) ),
			'msg'        => (string) $coupon->get_meta( Keys::STACK_MSG, true ),
		);
	}

	/**
	 * True when the coupon constrains stacking at all (so the validator can skip the
	 * cheaper coupons that set nothing).
	 *
	 * @param array{no_stack:bool,allowed:array<int,string>,disallowed:array<int,string>,msg?:string} $cfg Rules.
	 */
	public static function is_active( array $cfg ): bool {
		return $cfg['no_stack'] || array() !== $cfg['allowed'] || array() !== $cfg['disallowed'];
	}

	/**
	 * Parse a comma / newline separated code list into a normalized, de-duplicated,
	 * lowercased array (WC matches coupon codes case-insensitively).
	 *
	 * @param string $raw Raw textarea value.
	 * @return array<int,string>
	 */
	public static function parse_codes( string $raw ): array {
		$parts = preg_split( '/[\s,]+/', $raw );
		if ( false === $parts ) {
			return array();
		}
		$out = array();
		foreach ( $parts as $part ) {
			$code = self::normalize( $part );
			if ( '' !== $code && ! in_array( $code, $out, true ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * Normalize a single coupon code (lowercase), preferring WC's own formatter.
	 *
	 * @param string $code Raw code.
	 */
	public static function normalize( string $code ): string {
		$code = trim( $code );
		if ( '' === $code ) {
			return '';
		}
		return function_exists( 'wc_format_coupon_code' ) ? wc_format_coupon_code( $code ) : strtolower( $code );
	}

	/**
	 * Pure stacking verdict. Returns the conflicting OTHER coupon code, or null when
	 * the coupon may be combined with all of them. A conflict exists when ANY holds:
	 *   - this coupon disallows the other (blacklist),
	 *   - the other disallows this coupon,
	 *   - this coupon excludes-others and the other is not in this coupon's allow-list,
	 *   - the other excludes-others and this coupon is not in the other's allow-list.
	 *
	 * @param string                                                                                            $code       The coupon being validated (normalized).
	 * @param array{no_stack:bool,allowed:array<int,string>,disallowed:array<int,string>}                        $self_rules This coupon's rules.
	 * @param array<int,array{code:string,no_stack:bool,allowed:array<int,string>,disallowed:array<int,string>}> $others     Other applied coupons + rules.
	 */
	public static function stack_conflict( string $code, array $self_rules, array $others ): ?string {
		foreach ( $others as $o ) {
			$ocode = $o['code'];
			if ( $ocode === $code || '' === $ocode ) {
				continue;
			}
			if ( in_array( $ocode, $self_rules['disallowed'], true ) ) {
				return $ocode;
			}
			if ( in_array( $code, $o['disallowed'], true ) ) {
				return $ocode;
			}
			if ( $self_rules['no_stack'] && ! in_array( $ocode, $self_rules['allowed'], true ) ) {
				return $ocode;
			}
			if ( $o['no_stack'] && ! in_array( $code, $o['allowed'], true ) ) {
				return $ocode;
			}
		}
		return null;
	}
}
