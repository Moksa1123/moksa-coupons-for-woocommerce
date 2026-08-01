<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers for tiered discounts: parse / validate / canonicalise the tier rows, pick the
 * active tier for a cart, and decide whether a cart line is in scope. No WordPress or
 * WooCommerce dependency, so the selection maths is fully unit-tested.
 *
 * A tier ladder is measured against a chosen BASIS — the cart's subtotal, total quantity or
 * total weight (set-level, see {@see Tiers::BASES}). Each tier row is
 * { threshold: float, kind: 'percent'|'fixed', value: float }: when the cart's basis metric
 * reaches `threshold`, the tier gives either `value`% off or a fixed `value` currency amount.
 * Tiers may freely mix the two kinds; among the tiers a cart qualifies for, the one giving the
 * LARGEST actual discount wins (customer-friendly, comparable across kinds, order-independent).
 *
 * Backward compatibility: coupons saved before this redesign stored rows as
 * { min_subtotal, min_qty, percent }; parse() reads those as subtotal-basis percent tiers.
 */
final class Tiers {

	/** @var array<int,string> Allowed ladder bases. */
	public const BASES = array( 'subtotal', 'quantity', 'weight' );

	/**
	 * Coerce raw input (a JSON string, or an array of rows) into a clean, validated list of
	 * tier rows. A 'percent' value must be within (0, 100]; a 'fixed' value must be > 0; invalid
	 * rows are dropped and the threshold clamps to >= 0. Returns a 0-indexed list.
	 *
	 * @param mixed $raw
	 * @return array<int,array{threshold:float,kind:string,value:float}>
	 */
	public static function parse( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rows = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( array_key_exists( 'threshold', $row ) ) {
				$threshold = max( 0.0, (float) $row['threshold'] );
			} else {
				// Legacy { min_subtotal, min_qty }: prefer the subtotal, else the quantity.
				$ms        = isset( $row['min_subtotal'] ) ? (float) $row['min_subtotal'] : 0.0;
				$mq        = isset( $row['min_qty'] ) ? (float) $row['min_qty'] : 0.0;
				$threshold = max( 0.0, $ms > 0.0 ? $ms : $mq );
			}
			$kind  = ( isset( $row['kind'] ) && 'fixed' === $row['kind'] ) ? 'fixed' : 'percent';
			$value = isset( $row['value'] ) ? (float) $row['value'] : ( isset( $row['percent'] ) ? (float) $row['percent'] : 0.0 );
			if ( 'percent' === $kind ) {
				if ( $value <= 0.0 || $value > 100.0 ) {
					continue;
				}
			} elseif ( $value <= 0.0 ) {
				continue;
			}
			$rows[] = array(
				'threshold' => $threshold,
				'kind'      => $kind,
				'value'     => $value,
			);
		}
		return $rows;
	}

	/** Normalise a basis string to one of BASES (defaults to subtotal). */
	public static function basis( $raw ): string {
		$raw = is_string( $raw ) ? $raw : '';
		return in_array( $raw, self::BASES, true ) ? $raw : 'subtotal';
	}

	/**
	 * Canonical JSON string for storage (parsed + validated). '' when there are no valid rows
	 * so an empty value clears the meta.
	 *
	 * @param mixed $raw
	 */
	public static function canonical_json( $raw ): string {
		$rows = self::parse( $raw );
		if ( array() === $rows ) {
			return '';
		}
		$encoded = wp_json_encode( $rows );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Resolve the active tier for a cart: among tiers whose threshold the cart's basis metric
	 * ($measure) has reached, the one yielding the LARGEST actual discount on $base (the
	 * discountable / targeted subtotal). A percent tier gives $base × value / 100; a fixed tier
	 * gives min(value, $base). Returns the winning tier's kind + value + computed amount; amount
	 * 0 when none qualify.
	 *
	 * @param array<int,array{threshold:float,kind:string,value:float}> $tiers
	 * @return array{kind:string,value:float,amount:float}
	 */
	public static function resolve( array $tiers, float $measure, float $base ): array {
		$best = array(
			'kind'   => 'percent',
			'value'  => 0.0,
			'amount' => 0.0,
		);
		foreach ( $tiers as $tier ) {
			if ( $measure + Rules::EPSILON < (float) $tier['threshold'] ) {
				continue;
			}
			$kind   = ( ( $tier['kind'] ?? 'percent' ) === 'fixed' ) ? 'fixed' : 'percent';
			$value  = (float) $tier['value'];
			$amount = 'percent' === $kind ? ( $base * $value / 100.0 ) : min( $value, $base );
			if ( $amount > $best['amount'] ) {
				$best = array(
					'kind'   => $kind,
					'value'  => $value,
					'amount' => $amount,
				);
			}
		}
		return $best;
	}

	/**
	 * The nearest better tier a cart can still reach by increasing its basis metric, plus the
	 * gap to it. Returns null when the cart already holds the best tier. Drives the cart/checkout
	 * progress nudge.
	 *
	 * @param array<int,array{threshold:float,kind:string,value:float}> $tiers
	 * @return array{threshold:float,kind:string,value:float,gap:float}|null
	 */
	public static function next_tier( array $tiers, float $measure, float $base ): ?array {
		$current = self::resolve( $tiers, $measure, $base )['amount'];
		$best    = null;
		foreach ( $tiers as $tier ) {
			$threshold = (float) $tier['threshold'];
			if ( $measure + Rules::EPSILON >= $threshold ) {
				continue; // already reached.
			}
			$kind   = ( ( $tier['kind'] ?? 'percent' ) === 'fixed' ) ? 'fixed' : 'percent';
			$value  = (float) $tier['value'];
			$amount = 'percent' === $kind ? ( $base * $value / 100.0 ) : min( $value, $base );
			if ( $amount <= $current ) {
				continue; // not actually a better deal at this cart.
			}
			$gap = $threshold - $measure;
			if ( null === $best || $gap < $best['gap'] ) {
				$best = array(
					'threshold' => $threshold,
					'kind'      => $kind,
					'value'     => $value,
					'gap'       => $gap,
				);
			}
		}
		return $best;
	}

	/**
	 * Per-line discount for a percent tier: the line's discountable amount × percent / 100.
	 */
	public static function line_discount( float $discounting_amount, float $percent ): float {
		if ( $percent <= 0.0 || $discounting_amount <= 0.0 ) {
			return 0.0;
		}
		return $discounting_amount * $percent / 100.0;
	}

	/**
	 * A line's proportional share of a fixed-amount tier: distribute the (capped) fixed total
	 * across the targeted lines in proportion to each line's discountable amount.
	 */
	public static function fixed_line_share( float $discounting_amount, float $base, float $fixed_value ): float {
		if ( $discounting_amount <= 0.0 || $base <= 0.0 ) {
			return 0.0;
		}
		$total = min( $fixed_value, $base );
		return $total * ( $discounting_amount / $base );
	}

	/**
	 * Whether a cart line is within the discount scope. 'all' = every line; 'products' = the
	 * product (or its variation) is listed; 'categories' = the product is in a listed category.
	 *
	 * @param string         $mode              Scope mode (all|products|categories).
	 * @param array<int,int> $target_products   Listed product / variation ids.
	 * @param array<int,int> $target_categories Listed category term ids.
	 * @param int            $product_id        The line's product id.
	 * @param int            $variation_id      The line's variation id (0 if none).
	 * @param array<int,int> $product_cat_ids   The product's category term ids.
	 */
	public static function is_targeted( string $mode, array $target_products, array $target_categories, int $product_id, int $variation_id, array $product_cat_ids ): bool {
		if ( 'products' === $mode ) {
			return in_array( $product_id, $target_products, true ) || ( $variation_id > 0 && in_array( $variation_id, $target_products, true ) );
		}
		if ( 'categories' === $mode ) {
			return array() !== array_intersect( $product_cat_ids, $target_categories );
		}
		return true;
	}
}
