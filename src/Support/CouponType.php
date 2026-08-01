<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Customer-facing label for a coupon discount-type slug. Kept as a thin, widely-used facade
 * over {@see DiscountTypeRegistry} (the single registry of all type metadata) so a raw slug
 * like "moksafocou_cashback" can never leak to a customer. Unknown types fall back to
 * WooCommerce's own registered label, then to the raw slug only as a last resort.
 */
final class CouponType {

	/** @return array<string,string> discount_type slug => label. */
	public static function labels(): array {
		return DiscountTypeRegistry::labels();
	}

	public static function label( string $type ): string {
		return DiscountTypeRegistry::label( $type );
	}
}
