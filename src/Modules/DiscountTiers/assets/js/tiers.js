/**
 * 階梯折扣 — 後台動態加減階梯列。引擎本身不限層數;此腳本只負責 UI 的新增 / 刪除與重新編號。
 * 無此腳本時(JS 關閉)仍會 render 已存的階梯列 + 幾列空白列,照常送出儲存。
 */
( function () {
	var cfg = window.moksafocouTiers || {};
	var MAX = parseInt( cfg.maxRows, 10 ) || 50;

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function each( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	}

	// Keep the threshold column header showing the unit of the selected basis.
	function syncHeader() {
		var basis = cfg.basisId ? document.getElementById( cfg.basisId ) : null;
		var hdr = document.querySelector( '.moksafocou-tier-th-threshold' );
		var labels = cfg.thresholdHdr || {};
		if ( basis && hdr && labels[ basis.value ] ) {
			hdr.textContent = labels[ basis.value ];
		}
	}

	ready( function () {
		var basisEl = cfg.basisId ? document.getElementById( cfg.basisId ) : null;
		if ( basisEl ) {
			basisEl.addEventListener( 'change', syncHeader );
			syncHeader();
		}
		each( document.querySelectorAll( '.moksafocou-tiers-builder' ), function ( builder ) {
			var tbody = builder.querySelector( '.moksafocou-tiers-rows' );
			var addBtn = builder.querySelector( '.moksafocou-tier-add' );
			var tpl = builder.querySelector( '.moksafocou-tier-template' );
			if ( ! tbody || ! addBtn || ! tpl ) {
				return;
			}

			function rows() {
				return tbody.querySelectorAll( '.moksafocou-tier-row' );
			}

			function renumber() {
				each( rows(), function ( row, i ) {
					var idx = row.querySelector( '.moksafocou-tier-idx' );
					if ( idx ) {
						idx.textContent = String( i + 1 );
					}
				} );
			}

			function clone_row() {
				if ( tpl.content && tpl.content.firstElementChild ) {
					return tpl.content.firstElementChild.cloneNode( true );
				}
				// Fallback for browsers without <template>.content support.
				var tmp = document.createElement( 'tbody' );
				tmp.innerHTML = tpl.innerHTML;
				return tmp.querySelector( '.moksafocou-tier-row' );
			}

			addBtn.addEventListener( 'click', function () {
				if ( rows().length >= MAX ) {
					return;
				}
				var row = clone_row();
				if ( ! row ) {
					return;
				}
				tbody.appendChild( row );
				renumber();
				var first = row.querySelector( 'input' );
				if ( first ) {
					first.focus();
				}
			} );

			tbody.addEventListener( 'click', function ( e ) {
				var btn = e.target && e.target.closest ? e.target.closest( '.moksafocou-tier-remove' ) : null;
				if ( ! btn ) {
					return;
				}
				e.preventDefault();
				var all = rows();
				if ( all.length <= 1 ) {
					// Keep at least one row; just clear it instead of removing.
					each( all[ 0 ].querySelectorAll( 'input' ), function ( inp ) {
						inp.value = '';
					} );
					return;
				}
				var row = btn.closest( '.moksafocou-tier-row' );
				if ( row && row.parentNode ) {
					row.parentNode.removeChild( row );
					renumber();
				}
			} );
		} );
	} );
} )();
