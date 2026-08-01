<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\Bogo;

use Moksafocou\Support\PriceMath;

defined( 'ABSPATH' ) || exit;

/**
 * Pure BOGO math — the heart of the engine, deliberately free of WooCommerce so it
 * is fully unit-testable. The Frontend resolves cart lines into the abstract
 * {key, qty, price, role} shape (role: 'trigger' takes precedence over 'reward',
 * so a product listed in both pools counts as a trigger and the same-product deal
 * is naturally out of scope for v1) and feeds them here.
 *
 * compute() returns how many trigger+reward bundles fire and, per reward line, how
 * many units are discounted and the BLENDED per-unit price to set_price() — so a
 * single mixed line (some full-price, some discounted units) totals correctly and
 * can never go negative.
 */
final class BogoCalc {

	public const MODES      = array( 'percent', 'fixed_per_item', 'free' );
	public const DEAL_MODES = array( 'once', 'repeat' );

	/**
	 * @param array{trigger_qty:int,reward_qty:int,reward_mode:string,reward_value:float,deal_mode:string,repeat_limit:int} $cfg
	 * @param array<int,array{key:string,qty:int,price:float,role:string}>                                                  $lines
	 * @return array{trigger_met:bool,bundles:int,reward_units:int,reward_short:bool,total_discount:float,rewards:array<string,array{disc_qty:int,unit_discount:float,blended_price:float}>}
	 */
	public static function compute( array $cfg, array $lines ): array {
		$n         = max( 1, (int) ( $cfg['trigger_qty'] ?? 1 ) );
		$m         = max( 1, (int) ( $cfg['reward_qty'] ?? 1 ) );
		$mode      = in_array( $cfg['reward_mode'] ?? '', self::MODES, true ) ? $cfg['reward_mode'] : 'percent';
		$value     = (float) ( $cfg['reward_value'] ?? 0 );
		$deal_mode = 'repeat' === ( $cfg['deal_mode'] ?? 'once' ) ? 'repeat' : 'once';
		$repeat    = max( 0, (int) ( $cfg['repeat_limit'] ?? 0 ) );

		$trigger_units = 0;
		$reward_units  = 0;
		$reward_lines  = array();
		foreach ( $lines as $line ) {
			$qty = max( 0, (int) ( $line['qty'] ?? 0 ) );
			if ( 'trigger' === ( $line['role'] ?? '' ) ) {
				$trigger_units += $qty;
			} elseif ( 'reward' === ( $line['role'] ?? '' ) ) {
				$reward_units  += $qty;
				$reward_lines[] = array(
					'key'   => (string) $line['key'],
					'qty'   => $qty,
					'price' => max( 0.0, (float) ( $line['price'] ?? 0 ) ),
				);
			}
		}

		$trigger_met     = $trigger_units >= $n;
		$bundles_trigger = intdiv( $trigger_units, $n );
		$bundles_reward  = intdiv( $reward_units, $m );
		$bundles         = min( $bundles_trigger, $bundles_reward );
		if ( 'once' === $deal_mode ) {
			$bundles = min( $bundles, 1 );
		} elseif ( $repeat > 0 ) {
			$bundles = min( $bundles, $repeat );
		}

		// Trigger qualifies for at least one bundle but there is no reward to discount.
		$reward_short = $trigger_met && $bundles_trigger >= 1 && $bundles_reward < 1;

		$units_to_discount = $bundles * $m;
		$rewards           = array();
		$total_discount    = 0.0;

		if ( $units_to_discount > 0 ) {
			// Cheapest reward units first (store-favorable / lesser-value-free), stable.
			usort(
				$reward_lines,
				static fn( array $a, array $b ): int => $a['price'] <=> $b['price']
			);

			$remaining = $units_to_discount;
			foreach ( $reward_lines as $line ) {
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
			'trigger_met'    => $trigger_met,
			'bundles'        => $bundles,
			'reward_units'   => $units_to_discount,
			'reward_short'   => $reward_short,
			'total_discount' => $total_discount,
			'rewards'        => $rewards,
		);
	}

	/**
	 * Per-unit discount for a reward unit, clamped to the unit price. Thin facade over
	 * {@see PriceMath::unit_discount} (kept for the existing public API + its unit tests).
	 */
	public static function unit_discount( string $mode, float $value, float $price ): float {
		return PriceMath::unit_discount( $mode, $value, $price );
	}
}
