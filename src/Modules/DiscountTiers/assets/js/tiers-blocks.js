/**
 * Tiered discounts in the block cart and checkout.
 *
 * Reads what the server already worked out (cart extensions.moksafocou, see StoreApi.php) and
 * places it through WooCommerce Blocks' own extension points:
 *
 *   - registerCheckoutFilters( coupons ) names the current tier on the coupon line;
 *   - an ExperimentalOrderMeta fill shows "add NT$X more" with a progress bar.
 *
 * Nothing here computes a tier, formats money or knows any wording: every string arrives
 * finished and translated, so this file cannot disagree with the classic cart, the discount the
 * server actually applied, or the shop's language.
 *
 * Plain ES5 and wp.element.createElement, no build step, matching the rest of the plugin's
 * front-end scripts.
 */
( function () {
	'use strict';

	var wc = window.wc || {};
	var wp = window.wp || {};
	var checkout = wc.blocksCheckout;

	if ( ! checkout || ! wp.element || ! wp.plugins ) {
		return; // Not a block cart / checkout page.
	}

	var el = wp.element.createElement;

	/**
	 * The rows the server sent, or an empty list.
	 *
	 * Taken from the argument when a caller passes extensions, otherwise from the cart store. The
	 * fallback is not defensive padding: WooCommerce 11.1 calls the `coupons` filter as
	 * applyCheckoutFilter({ arg, filterName, defaultValue }) with NO extensions at all, so the first
	 * build of this script received {} there and the tier never reached the coupon line — the nudge,
	 * which does get extensions from its slot, worked on the same page.
	 */
	function tiersFrom( extensions ) {
		var data = extensions && extensions.moksafocou;
		if ( ! data && wp.data && 'function' === typeof wp.data.select ) {
			var key = ( wc.wcBlocksData && wc.wcBlocksData.CART_STORE_KEY ) || 'wc/store/cart';
			var store = wp.data.select( key );
			var cart = store && 'function' === typeof store.getCartData ? store.getCartData() : null;
			data = cart && cart.extensions ? cart.extensions.moksafocou : null;
		}
		return ( data && Array.isArray( data.tiers ) ) ? data.tiers : [];
	}

	/* ------------------------------------------------------------------ *
	 * 1. Name the current tier on the coupon line.
	 *
	 * The Store API coupon carries only a code. Without this the checkout read "−NT$247" next to
	 * the coupon's name and customers asked why it was not 85 折, when the cart had already
	 * climbed to 82 折.
	 * ------------------------------------------------------------------ */
	if ( 'function' === typeof checkout.registerCheckoutFilters ) {
		checkout.registerCheckoutFilters( 'moksafocou', {
			coupons: function ( coupons, extensions ) {
				var tiers = tiersFrom( extensions );
				if ( ! tiers.length || ! Array.isArray( coupons ) ) {
					return coupons;
				}
				return coupons.map( function ( coupon ) {
					for ( var i = 0; i < tiers.length; i++ ) {
						if ( tiers[ i ].code === coupon.code && tiers[ i ].label_suffix ) {
							return Object.assign( {}, coupon, {
								label: String( coupon.label || coupon.code ) + tiers[ i ].label_suffix
							} );
						}
					}
					return coupon;
				} );
			}
		} );
	}

	/* ------------------------------------------------------------------ *
	 * 2. "Add NT$X more" under the totals.
	 * ------------------------------------------------------------------ */
	var Slot = checkout.ExperimentalOrderMeta;
	if ( ! Slot ) {
		return;
	}

	/** The fill hands its child { cart, extensions, context }. */
	function Nudge( props ) {
		var tiers = tiersFrom( props && props.extensions );
		for ( var i = 0; i < tiers.length; i++ ) {
			var next = tiers[ i ].next;
			if ( ! next || ! next.message ) {
				continue;
			}
			var pct = Math.round( Math.max( 0, Math.min( 1, Number( next.progress ) || 0 ) ) * 100 );
			return el(
				'div',
				// Not wc-block-components-totals-item: that class is a flex ROW, and an empty bar
				// laid out beside the text in a row shrinks to zero width. The first build borrowed it
				// for the spacing and drew a progress bar nobody could see.
				{ className: 'moksafocou-tier-nudge' },
				el( 'div', { className: 'moksafocou-tier-nudge__text' }, next.message ),
				el(
					'div',
					{
						className: 'moksafocou-tier-nudge__bar',
						role: 'progressbar',
						'aria-valuemin': 0,
						'aria-valuemax': 100,
						'aria-valuenow': pct,
						'aria-label': next.gap_text
					},
					el( 'span', { className: 'moksafocou-tier-nudge__fill', style: { width: pct + '%' } } )
				)
			);
		}
		return null; // Already on the best tier, or no tiered coupon applied.
	}

	wp.plugins.registerPlugin( 'moksafocou-tier-nudge', {
		// One scope covers both the cart block and the checkout block.
		scope: 'woocommerce-checkout',
		render: function () {
			return el( Slot, null, el( Nudge ) );
		}
	} );
}() );
