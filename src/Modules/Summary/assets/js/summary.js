/**
 * 編輯頁即時摘要 — 讀取優惠券表單,即時組出「這張券做什麼 / 已啟用哪些功能 / 有無衝突」。
 * 標籤全來自 PHP(moksafocouSummary.i18n),保持可翻譯。
 */
( function () {
	var cfg = window.moksafocouSummary || {};
	var i = cfg.i18n || {};
	var features = cfg.features || [];

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function q( sel ) {
		return document.querySelector( sel );
	}

	function val( sel ) {
		var el = q( sel );
		return el ? el.value : '';
	}

	function isOn( sel ) {
		var el = q( sel );
		if ( ! el ) {
			return false;
		}
		return 'checkbox' === el.type ? el.checked : 'yes' === el.value;
	}

	function num( sel ) {
		return parseFloat( val( sel ) ) || 0;
	}

	function fmt( tpl ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var n = 0;
		return String( tpl || '' ).replace( /%s/g, function () {
			return args[ n++ ];
		} ).replace( /%%/g, '%' );
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	function trim2( v ) {
		return String( parseFloat( v.toFixed( 2 ) ) );
	}

	function build() {
		var panel = q( '#moksafocou-summary-panel' );
		if ( ! panel ) {
			return;
		}
		var type = val( '#discount_type' );
		var amount = num( '#coupon_amount' );
		var tiersOn = isOn( '#_moksafocou_tiers_enabled' );
		var warns = [];
		var html = '';

		var typeLabel = ( 'moksafocou_bogo' === type ) ? i.bogo : ( i[ type ] || i.none );
		var line = esc( typeLabel );
		if ( 'percent' === type ) {
			if ( tiersOn ) {
				line += ' — ' + esc( i.tiersDrive );
			} else {
				line += ' — ' + esc( fmt( i.percentOff, trim2( amount ) ) );
				if ( amount > 0 && amount < 100 && amount % 10 === 0 ) {
					line += ' (' + esc( fmt( i.zhe, String( ( 100 - amount ) / 10 ) ) ) + ')';
				}
			}
			if ( amount > 100 ) {
				warns.push( i.cPercentRange );
			}
		} else if ( 'fixed_cart' === type || 'fixed_product' === type ) {
			line += ' — ' + esc( fmt( i.amountOff, trim2( amount ) ) );
		} else if ( 'moksafocou_bogo' === type ) {
			line += ' — ' + esc( i.bogoTab );
		} else if ( 'moksafocou_cashback' === type ) {
			line += ' — ' + esc( i.cashbackTab );
		}
		html += '<div class="mfc-sum-head">' + esc( i.discountHead ) + '</div><div class="mfc-sum-val">' + line + '</div>';

		var exp = val( '#expiry_date' );
		html += '<div class="mfc-sum-val mfc-sum-info">' + ( exp ? esc( fmt( i.expiresOn, exp ) ) : esc( i.noExpiry ) ) + '</div>';

		var chips = '';
		features.forEach( function ( f ) {
			if ( isOn( f.sel ) ) {
				chips += '<span class="mfc-sum-chip">' + esc( f.label ) + '</span>';
			}
		} );
		if ( chips ) {
			html += '<div class="mfc-sum-head">' + esc( i.featuresHead ) + '</div><div class="mfc-sum-chips">' + chips + '</div>';
		}

		if ( tiersOn && 'percent' !== type ) {
			warns.push( i.cTiersType );
		}
		var min = num( '#minimum_amount' );
		var max = num( '#maximum_amount' );
		if ( min > 0 && max > 0 && min > max ) {
			warns.push( i.cMinMax );
		}
		if ( warns.length ) {
			html += '<div class="mfc-sum-head">' + esc( i.conflictsHead ) + '</div>';
			warns.forEach( function ( w ) {
				html += '<div class="mfc-sum-warn">' + esc( w ) + '</div>';
			} );
		}

		panel.innerHTML = html;
	}

	ready( function () {
		var form = q( '#post' );
		if ( form ) {
			form.addEventListener( 'input', build );
			form.addEventListener( 'change', build );
		}
		build();
	} );
} )();
