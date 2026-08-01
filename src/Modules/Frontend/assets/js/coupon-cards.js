/**
 * Copy-to-clipboard for the [moksafocou_coupons] card list.
 */
( function () {
	'use strict';

	var liveRegion = null;

	// A single polite live region so screen-reader users hear that the copy succeeded
	// (a button's own text change is not reliably announced).
	function announce( message ) {
		if ( ! liveRegion ) {
			liveRegion = document.createElement( 'div' );
			liveRegion.setAttribute( 'aria-live', 'polite' );
			liveRegion.setAttribute( 'role', 'status' );
			liveRegion.className = 'moksafocou-sr-only';
			document.body.appendChild( liveRegion );
		}
		// Clear then set so an identical message re-announces.
		liveRegion.textContent = '';
		window.setTimeout( function () {
			liveRegion.textContent = message;
		}, 50 );
	}

	function flash( button ) {
		var original = button.textContent;
		var copied = button.getAttribute( 'data-copied' ) || 'Copied';
		button.classList.add( 'is-copied' );
		button.textContent = copied;
		announce( ( button.getAttribute( 'aria-label' ) || copied ) + ' — ' + copied );
		window.setTimeout( function () {
			button.classList.remove( 'is-copied' );
			button.textContent = original;
		}, 1500 );
	}

	function copy( code, button ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( code ).then(
				function () {
					flash( button );
				},
				function () {
					legacyCopy( code, button );
				}
			);
		} else {
			legacyCopy( code, button );
		}
	}

	function legacyCopy( code, button ) {
		var field = document.createElement( 'textarea' );
		field.value = code;
		field.setAttribute( 'readonly', '' );
		field.style.position = 'absolute';
		field.style.left = '-9999px';
		document.body.appendChild( field );
		field.select();
		try {
			document.execCommand( 'copy' );
			flash( button );
		} catch ( e ) {
			/* no-op */
		}
		document.body.removeChild( field );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.moksafocou-coupon__copy' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		copy( button.getAttribute( 'data-code' ) || '', button );
	} );

	// --- Live countdown ---------------------------------------------------------------
	// The card HTML is cached and shared across visitors, so the server only bakes the target
	// epoch (data-deadline, UTC ms). We tick the human-readable countdown client-side so it is
	// never a frozen "3 days left".
	function formatRemaining( ms ) {
		var s = Math.floor( ms / 1000 );
		var d = Math.floor( s / 86400 );
		s -= d * 86400;
		var h = Math.floor( s / 3600 );
		s -= h * 3600;
		var m = Math.floor( s / 60 );
		s -= m * 60;
		function pad( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		}
		return ( d > 0 ? d + 'd ' : '' ) + pad( h ) + ':' + pad( m ) + ':' + pad( s );
	}

	function tickCountdowns() {
		var nodes = document.querySelectorAll( '.moksafocou-coupon__countdown[data-deadline]' );
		if ( ! nodes.length ) {
			return false;
		}
		var now = Date.now();
		var anyLive = false;
		for ( var i = 0; i < nodes.length; i++ ) {
			var node = nodes[ i ];
			var deadline = parseInt( node.getAttribute( 'data-deadline' ), 10 );
			if ( isNaN( deadline ) ) {
				continue;
			}
			var diff = deadline - now;
			if ( diff <= 0 ) {
				node.textContent = node.getAttribute( 'data-ended' ) || 'Ended';
				node.classList.add( 'is-ended' );
				continue;
			}
			anyLive = true;
			node.textContent = formatRemaining( diff );
		}
		return anyLive;
	}

	if ( document.querySelector( '.moksafocou-coupon__countdown[data-deadline]' ) ) {
		tickCountdowns();
		var timer = window.setInterval( function () {
			if ( ! tickCountdowns() ) {
				window.clearInterval( timer );
			}
		}, 1000 );
	}
} )();
