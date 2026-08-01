<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\NthItem;

use Moksafocou\Support\PriceMath;

defined( 'ABSPATH' ) || exit;

/**
 * Pure "Nth-item discount" math (第二件6折 / 第 N 件折扣), free of WooCommerce so it is fully
 * unit-tested. A single pool of in-set items: every N items forms a set and the Nth item of each
 * set is discounted. By convention the CHEAPEST units are the discounted ones (store-favorable,
 * the same direction as BOGO's "lesser-value free").
 *
 * Differs from BOGO: BOGO is "buy X get a DIFFERENT Y"; this is one pool where the item being
 * bought IS the item being discounted. group_by 'cart' pools every in-set line together; 'product'
 * runs the same maths independently per product line.
 *
 * compute() returns, per cart-item-key, how many of its units are discounted and the BLENDED
 * per-unit price to set_price() — so a mixed line (some full-price, some discounted) totals
 * correctly and can never go negative.
 */
final class NthItemCalc {

	public const MODES      = array( 'percent', 'fixed_per_item', 'free' );
	public const DEAL_MODES = array( 'once', 'repeat' );

	/**
	 * @param array{n:int,reward_mode:string,reward_value:float,deal_mode:string,repeat_limit:int,group_by:string} $cfg
	 * @param array<int,array{key:string,qty:int,price:float}>                                                     $lines In-set cart lines.
	 * @return array{units:int,sets:int,discount_units:int,short:bool,total_discount:float,rewards:array<string,array{disc_qty:int,unit_discount:float,blended_price:float}>}
	 */
	public static function compute( array $cfg, array $lines ): array {
		$n         = max( 2, (int) ( $cfg['n'] ?? 2 ) );
		$mode      = in_array( $cfg['reward_mode'] ?? '', self::MODES, true ) ? (string) $cfg['reward_mode'] : 'percent';
		$value     = (float) ( $cfg['reward_value'] ?? 0 );
		$deal_mode = 'repeat' === ( $cfg['deal_mode'] ?? 'repeat' ) ? 'repeat' : 'once';
		$repeat    = max( 0, (int) ( $cfg['repeat_limit'] ?? 0 ) );
		$group_by  = 'product' === ( $cfg['group_by'] ?? 'cart' ) ? 'product' : 'cart';

		$pools = 'product' === $group_by
			? array_map( static fn( array $line ): array => array( $line ), $lines )
			: array( $lines );

		$units          = 0;
		$sets           = 0;
		$discount_units = 0;
		$total_discount = 0.0;
		$rewards        = array();

		foreach ( $pools as $pool ) {
			$result          = self::compute_pool( $pool, $n, $mode, $value, $deal_mode, $repeat );
			$units          += $result['units'];
			$sets           += $result['sets'];
			$discount_units += $result['discount_units'];
			$total_discount += $result['total_discount'];
			foreach ( $result['rewards'] as $key => $reward ) {
				$rewards[ $key ] = $reward;
			}
		}

		return array(
			'units'          => $units,
			'sets'           => $sets,
			'discount_units' => $discount_units,
			// In-set items present but not yet enough for one set (drives the "再買 X 件" notice).
			'short'          => $units > 0 && $discount_units < 1,
			'total_discount' => $total_discount,
			'rewards'        => $rewards,
		);
	}

	/**
	 * One pool: count units, decide how many sets (and therefore discounted units) fire, then apply
	 * the discount to the cheapest units first, blending per cart-item-key.
	 *
	 * @param array<int,array{key:string,qty:int,price:float}> $lines
	 * @return array{units:int,sets:int,discount_units:int,total_discount:float,rewards:array<string,array{disc_qty:int,unit_discount:float,blended_price:float}>}
	 */
	private static function compute_pool( array $lines, int $n, string $mode, float $value, string $deal_mode, int $repeat ): array {
		$pool  = array();
		$units = 0;
		foreach ( $lines as $line ) {
			$qty = max( 0, (int) ( $line['qty'] ?? 0 ) );
			if ( $qty <= 0 ) {
				continue;
			}
			$units += $qty;
			$pool[] = array(
				'key'   => (string) $line['key'],
				'qty'   => $qty,
				'price' => max( 0.0, (float) ( $line['price'] ?? 0 ) ),
			);
		}

		$sets = intdiv( $units, $n );
		if ( 'once' === $deal_mode ) {
			$sets = min( $sets, 1 );
		} elseif ( $repeat > 0 ) {
			$sets = min( $sets, $repeat );
		}
		$discount_units = max( 0, $sets ); // one discounted item per set.

		$rewards        = array();
		$total_discount = 0.0;
		if ( $discount_units > 0 ) {
			// Cheapest units first (the discount lands on the lower-priced items).
			usort( $pool, static fn( array $a, array $b ): int => $a['price'] <=> $b['price'] );
			$remaining = $discount_units;
			foreach ( $pool as $line ) {
				if ( $remaining <= 0 ) {
					break;
				}
				$take = min( $line['qty'], $remaining );
				if ( $take <= 0 ) {
					continue;
				}
				$unit_discount           = self::unit_discount( $mode, $value, $line['price'] );
				$rewards[ $line['key'] ] = array(
					'disc_qty'      => $take,
					'unit_discount' => $unit_discount,
					'blended_price' => PriceMath::blended_price( $line['price'], $unit_discount, $take, (int) $line['qty'] ),
				);
				$total_discount         += $unit_discount * $take;
				$remaining              -= $take;
			}
		}

		return array(
			'units'          => $units,
			'sets'           => $sets,
			'discount_units' => $discount_units,
			'total_discount' => $total_discount,
			'rewards'        => $rewards,
		);
	}

	/**
	 * Per-unit discount for a discounted item, clamped to the unit price. Thin facade over
	 * {@see PriceMath::unit_discount} (kept for the existing public API + its unit tests).
	 */
	public static function unit_discount( string $mode, float $value, float $price ): float {
		return PriceMath::unit_discount( $mode, $value, $price );
	}
}
