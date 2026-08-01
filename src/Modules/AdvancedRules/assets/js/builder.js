/* global moksafocouRules */
/**
 * Visual AND/OR rule builder. Reads/writes the hidden #moksafocou_rules_json textarea, so the
 * field degrades to a raw-JSON editor when this script is absent. Single source of truth for
 * types/operators is the localized `moksafocouRules.types` map (mirrors PHP Support\Rules).
 */
( function () {
	'use strict';

	var CFG = window.moksafocouRules || {};
	var TYPES = CFG.types || {};
	var I18N = CFG.i18n || {};

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			if ( k === 'style' ) { node.setAttribute( 'style', attrs[ k ] ); }
			else if ( k === 'text' ) { node.textContent = attrs[ k ]; }
			else { node.setAttribute( k, attrs[ k ] ); }
		} );
		( children || [] ).forEach( function ( c ) { if ( c ) { node.appendChild( c ); } } );
		return node;
	}

	function matchSelect( value ) {
		var sel = el( 'select', { class: 'moksafocou-rule-match' } );
		[ [ 'all', I18N.matchAll ], [ 'any', I18N.matchAny ] ].forEach( function ( o ) {
			var opt = el( 'option', { value: o[ 0 ], text: o[ 1 ] } );
			if ( o[ 0 ] === value ) { opt.selected = true; }
			sel.appendChild( opt );
		} );
		return sel;
	}

	function optionList( map ) {
		return Object.keys( map ).map( function ( k ) { return [ k, map[ k ] ]; } );
	}

	function selectMaps() {
		return { country: CFG.countries, payment: CFG.gateways, role: CFG.roles, weekday: CFG.weekdays, stock: CFG.stocks, zone: CFG.zones };
	}

	// Rule types whose value is a product id list vs a category id list — these use WooCommerce's
	// own AJAX search dropdown instead of a raw ID box.
	var PRODUCT_TYPES = [ 'product_in_cart', 'ordered_product' ];
	var CATEGORY_TYPES = [ 'category_in_cart', 'ordered_category' ];

	function isProductType( type ) { return PRODUCT_TYPES.indexOf( type ) !== -1; }
	function isCategoryType( type ) { return CATEGORY_TYPES.indexOf( type ) !== -1; }

	function searchSelect( type, value ) {
		var isCat = isCategoryType( type );
		var sel = el( 'select', {
			class: 'moksafocou-rule-value ' + ( isCat ? 'wc-category-search' : 'wc-product-search' ),
			multiple: 'multiple',
			'data-action': isCat ? 'woocommerce_json_search_categories' : 'woocommerce_json_search_products_and_variations',
			'data-placeholder': isCat ? ( I18N.searchCat || '' ) : ( I18N.searchProduct || '' )
		} );
		var chosen = Array.isArray( value ) ? value : ( value || value === 0 ? [ value ] : [] );
		var labels = CFG.idLabels || {};
		chosen.forEach( function ( id ) {
			var key = String( id );
			var opt = el( 'option', { value: key, text: labels[ key ] || ( '#' + key ) } );
			opt.selected = true;
			sel.appendChild( opt );
		} );
		return sel;
	}

	function valueInput( type, value ) {
		var spec = TYPES[ type ] || { kind: 'num' };
		var kind = spec.kind;
		var maps = selectMaps();
		if ( isProductType( type ) || isCategoryType( type ) ) {
			return searchSelect( type, value );
		}
		if ( maps[ kind ] ) {
			var sel = el( 'select', { class: 'moksafocou-rule-value', multiple: 'multiple' } );
			var chosen = Array.isArray( value ) ? value.map( String ) : [];
			optionList( maps[ kind ] ).forEach( function ( o ) {
				var opt = el( 'option', { value: o[ 0 ], text: o[ 1 ] } );
				if ( chosen.indexOf( String( o[ 0 ] ) ) !== -1 ) { opt.selected = true; }
				sel.appendChild( opt );
			} );
			return sel;
		}
		if ( kind === 'ids' ) {
			return el( 'input', { type: 'text', class: 'moksafocou-rule-value', placeholder: I18N.idsHint, value: Array.isArray( value ) ? value.join( ',' ) : ( value || '' ) } );
		}
		if ( kind === 'codetext' ) {
			return el( 'input', { type: 'text', class: 'moksafocou-rule-value', placeholder: I18N.codesHint, value: Array.isArray( value ) ? value.join( ',' ) : ( value || '' ) } );
		}
		if ( kind === 'pair' ) {
			var v = ( value && typeof value === 'object' ) ? value : {};
			var span = el( 'span', { class: 'moksafocou-rule-value' } );
			span.appendChild( el( 'input', { type: 'number', class: 'mfc-a', placeholder: I18N.pairId, value: ( v.a != null ? v.a : '' ) } ) );
			span.appendChild( el( 'input', { type: 'number', step: 'any', class: 'mfc-b', placeholder: I18N.pairNum, value: ( v.b != null ? v.b : '' ) } ) );
			return span;
		}
		if ( kind === 'tax' ) {
			var tv = ( value && typeof value === 'object' ) ? value : {};
			var tspan = el( 'span', { class: 'moksafocou-rule-value' } );
			tspan.appendChild( el( 'input', { type: 'text', class: 'mfc-tax', placeholder: I18N.taxSlug, value: ( tv.tax || '' ) } ) );
			tspan.appendChild( el( 'input', { type: 'text', class: 'mfc-terms', placeholder: I18N.taxTerms, value: ( Array.isArray( tv.terms ) ? tv.terms.join( ',' ) : '' ) } ) );
			return tspan;
		}
		if ( kind === 'kv' ) {
			var kv = ( value && typeof value === 'object' ) ? value : {};
			var kspan = el( 'span', { class: 'moksafocou-rule-value' } );
			kspan.appendChild( el( 'input', { type: 'text', class: 'mfc-key', placeholder: I18N.metaKey, value: ( kv.key || '' ) } ) );
			kspan.appendChild( el( 'input', { type: 'text', class: 'mfc-kv-val', placeholder: I18N.metaVal, value: ( kv.value != null ? kv.value : '' ) } ) );
			return kspan;
		}
		if ( kind === 'time' ) { return el( 'input', { type: 'time', class: 'moksafocou-rule-value', value: value || '' } ); }
		if ( kind === 'date' ) { return el( 'input', { type: 'datetime-local', class: 'moksafocou-rule-value', value: value ? String( value ).replace( ' ', 'T' ).slice( 0, 16 ) : '' } ); }
		return el( 'input', { type: 'number', step: 'any', class: 'moksafocou-rule-value', value: ( value === 0 || value ) ? value : '' } );
	}

	function readValue( input, type ) {
		var spec = TYPES[ type ] || { kind: 'num' };
		var kind = spec.kind;
		if ( isProductType( type ) || isCategoryType( type ) ) {
			return Array.prototype.slice.call( input.options ).filter( function ( o ) { return o.selected; } ).map( function ( o ) { return parseInt( o.value, 10 ) || o.value; } );
		}
		if ( selectMaps()[ kind ] ) {
			return Array.prototype.slice.call( input.options ).filter( function ( o ) { return o.selected; } ).map( function ( o ) { return o.value; } );
		}
		if ( kind === 'ids' || kind === 'codetext' ) {
			return input.value.split( ',' ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
		}
		if ( kind === 'pair' ) {
			var a = input.querySelector( '.mfc-a' );
			var b = input.querySelector( '.mfc-b' );
			return { a: a ? ( parseInt( a.value, 10 ) || 0 ) : 0, b: b ? b.value : '' };
		}
		if ( kind === 'tax' ) {
			var tx = input.querySelector( '.mfc-tax' );
			var tm = input.querySelector( '.mfc-terms' );
			return { tax: tx ? tx.value.trim() : '', terms: tm ? tm.value.split( ',' ).map( function ( s ) { return s.trim(); } ).filter( Boolean ) : [] };
		}
		if ( kind === 'kv' ) {
			var k = input.querySelector( '.mfc-key' );
			var val = input.querySelector( '.mfc-kv-val' );
			return { key: k ? k.value.trim() : '', value: val ? val.value : '' };
		}
		return input.value;
	}

	function typeSelect( current ) {
		var sel = el( 'select', { class: 'moksafocou-rule-type' } );
		Object.keys( TYPES ).forEach( function ( t ) {
			var opt = el( 'option', { value: t, text: TYPES[ t ].label } );
			if ( t === current ) { opt.selected = true; }
			sel.appendChild( opt );
		} );
		return sel;
	}

	function opSelect( type, current ) {
		var sel = el( 'select', { class: 'moksafocou-rule-op' } );
		var ops = ( TYPES[ type ] || {} ).ops || {};
		optionList( ops ).forEach( function ( o ) {
			var opt = el( 'option', { value: o[ 0 ], text: o[ 1 ] } );
			if ( o[ 0 ] === current ) { opt.selected = true; }
			sel.appendChild( opt );
		} );
		return sel;
	}

	function build( root, textarea ) {
		var state;
		try { state = JSON.parse( textarea.value || '{}' ); } catch ( e ) { state = {}; }
		if ( ! state || typeof state !== 'object' ) { state = {}; }
		if ( state.match !== 'any' ) { state.match = 'all'; }
		if ( ! Array.isArray( state.groups ) ) { state.groups = []; }

		function sync() { textarea.value = state.groups.length ? JSON.stringify( state ) : ''; }

		// Turn the bounded-list value pickers into nice dropdowns (selectWoo / select2),
		// instead of native multi-row listboxes. width:100% sizes correctly even in a hidden tab.
		function wcSearch( $, s ) {
			var $s = $( s );
			if ( $s.hasClass( 'select2-hidden-accessible' ) ) { return; }
			var fn = ( typeof $s.selectWoo === 'function' ) ? 'selectWoo' : ( ( typeof $s.select2 === 'function' ) ? 'select2' : null );
			if ( ! fn ) { return; }
			var params = window.wc_enhanced_select_params || {};
			var isCat = $s.hasClass( 'wc-category-search' );
			var action = s.getAttribute( 'data-action' ) || ( isCat ? 'woocommerce_json_search_categories' : 'woocommerce_json_search_products_and_variations' );
			var nonce = isCat ? params.search_categories_nonce : params.search_products_nonce;
			$s[ fn ]( {
				width: '100%',
				allowClear: true,
				minimumInputLength: 2,
				placeholder: s.getAttribute( 'data-placeholder' ) || '',
				escapeMarkup: function ( m ) { return m; },
				ajax: {
					url: params.ajax_url,
					dataType: 'json',
					delay: 250,
					data: function ( q ) { return { term: q.term, action: action, security: nonce }; },
					processResults: function ( data ) {
						var results = [];
						$.each( data || {}, function ( id, text ) { results.push( { id: id, text: text } ); } );
						return { results: results };
					},
					cache: true
				}
			} );
		}

		function enhance() {
			if ( ! window.jQuery ) { return; }
			var $ = window.jQuery;
			// Product / category value pickers → WooCommerce's AJAX search (no raw IDs).
			root.querySelectorAll( '.mfc-val > select.wc-product-search, .mfc-val > select.wc-category-search' ).forEach( function ( s ) {
				wcSearch( $, s );
			} );
			// Other bounded-list pickers → plain selectWoo over their pre-loaded options.
			root.querySelectorAll( '.mfc-val > select[multiple]:not(.wc-product-search):not(.wc-category-search)' ).forEach( function ( s ) {
				var $s = $( s );
				if ( $s.hasClass( 'select2-hidden-accessible' ) ) { return; }
				if ( typeof $s.selectWoo === 'function' ) { $s.selectWoo( { width: '100%' } ); }
				else if ( typeof $s.select2 === 'function' ) { $s.select2( { width: '100%' } ); }
			} );
		}

		function render() {
			root.innerHTML = '';
			var firstType = Object.keys( TYPES )[ 0 ];

			var top = el( 'div', { class: 'mfc-top' } );
			var topMatch = matchSelect( state.match );
			topMatch.addEventListener( 'change', function () { state.match = topMatch.value; sync(); } );
			top.appendChild( document.createTextNode( I18N.ofGroups + ' ' ) );
			top.appendChild( topMatch );
			root.appendChild( top );

			state.groups.forEach( function ( group, gi ) {
				if ( group.match !== 'any' ) { group.match = 'all'; }
				if ( ! Array.isArray( group.rules ) ) { group.rules = []; }
				var box = el( 'div', { class: 'mfc-group' } );
				var head = el( 'div', { class: 'mfc-group-head' } );
				var gMatch = matchSelect( group.match );
				gMatch.addEventListener( 'change', function () { group.match = gMatch.value; sync(); } );
				head.appendChild( document.createTextNode( I18N.ofRules + ' ' ) );
				head.appendChild( gMatch );
				var delG = el( 'button', { type: 'button', class: 'button-link-delete mfc-del-group', text: I18N.removeGroup } );
				delG.addEventListener( 'click', function () { state.groups.splice( gi, 1 ); sync(); render(); } );
				head.appendChild( delG );
				box.appendChild( head );

				group.rules.forEach( function ( rule, ri ) {
					if ( ! TYPES[ rule.type ] ) { rule.type = firstType; }
					var ops = Object.keys( ( TYPES[ rule.type ] || {} ).ops || {} );
					if ( ops.indexOf( rule.op ) === -1 ) { rule.op = ops[ 0 ]; }
					var ruleBox = el( 'div', { class: 'mfc-rule' } );
					var tSel = typeSelect( rule.type );
					tSel.className += ' mfc-type';
					var oSel = opSelect( rule.type, rule.op );
					oSel.className += ' mfc-op';
					var vWrap = el( 'div', { class: 'mfc-val' } );
					var vIn = valueInput( rule.type, rule.value );
					vWrap.appendChild( vIn );
					tSel.addEventListener( 'change', function () { rule.type = tSel.value; rule.op = null; rule.value = null; sync(); render(); } );
					oSel.addEventListener( 'change', function () { rule.op = oSel.value; sync(); } );
					var onVal = function () { rule.value = readValue( vIn, rule.type ); sync(); };
					vIn.addEventListener( 'change', onVal );
					vIn.addEventListener( 'input', onVal );
					if ( window.jQuery ) { window.jQuery( vIn ).on( 'change', onVal ); } // catch select2-triggered change
					var delR = el( 'button', { type: 'button', class: 'mfc-del', title: I18N.removeRule, 'aria-label': I18N.removeRule, text: '×' } );
					delR.addEventListener( 'click', function () { group.rules.splice( ri, 1 ); sync(); render(); } );
					ruleBox.appendChild( tSel );
					ruleBox.appendChild( oSel );
					ruleBox.appendChild( vWrap );
					ruleBox.appendChild( delR );
					box.appendChild( ruleBox );
				} );

				var addR = el( 'button', { type: 'button', class: 'button mfc-addrule', text: I18N.addRule } );
				addR.addEventListener( 'click', function () { group.rules.push( { type: firstType, op: null, value: null } ); sync(); render(); } );
				box.appendChild( addR );
				root.appendChild( box );
			} );

			var addG = el( 'button', { type: 'button', class: 'button button-secondary', text: I18N.addGroup } );
			addG.addEventListener( 'click', function () { state.groups.push( { match: 'all', rules: [] } ); sync(); render(); } );
			root.appendChild( addG );

			enhance();
		}
		render();
		sync();
	}

	function init() {
		var root = document.querySelector( '.moksafocou-rules-builder' );
		var textarea = document.getElementById( CFG.field || 'moksafocou_rules_json' );
		if ( ! root || ! textarea || ! Object.keys( TYPES ).length ) { return; }
		textarea.style.display = 'none';
		var label = textarea.closest( '.form-field' );
		if ( label ) { label.style.display = 'none'; }
		build( root, textarea );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
