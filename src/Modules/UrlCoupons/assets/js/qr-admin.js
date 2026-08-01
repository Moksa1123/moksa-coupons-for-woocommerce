/* global wp, moksafocouQr */
( function () {
	'use strict';

	var L = window.moksafocouQr || {};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			node.setAttribute( k, attrs[ k ] );
		} );
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function renderShareUrl( mount, shareUrl ) {
		var wrap = el( 'p', { class: 'form-field' } );
		wrap.appendChild( el( 'label', {}, L.shareTitle || 'Share link' ) );

		var input = el( 'input', { type: 'text', readonly: 'readonly', value: shareUrl, style: 'width:70%;' } );
		input.addEventListener( 'focus', function () {
			input.select();
		} );
		wrap.appendChild( input );

		var copy = el( 'button', { type: 'button', class: 'button', style: 'margin-left:6px;' }, L.copyLabel || 'Copy' );
		copy.addEventListener( 'click', function () {
			input.select();
			navigator.clipboard.writeText( shareUrl ).then( function () {
				copy.textContent = L.copiedLabel || 'Copied';
				setTimeout( function () {
					copy.textContent = L.copyLabel || 'Copy';
				}, 1200 );
			} );
		} );
		wrap.appendChild( copy );
		mount.appendChild( wrap );
	}

	function renderQr( mount, svgMarkup, couponId ) {
		var box = el( 'p', { class: 'form-field' } );
		box.appendChild( el( 'label', {}, L.qrTitle || 'QR Code' ) );

		var holder = el( 'span', { style: 'display:inline-block;vertical-align:top;' } );
		holder.innerHTML = svgMarkup;
		var svg = holder.querySelector( 'svg' );
		if ( svg ) {
			svg.style.width = '180px';
			svg.style.height = '180px';
		}
		box.appendChild( holder );
		mount.appendChild( box );

		var dl = el( 'p', { class: 'form-field' } );
		var link = el( 'a', { href: '#', class: 'button', download: 'coupon-' + couponId + '-qr.png' }, L.downloadLabel || 'Download QR' );
		link.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			svgToPng( svgMarkup, function ( pngUrl ) {
				link.setAttribute( 'href', pngUrl );
				// Trigger the actual download on a synthetic second click.
				var tmp = el( 'a', { href: pngUrl, download: 'coupon-' + couponId + '-qr.png' } );
				document.body.appendChild( tmp );
				tmp.click();
				document.body.removeChild( tmp );
			} );
		} );
		dl.appendChild( link );
		mount.appendChild( dl );
	}

	function svgToPng( svgMarkup, cb ) {
		var blob = new Blob( [ svgMarkup ], { type: 'image/svg+xml;charset=utf-8' } );
		var url = URL.createObjectURL( blob );
		var img = new Image();
		img.onload = function () {
			var size = 512;
			var canvas = el( 'canvas' );
			canvas.width = size;
			canvas.height = size;
			var ctx = canvas.getContext( '2d' );
			ctx.fillStyle = '#ffffff';
			ctx.fillRect( 0, 0, size, size );
			ctx.imageSmoothingEnabled = false;
			ctx.drawImage( img, 0, 0, size, size );
			URL.revokeObjectURL( url );
			cb( canvas.toDataURL( 'image/png' ) );
		};
		img.src = url;
	}

	function load() {
		var mount = document.getElementById( 'moksafocou-url-share' );
		if ( ! mount || ! window.wp || ! wp.apiFetch ) {
			return;
		}
		var couponId = mount.getAttribute( 'data-coupon' ) || '0';

		wp.apiFetch( { path: 'moksafocou/v1/coupons/' + couponId + '/share?inline=1' } ).then( function ( res ) {
			mount.innerHTML = '';
			if ( ! res || ! res.enabled ) {
				mount.appendChild( el( 'p', { class: 'description' }, ( res && res.reason ) || L.saveFirst || '' ) );
				return;
			}
			renderShareUrl( mount, res.share_url );
			if ( res.qr_svg ) {
				renderQr( mount, res.qr_svg, couponId );
			}
		} ).catch( function () {
			mount.innerHTML = '';
			mount.appendChild( el( 'p', { class: 'description' }, L.saveFirst || '' ) );
		} );
	}

	ready( load );
}() );
