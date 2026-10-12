<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\DiscountTiers;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\Tiers;

defined( 'ABSPATH' ) || exit;

/**
 * Where a cart stands on every tiered coupon applied to it: which tier it is on now, and what the
 * next one costs.
 *
 * One computation, read by three surfaces — the classic cart / checkout nudge row, the Store API
 * cart extension the block cart and checkout render from, and the coupon label on both. Before
 * this existed the nudge recomputed the ladder on its own and the block cart could not see it at
 * all; three copies of "which tier am I on" is how a customer ends up looking at a nudge that
 * disagrees with the discount line beside it.
 *
 * Every string here is final, translated, plain text. The block front end only places them, so
 * it carries no copy of the wording, the currency format or the 折 arithmetic.
 */
final class State {

	/**
	 * Per applied tiered coupon, keyed by coupon code.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return array<string,array{
	 *     basis:string,
	 *     current:array{kind:string,value:float,label:string}|null,
	 *     next:array{threshold:float,gap:float,progress:float,kind:string,value:float,label:string,gap_text:string,message:string}|null
	 * }>
	 */
	public static function for_cart( \WC_Cart $cart ): array {
		list( $subtotal, $qty, $weight ) = Engine::measure( $cart );

		$out = array();
		foreach ( $cart->get_coupons() as $coupon ) {
			if ( ! $coupon instanceof \WC_Coupon || 'percent' !== $coupon->get_discount_type() ) {
				continue;
			}
			if ( 'yes' !== $coupon->get_meta( Keys::TIERS_ENABLED, true ) ) {
				continue;
			}
			// Only tiers in effect today: a row that starts next month must not be advertised as
			// the next thing to reach for.
			$tiers = Tiers::in_effect( Tiers::parse( (string) $coupon->get_meta( Keys::TIERS, true ) ) );
			if ( array() === $tiers ) {
				continue;
			}

			$basis   = Tiers::basis( $coupon->get_meta( Keys::TIERS_BASIS, true ) );
			$measure = 'quantity' === $basis ? (float) $qty : ( 'weight' === $basis ? $weight : $subtotal );
			$mode    = (string) $coupon->get_meta( Keys::TIERS_TARGET_MODE, true );
			$mode    = in_array( $mode, array( 'products', 'categories' ), true ) ? $mode : 'all';
			$base    = Engine::targeted_subtotal(
				$cart,
				$mode,
				self::ids( $coupon->get_meta( Keys::TIERS_TARGET_PRODUCTS, true ) ),
				self::ids( $coupon->get_meta( Keys::TIERS_TARGET_CATEGORIES, true ) )
			);

			$out[ $coupon->get_code() ] = array(
				'basis'   => $basis,
				'current' => self::current( $coupon->get_code(), $tiers, $measure, $base ),
				'next'    => self::next( $tiers, $measure, $base, $basis ),
			);
		}
		return $out;
	}

	/**
	 * The tier the cart is on, as the Engine actually applied it.
	 *
	 * Read from the Engine first, because the percent it applies has been through the
	 * moksafocou_tier_percent filter (a VIP bump, say) and the label must name the discount the
	 * customer is getting, not the one printed in the ladder. Falls back to resolving the ladder
	 * when totals have not been calculated in this request.
	 *
	 * @param string                                                    $code    Coupon code.
	 * @param array<int,array{threshold:float,kind:string,value:float}> $tiers   Tiers in effect.
	 * @param float                                                     $measure The cart on the ladder's basis.
	 * @param float                                                     $base    Discountable subtotal.
	 * @return array{kind:string,value:float,label:string}|null Null below every tier.
	 */
	private static function current( string $code, array $tiers, float $measure, float $base ): ?array {
		$active = Engine::active( $code );
		if ( null !== $active ) {
			$kind  = $active['kind'];
			$value = $active['value'];
		} else {
			$resolved = Tiers::resolve( $tiers, $measure, $base );
			$kind     = (string) $resolved['kind'];
			$value    = (float) $resolved['value'];
		}
		if ( $value <= 0.0 ) {
			return null;
		}
		return array(
			'kind'  => $kind,
			'value' => $value,
			'label' => self::deal_label( $kind, $value ),
		);
	}

