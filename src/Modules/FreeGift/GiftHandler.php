<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\FreeGift;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-adds a coupon's free-gift product to the cart when the coupon is applied
 * (classic AND Block/Store-API, both via woocommerce_applied_coupon), prices it via
 * set_price from a base captured at add time (idempotent), removes it when the coupon
 * is removed or orphaned, and locks the gift line so the shopper cannot change its
 * quantity or remove it. The gift line is identified by the ITEM_FLAG cart-item meta.
 */
final class GiftHandler {

	/** @var bool Guards the orphan-cleanup against re-entrancy (remove_cart_item recalcs). */
	private static bool $cleaning = false;

	public static function boot(): void {
		add_action( 'woocommerce_applied_coupon', array( self::class, 'on_applied' ), 10, 1 );
		add_action( 'woocommerce_removed_coupon', array( self::class, 'on_removed' ), 10, 1 );
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'cleanup_orphans' ), 19, 1 );
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'price_gifts' ), 20, 1 );

		add_filter( 'woocommerce_cart_item_quantity', array( self::class, 'lock_qty' ), 10, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( self::class, 'prevent_qty_change' ), 10, 4 );
		add_filter( 'woocommerce_store_api_product_quantity_minimum', array( self::class, 'lock_store_qty' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( self::class, 'lock_store_qty' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_remove_link', array( self::class, 'hide_remove' ), 10, 2 );
	}

	/* ---------------- add / remove ---------------- */

	/**
	 * @param mixed $code Coupon code.
	 */
	public static function on_applied( $code ): void {
		$code = (string) $code;
		if ( '' === $code || ! self::cart() ) {
			return;
		}
		$id = function_exists( 'wc_get_coupon_id_by_code' ) ? (int) wc_get_coupon_id_by_code( $code ) : 0;
		if ( ! $id ) {
			return;
		}
		$cfg = GiftConfig::read( $id );
		if ( ! GiftConfig::is_active( $cfg ) || null !== self::find_gift_key( $code ) ) {
			return; // Not a gift coupon, or the gift is already in the cart.
		}

		$product = wc_get_product( $cfg['product_id'] );
		if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}

		$product_id   = $cfg['product_id'];
		$variation_id = 0;
		if ( 'product_variation' === get_post_type( $product_id ) ) {
			$variation_id = $product_id;
			$product_id   = (int) wp_get_post_parent_id( $variation_id );
		}

		$item_data = array(
			GiftConfig::ITEM_FLAG  => $code,
			GiftConfig::ITEM_QTY   => $cfg['qty'],
			GiftConfig::ITEM_BASE  => (float) $product->get_price(),
			GiftConfig::ITEM_MODE  => $cfg['mode'],
			GiftConfig::ITEM_VALUE => $cfg['value'],
		);

		// Avoid a premature totals recalc during add_to_cart (mirrors WC core's own pattern).
		self::suppress_recalc( true );
		self::cart()->add_to_cart( $product_id, $cfg['qty'], $variation_id, array(), $item_data );
		self::suppress_recalc( false );
	}

	/**
	 * @param mixed $code Coupon code.
	 */
	public static function on_removed( $code ): void {
		$code = (string) $code;
		if ( '' === $code || ! self::cart() ) {
			return;
		}
		foreach ( self::cart()->get_cart() as $key => $item ) {
			if ( ( $item[ GiftConfig::ITEM_FLAG ] ?? '' ) === $code ) {
				self::cart()->remove_cart_item( $key );
			}
		}
	}

	/**
	 * @param mixed $cart WC_Cart.
	 */
	public static function cleanup_orphans( $cart ): void {
		if ( self::$cleaning || ! $cart instanceof \WC_Cart ) {
			return;
		}
		$applied = $cart->get_applied_coupons();
		$remove  = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$code = (string) ( $item[ GiftConfig::ITEM_FLAG ] ?? '' );
			if ( '' !== $code && ! in_array( $code, $applied, true ) ) {
				$remove[] = $key;
			}
		}
		if ( array() === $remove ) {
			return;
		}
		self::$cleaning = true;
		foreach ( $remove as $key ) {
			$cart->remove_cart_item( $key );
		}
		self::$cleaning = false;
	}

	/* ---------------- pricing ---------------- */

	/**
	 * @param mixed $cart WC_Cart.
	 */
	public static function price_gifts( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		$applied = $cart->get_applied_coupons();
		foreach ( $cart->get_cart() as $item ) {
			$code = (string) ( $item[ GiftConfig::ITEM_FLAG ] ?? '' );
			if ( '' === $code || ! in_array( $code, $applied, true ) ) {
				continue;
			}
			if ( ! isset( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}
			$base  = (float) ( $item[ GiftConfig::ITEM_BASE ] ?? $item['data']->get_price() );
			$mode  = (string) ( $item[ GiftConfig::ITEM_MODE ] ?? 'free' );
			$value = (float) ( $item[ GiftConfig::ITEM_VALUE ] ?? 0 );
			$item['data']->set_price( GiftConfig::gift_price( $base, $mode, $value ) );
		}
	}

	/* ---------------- lock quantity / remove ---------------- */

	/**
	 * @param mixed $html
	 * @param mixed $cart_item_key
	 * @param mixed $cart_item
	 * @return mixed
	 */
	public static function lock_qty( $html, $cart_item_key, $cart_item = array() ) {
		if ( self::is_gift_item( $cart_item ) ) {
			return esc_html( (string) (int) ( $cart_item[ GiftConfig::ITEM_QTY ] ?? 1 ) );
		}
		return $html;
	}

	/**
	 * @param mixed $valid
	 * @param mixed $cart_item_key
	 * @param mixed $values
	 * @param mixed $quantity
	 * @return mixed
	 */
	public static function prevent_qty_change( $valid, $cart_item_key, $values, $quantity ) {
		if ( self::is_gift_item( $values ) && (int) $quantity !== (int) ( $values[ GiftConfig::ITEM_QTY ] ?? 1 ) ) {
			wc_add_notice( __( 'The gift quantity cannot be changed.', 'moksa-coupons-for-woocommerce' ), 'error' );
			return false;
		}
		return $valid;
	}

	/**
	 * @param mixed $value
	 * @param mixed $product
	 * @param mixed $cart_item
	 * @return mixed
	 */
	public static function lock_store_qty( $value, $product, $cart_item ) {
		if ( self::is_gift_item( $cart_item ) ) {
			return (int) ( $cart_item[ GiftConfig::ITEM_QTY ] ?? 1 );
		}
		return $value;
	}

	/**
	 * @param mixed $link
	 * @param mixed $cart_item_key
	 * @return mixed
	 */
	public static function hide_remove( $link, $cart_item_key ) {
		$cart = self::cart();
		$item = $cart ? $cart->get_cart_item( (string) $cart_item_key ) : null;
		return self::is_gift_item( $item ) ? '' : $link;
	}

	/* ---------------- helpers ---------------- */

	/**
	 * @param mixed $item
	 */
	private static function is_gift_item( $item ): bool {
		if ( ! is_array( $item ) || empty( $item[ GiftConfig::ITEM_FLAG ] ) ) {
			return false;
		}
		$applied = self::cart() ? self::cart()->get_applied_coupons() : array();
		return in_array( $item[ GiftConfig::ITEM_FLAG ], $applied, true );
	}

	private static function find_gift_key( string $code ): ?string {
		if ( ! self::cart() ) {
			return null;
		}
		foreach ( self::cart()->get_cart() as $key => $item ) {
			if ( ( $item[ GiftConfig::ITEM_FLAG ] ?? '' ) === $code ) {
				return (string) $key;
			}
		}
		return null;
	}

	private static function cart(): ?\WC_Cart {
		return ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) ? WC()->cart : null;
	}

	private static function suppress_recalc( bool $on ): void {
		$cart = self::cart();
		if ( ! $cart ) {
			return;
		}
		if ( $on ) {
			remove_action( 'woocommerce_add_to_cart', array( $cart, 'calculate_totals' ), 20 );
		} else {
			add_action( 'woocommerce_add_to_cart', array( $cart, 'calculate_totals' ), 20 );
		}
	}
}
