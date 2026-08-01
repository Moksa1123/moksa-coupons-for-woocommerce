<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\MixMatch;

use Moksafocou\Support\PriceMath;

defined( 'ABSPATH' ) || exit;

/**
 * Pure "mix & match" bundle-pricing math (任選 N 件 $X / 任選 N 件 Y 折), free of WooCommerce so it
 * is fully unit-tested. A single member pool: every N units forms a bundle priced as a fixed total
 * or an across-the-board percent off.
 *
 * Opposite ordering to Nth-item / BOGO: the PRICIEST units are taken into a bundle first (bundle
 * convention — the customer's most valuable items make the set). fixed_total prices the bundle at
 * EXACTLY the advertised total when the bundle's items are worth more (a single proportional factor
 * scales every bundle unit, so the sum is conserved and no unit is ever priced above its original);
 * when the items are worth less than the total, the customer simply pays their (lower) sum.
 *
 * compute() returns, per cart-item-key, how many of its units are bundle-priced and the BLENDED
 * per-unit price to set_price() — so a mixed line (some full-price, some bundled) totals correctly
 * and can never go negative.
 */
final class MixMatchCalc {

	public const PRICE_MODES = array( 'fixed_total', 'percent' );
	public const DEAL_MODES  = array( 'once', 'repeat' );

	/**
	 * @param array{qty:int,price_mode:string,price_value:float,deal_mode:string,repeat_limit:int} $cfg
	 * @param array<int,array{key:string,qty:int,price:float}>                                      $lines Member cart lines.
	 * @return array{member_units:int,bundles:int,priced_units:int,short:bool,total_discount:float,rewards:array<string,array{disc_qty:int,unit_discount:float,blended_price:float}>}
	 */
	public static function compute( array $cfg, array $lines ): array {
		$n         = max( 1, (int) ( $cfg['qty'] ?? 1 ) );
		$mode      = in_array( $cfg['price_mode'] ?? '', self::PRICE_MODES, true ) ? (string) $cfg['price_mode'] : 'fixed_total';
		$value     = max( 0.0, (float) ( $cfg['price_value'] ?? 0 ) );
		$deal_mode = 'once' === ( $cfg['deal_mode'] ?? 'repeat' ) ? 'once' : 'repeat';
		$repeat    = max( 0, (int) ( $cfg['repeat_limit'] ?? 0 ) );

		$pool         = array();
		$member_units = 0;
		foreach ( $lines as $line ) {
			$qty = max( 0, (int) ( $line['qty'] ?? 0 ) );
			if ( $qty <= 0 ) {
				continue;
			}
			$member_units += $qty;
			$pool[]        = array(
				'key'   => (string) $line['key'],
				'qty'   => $qty,
				'price' => max( 0.0, (float) ( $line['price'] ?? 0 ) ),
			);
		}

		$bundles = intdiv( $member_units, $n );
		if ( 'once' === $deal_mode ) {
			$bundles = min( $bundles, 1 );
		} elseif ( $repeat > 0 ) {
			$bundles = min( $bundles, $repeat );
		}
		$priced_units = max( 0, $bundles * $n );

		$rewards        = array();
		$total_discount = 0.0;
		if ( $priced_units > 0 ) {
			// Priciest units first into the bundle.
			usort( $pool, static fn( array $a, array $b ): int => $b['price'] <=> $a['price'] );

			// Pass 1: choose which units are bundled (priciest first) and total their original price.
			$remaining = $priced_units;
			$taken     = array(); // pool index => unit count taken.
			$sum_taken = 0.0;
			foreach ( $pool as $idx => $line ) {
				if ( $remaining <= 0 ) {
					break;
				}
				$take = min( $line['qty'], $remaining );
				if ( $take <= 0 ) {
					continue;
				}
				$taken[ $idx ] = $take;
				$sum_taken    += $line['price'] * $take;
				$remaining    -= $take;
			}

			// fixed_total: one proportional factor scales every bundled unit so the bundles cost
			// EXACTLY bundles × price_value (sum conserved); when the items are worth less, the
			// factor is 1 (no discount, never charge more than the items are worth).
			$factor = 1.0;
			if ( 'fixed_total' === $mode ) {
				$target = min( $sum_taken, $bundles * $value );
				$factor = $sum_taken > 0.0 ? $target / $sum_taken : 1.0;
			}

			// Pass 2: blend each bundled line.
			foreach ( $taken as $idx => $take ) {
				$line          = $pool[ $idx ];
				$unit_discount = 'percent' === $mode
					? $line['price'] * ( min( 100.0, $value ) / 100 )
					: $line['price'] * ( 1 - $factor );
				$unit_discount = max( 0.0, min( $line['price'], $unit_discount ) );

				$rewards[ $line['key'] ] = array(
					'disc_qty'      => $take,
					'unit_discount' => $unit_discount,
					'blended_price' => PriceMath::blended_price( $line['price'], $unit_discount, $take, (int) $line['qty'] ),
				);
				$total_discount         += $unit_discount * $take;
			}
		}

		return array(
			'member_units'   => $member_units,
			'bundles'        => $bundles,
			'priced_units'   => $priced_units,
			// Members present but not enough for a single bundle (drives the "再選 X 件" notice).
			'short'          => $member_units > 0 && $bundles < 1,
			'total_discount' => $total_discount,
			'rewards'        => $rewards,
		);
	}
}
