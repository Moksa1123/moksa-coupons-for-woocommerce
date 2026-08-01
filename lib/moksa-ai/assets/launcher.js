/**
 * Moksa AI Launcher — the one floating admin assistant, ability-driven across every Moksa
 * plugin's namespace. Renders a single button + panel; lists the abilities the current user
 * may run (grouped by the sections each plugin registered) and runs them via the REST proxy.
 * Loaded once by the elected bundled copy (see moksa-ai.php version election).
 */
( function () {
	'use strict';

	if ( ! window.moksaAI || window.__moksaAImounted ) {
		return; // single mount, even if (somehow) enqueued twice
	}
	window.__moksaAImounted = true;

	var cfg = window.moksaAI;
	var S = cfg.strings || {};

	function el( tag, cls, html ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( html != null ) { n.innerHTML = html; }
		return n;
	}

	function api( path, opts ) {
		opts = opts || {};
		opts.headers = Object.assign( { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }, opts.headers || {} );
		return fetch( cfg.rest.replace( /\/$/, '' ) + path, opts ).then( function ( r ) { return r.json(); } );
	}

	// --- DOM scaffold (one button + one panel) --------------------------------
	var btn = el( 'button', 'moksa-ai-btn', '✨' );
	btn.title = S.title || 'Moksa';
	btn.setAttribute( 'aria-label', S.title || 'Moksa AI' );

	var panel = el( 'div', 'moksa-ai-panel', '' );
	panel.hidden = true;
	var head = el( 'div', 'moksa-ai-head', '<strong>' + ( S.title || 'Moksa' ) + '</strong>' );
	var close = el( 'button', 'moksa-ai-close', '×' );
	close.setAttribute( 'aria-label', 'close' );
	head.appendChild( close );
	var search = el( 'input', 'moksa-ai-search' );
	search.type = 'search';
	search.placeholder = S.search || 'Search…';
	var body = el( 'div', 'moksa-ai-body', '' );
	var out = el( 'div', 'moksa-ai-out', '' );
	panel.appendChild( head );
	panel.appendChild( search );
	panel.appendChild( body );
	panel.appendChild( out );

	document.body.appendChild( btn );
	document.body.appendChild( panel );

	var abilities = [];
	var loaded = false;

	function sectionFor( ns ) {
		var secs = cfg.sections || [];
		for ( var i = 0; i < secs.length; i++ ) {
			if ( secs[ i ].namespace === ns ) { return secs[ i ]; }
		}
		return { label: ns.replace( /\/$/, '' ), icon: '', namespace: ns };
	}

	function render( filter ) {
		body.innerHTML = '';
		var q = ( filter || '' ).toLowerCase();
		var groups = {};
		abilities.forEach( function ( a ) {
			if ( q && ( a.label + ' ' + a.description + ' ' + a.name ).toLowerCase().indexOf( q ) === -1 ) { return; }
			( groups[ a.namespace ] = groups[ a.namespace ] || [] ).push( a );
		} );
		var order = ( cfg.sections || [] ).map( function ( s ) { return s.namespace; } );
		Object.keys( groups ).sort( function ( x, y ) { return order.indexOf( x ) - order.indexOf( y ); } ).forEach( function ( ns ) {
			var sec = sectionFor( ns );
			var g = el( 'div', 'moksa-ai-group' );
			g.appendChild( el( 'div', 'moksa-ai-group-h', ( sec.icon ? '' : '' ) + sec.label ) );
			groups[ ns ].forEach( function ( a ) {
				var item = el( 'button', 'moksa-ai-item' + ( a.destructive ? ' is-destructive' : '' ) );
				item.innerHTML = '<span class="t">' + esc( a.label ) + '</span>' + ( a.description ? '<span class="d">' + esc( a.description ) + '</span>' : '' );
				item.addEventListener( 'click', function () { openAbility( a ); } );
				g.appendChild( item );
			} );
			body.appendChild( g );
		} );
		if ( ! body.children.length ) {
			body.appendChild( el( 'p', 'moksa-ai-empty', esc( S.empty || 'No actions.' ) ) );
		}
	}

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	function openAbility( a ) {
		out.innerHTML = '';
		var props = ( a.input_schema && a.input_schema.properties ) || {};
		var keys = Object.keys( props );
		var form = el( 'div', 'moksa-ai-form' );
		form.appendChild( el( 'div', 'moksa-ai-form-h', esc( a.label ) + ( a.destructive ? ' ⚠' : '' ) ) );
		var inputs = {};
		keys.forEach( function ( k ) {
			var p = props[ k ];
			var wrap = el( 'label', 'moksa-ai-field' );
			wrap.appendChild( el( 'span', null, esc( p.title || k ) ) );
			var inp = el( 'input' );
			inp.type = ( p.type === 'integer' || p.type === 'number' ) ? 'number' : 'text';
			if ( p.description ) { inp.placeholder = p.description; }
			wrap.appendChild( inp );
			form.appendChild( wrap );
			inputs[ k ] = function () { return inp.value; };
		} );
		var go = el( 'button', 'button button-primary moksa-ai-go', esc( S.run || 'Run' ) );
		var confirmed = false;
		go.addEventListener( 'click', function () {
			var input = {};
			keys.forEach( function ( k ) {
				var v = inputs[ k ]();
				var t = props[ k ].type;
				input[ k ] = ( t === 'integer' ) ? parseInt( v, 10 ) : ( t === 'number' ? parseFloat( v ) : v );
			} );
			go.disabled = true;
			api( '/run', { method: 'POST', body: JSON.stringify( { ability: a.name, input: input, confirm: confirmed } ) } ).then( function ( res ) {
				go.disabled = false;
				if ( res && res.needs_confirm ) {
					confirmed = true;
					go.textContent = S.confirm || 'Confirm';
					go.classList.add( 'is-confirm' );
					return;
				}
				if ( res && res.error ) {
					out.innerHTML = '<div class="moksa-ai-result is-err">' + esc( res.error ) + '</div>';
					return;
				}
				out.innerHTML = '<div class="moksa-ai-result"><pre>' + esc( JSON.stringify( res.result, null, 2 ) ) + '</pre></div>';
			} ).catch( function () { go.disabled = false; } );
		} );
		form.appendChild( go );
		out.appendChild( form );
	}

	function load() {
		if ( loaded ) { return; }
		loaded = true;
		body.innerHTML = '<p class="moksa-ai-empty">…</p>';
		api( '/abilities' ).then( function ( res ) {
			abilities = ( res && res.abilities ) || [];
			render( '' );
		} ).catch( function () { loaded = false; body.innerHTML = ''; } );
	}

	function toggle( open ) {
		panel.hidden = ( open === false );
		if ( ! panel.hidden ) { load(); search.focus(); }
	}

	btn.addEventListener( 'click', function () { toggle( panel.hidden ); } );
	close.addEventListener( 'click', function () { toggle( false ); } );
	search.addEventListener( 'input', function () { render( search.value ); } );
	document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' && ! panel.hidden ) { toggle( false ); } } );
} )();
