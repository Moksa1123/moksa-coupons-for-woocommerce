/* global jQuery */
/**
 * Tab switcher for the consolidated 'Moksa coupon settings' metabox. Mirrors WooCommerce's
 * coupon-data tabs: clicking a left tab shows its panel and hides the others. Uses our
 * own .moksafocou-* classes so WooCommerce's native coupon-data tab JS never touches
 * these panels. The first panel is rendered visible server-side as a no-JS fallback.
 *
 * Implements the WAI-ARIA tablist pattern: roles, aria-selected, roving tabindex and
 * Arrow / Home / End keyboard navigation, so the tabs are operable without a mouse.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $wrap = $( '.moksafocou-panel-wrap' );
		if ( ! $wrap.length ) {
			return;
		}
		var $list  = $wrap.find( '.moksafocou-settings-tabs' );
		var $tabs  = $wrap.find( '.moksafocou-settings-tabs > li' );
		var $links = $wrap.find( '.moksafocou-settings-tabs > li > a' );

		$list.attr( 'role', 'tablist' );
		$links.attr( 'role', 'tab' );
		$wrap.find( '.moksafocou-panel' ).attr( 'role', 'tabpanel' ).attr( 'tabindex', '0' );

		function show( target, focusTab ) {
			if ( ! target ) {
				return;
			}
			$wrap.find( '.moksafocou-panel' ).hide();
			$( target ).show();
			$tabs.removeClass( 'active' );
			$links.attr( 'aria-selected', 'false' ).attr( 'tabindex', '-1' );
			var $active = $links.filter( '[href="' + target + '"]' );
			$active.parent().addClass( 'active' );
			$active.attr( 'aria-selected', 'true' ).attr( 'tabindex', '0' );
			if ( focusTab ) {
				$active.trigger( 'focus' );
			}
		}

		$links.on( 'click', function ( e ) {
			e.preventDefault();
			show( $( this ).attr( 'href' ) );
		} );

		// Arrow / Home / End navigation between tabs (roving tabindex).
		$links.on( 'keydown', function ( e ) {
			var idx = $links.index( this );
			var next = null;
			if ( 'ArrowDown' === e.key || 'ArrowRight' === e.key ) {
				next = ( idx + 1 ) % $links.length;
			} else if ( 'ArrowUp' === e.key || 'ArrowLeft' === e.key ) {
				next = ( idx - 1 + $links.length ) % $links.length;
			} else if ( 'Home' === e.key ) {
				next = 0;
			} else if ( 'End' === e.key ) {
				next = $links.length - 1;
			}
			if ( null !== next ) {
				e.preventDefault();
				show( $links.eq( next ).attr( 'href' ), true );
			}
		} );

		// Activate the first visible tab (the server marks one active for no-JS).
		var $start = $tabs.filter( ':visible' ).first().find( 'a' );
		if ( ! $start.length ) {
			$start = $links.first();
		}
		show( $start.attr( 'href' ) );
	} );
}( jQuery ) );
