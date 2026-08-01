/**
 * Coupon-templates admin page: category + keyword filtering of the template grid,
 * and the quick-configure modal (open from a card, focus-trap, Esc/backdrop close).
 * Enqueued by TemplatePage::enqueue_admin (previously an inline <script>).
 */
( function () {
	'use strict';

	var L = window.moksafocouTpl || {};

	// --- category + keyword filter (combined) ---------------------------------
	var cats = document.querySelector( '.moksafocou-tpl-cats' );
	var main = document.querySelector( '.moksafocou-tpl-main' );
	if ( main ) {
		var links = cats ? cats.querySelectorAll( 'a[data-filter]' ) : [];
		var cards = main.querySelectorAll( '.moksafocou-tpl-card' );
		var empty = main.querySelector( '.moksafocou-tpl-empty' );
		var search = main.querySelector( '.moksafocou-tpl-search input' );
		var curCat = 'all',
			curQ = '';
		var apply = function () {
			var shown = 0;
			cards.forEach( function ( c ) {
				var okCat = ( curCat === 'all' || c.getAttribute( 'data-cat' ) === curCat );
				var okQ = ( curQ === '' || ( c.getAttribute( 'data-search' ) || '' ).indexOf( curQ ) !== -1 );
				var ok = okCat && okQ;
				c.style.display = ok ? '' : 'none';
				if ( ok ) {
					shown++;
				}
			} );
			if ( empty ) {
				empty.hidden = shown > 0;
			}
		};
		if ( cats ) {
			cats.addEventListener( 'click', function ( e ) {
				var a = e.target.closest( 'a[data-filter]' );
				if ( ! a ) {
					return;
				}
				e.preventDefault();
				curCat = a.getAttribute( 'data-filter' );
				links.forEach( function ( l ) {
					l.classList.toggle( 'current', l === a );
				} );
				apply();
			} );
		}
		if ( search ) {
			search.addEventListener( 'input', function () {
				curQ = search.value.toLowerCase().trim();
				apply();
			} );
		}
	}

	// --- quick-configure modal ------------------------------------------------
	var modal = document.querySelector( '.moksafocou-tpl-modal' );
	if ( modal ) {
		var dlg = modal.querySelector( '.moksafocou-tpl-dialog' );
		var title = modal.querySelector( '#moksafocou-tpl-modal-title' );
		var fId = modal.querySelector( 'input[name=template_id]' );
		var fCode = modal.querySelector( 'input[name=code]' );
		var fAmt = modal.querySelector( 'input[name=amount]' );
		var amtRow = modal.querySelector( '.f-amount' );
		var unit = modal.querySelector( '.unit' );
		var fUl = modal.querySelector( 'input[name=usage_limit]' );
		var fUlpu = modal.querySelector( 'input[name=usage_limit_per_user]' );
		var fDesc = modal.querySelector( 'textarea[name=description]' );
		var fIndiv = modal.querySelector( 'input[name=individual_use]' );
		var lastFocus = null;
		var open = function ( b ) {
			lastFocus = b;
			fId.value = b.getAttribute( 'data-id' );
			title.textContent = b.getAttribute( 'data-label' );
			fCode.value = '';
			fCode.placeholder = ( b.getAttribute( 'data-prefix' ) || '' ) + '-… (' + ( L.autoGen || '' ) + ')';
			if ( b.getAttribute( 'data-amount-editable' ) === '1' ) {
				amtRow.hidden = false;
				fAmt.value = b.getAttribute( 'data-amount' );
				unit.textContent = b.getAttribute( 'data-unit' ) || '';
			} else {
				amtRow.hidden = true;
				fAmt.value = '';
			}
			fUl.value = b.getAttribute( 'data-usage-limit' ) || '';
			fUlpu.value = b.getAttribute( 'data-usage-pu' ) || '';
			fDesc.value = b.getAttribute( 'data-description' ) || '';
			fIndiv.checked = b.getAttribute( 'data-individual' ) === '1';
			modal.hidden = false;
			fCode.focus();
		};
		var close = function () {
			modal.hidden = true;
			if ( lastFocus ) {
				lastFocus.focus();
				lastFocus = null;
			}
		};
		document.querySelectorAll( '.moksafocou-tpl-apply' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				open( b );
			} );
		} );
		modal.querySelector( '.moksafocou-tpl-backdrop' ).addEventListener( 'click', close );
		modal.querySelector( '.moksafocou-tpl-cancel' ).addEventListener( 'click', close );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! modal.hidden ) {
				close();
			}
		} );
		// Focus trap: keep Tab cycling inside the open dialog.
		dlg.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Tab' || modal.hidden ) {
				return;
			}
			var f = Array.prototype.filter.call(
				dlg.querySelectorAll( 'a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])' ),
				function ( el ) {
					return el.offsetParent !== null;
				}
			);
			if ( ! f.length ) {
				return;
			}
			var first = f[ 0 ],
				last = f[ f.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		} );
	}
} )();