	/**
	 * @param array<int,array{threshold:float,kind:string,value:float}> $tiers
	 * @return array{threshold:float,gap:float,progress:float,kind:string,value:float,label:string,gap_text:string,message:string}|null
	 */
	private static function next( array $tiers, float $measure, float $base, string $basis ): ?array {
		$next = Tiers::next_tier( $tiers, $measure, $base );
		if ( null === $next ) {
			return null;
		}

		$gap_text = self::gap_text( $basis, (float) $next['gap'] );
		// Named the same way as the coupon line ("82 折"), not as a percentage. A cart reading
		// "85 折" on the coupon and "upgrade to 18%" one line below speaks two vocabularies at once,
		// and the customer has to do the subtraction to see they are the same thing.
		$deal = sprintf(
			/* translators: %s: the next tier, e.g. "82 折", "18% off" or "NT$100 off". */
			__( 'upgrade to %s', 'moksa-coupons-for-woocommerce' ),
			self::deal_label( (string) $next['kind'], (float) $next['value'] )
		);

		return array(
			'threshold' => (float) $next['threshold'],
			'gap'       => (float) $next['gap'],
			// How far along the way to the next tier, 0..1, measured from the tier the cart is on
			// (or from zero below every tier) rather than from nothing: a cart at NT$1,200 heading
			// for NT$1,399 is nearly there, not 86% of the way from an empty basket.
			'progress'  => self::progress( $tiers, $measure, (float) $next['threshold'] ),
			'kind'      => (string) $next['kind'],
			'value'     => (float) $next['value'],
			'label'     => self::deal_label( (string) $next['kind'], (float) $next['value'] ),
			'gap_text'  => $gap_text,
			'message'   => sprintf(
				/* translators: 1: amount/qty/weight to add, 2: the upgraded deal. */
				__( '%1$s,%2$s!', 'moksa-coupons-for-woocommerce' ),
				$gap_text,
				$deal
			),
		);
	}

	/**
	 * @param array<int,array{threshold:float,kind:string,value:float}> $tiers
	 */
	private static function progress( array $tiers, float $measure, float $target ): float {
		$floor = 0.0;
		foreach ( $tiers as $tier ) {
			$t = (float) $tier['threshold'];
			if ( $t <= $measure && $t > $floor ) {
				$floor = $t;
			}
		}
		$span = $target - $floor;
		if ( $span <= 0.0 ) {
			return 1.0;
		}
		return max( 0.0, min( 1.0, ( $measure - $floor ) / $span ) );
	}

	/**
	 * A tier as the customer would say it.
	 *
	 * Taiwanese shops and customers talk in 折 — the share that is PAID, so 18% off is 82 折 and
	 * 20% off is 8 折. A checkout line reading "−NT$247" next to a coupon called 全站優惠 made
	 * customers ask why it was not 85 折, when the cart had already climbed to 82 折; naming the tier
	 * answers that before it is asked. The arithmetic cannot live in a .po file, so Chinese locales
	 * are computed here and every other locale gets the translatable "18% off".
	 */
	public static function deal_label( string $kind, float $value ): string {
		$zh = 0 === strpos( (string) determine_locale(), 'zh' );

		if ( 'fixed' === $kind ) {
			$label = sprintf(
				/* translators: %s: fixed discount amount. */
				__( '%s off', 'moksa-coupons-for-woocommerce' ),
				self::money( $value )
			);
		} elseif ( $zh ) {
			$paid = 100.0 - $value;
			if ( $paid <= 0.0 ) {
				$label = __( 'Free', 'moksa-coupons-for-woocommerce' );
			} elseif ( abs( $paid - round( $paid ) ) < 0.001 && 0 === ( (int) round( $paid ) ) % 10 ) {
				// 80 → 8 折, 90 → 9 折: a round ten is said with one digit.
				$label = ( (int) round( $paid ) / 10 ) . ' 折';
			} else {
				$label = self::trim( $paid ) . ' 折';
			}
		} else {
			$label = sprintf(
				/* translators: %s: discount percent. */
				__( '%s%% off', 'moksa-coupons-for-woocommerce' ),
				self::trim( $value )
			);
		}

		/**
		 * Filter how a tier is named on the coupon line and in the nudge.
		 *
		 * @param string $label Display label, e.g. "82 折" or "18% off".
		 * @param string $kind  percent | fixed.
		 * @param float  $value Percent off, or the fixed amount.
		 */
		return (string) apply_filters( 'moksafocou_tier_deal_label', $label, $kind, $value );
	}

	/** "Add NT$X more to the cart", phrased for the ladder's basis. */
	private static function gap_text( string $basis, float $gap ): string {
		if ( 'quantity' === $basis ) {
			/* translators: %s: number of additional items. */
			return sprintf( __( 'Add %s more item(s) to the cart', 'moksa-coupons-for-woocommerce' ), self::trim( ceil( $gap ) ) );
		}
		if ( 'weight' === $basis ) {
			/* translators: %s: additional weight in kg. */
			return sprintf( __( 'Add %s more kg to the cart', 'moksa-coupons-for-woocommerce' ), self::trim( $gap ) );
		}
		/* translators: %s: additional spend. */
		return sprintf( __( 'Add %s more to the cart', 'moksa-coupons-for-woocommerce' ), self::money( $gap ) );
	}

	/** wc_price() as plain text — these strings travel through JSON to the block front end. */
	private static function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	private static function trim( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * @param mixed $value
	 * @return array<int,int>
	 */
	private static function ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', $value ), static fn( int $id ): bool => $id > 0 ) );
	}
}
