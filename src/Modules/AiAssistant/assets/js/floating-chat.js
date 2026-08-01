/**
 * Moksa 優惠券 AI 浮動對話窗 — 後台右下角助手。訊息經 REST(moksafocou/v1/ai-chat)走
 * agentic 迴圈。破壞性動作 → REST 回 confirm,跳「確認執行 / 取消」,按確認才 POST
 * /ai-confirm 真正執行。樣式全用 #moksafocou-ai-* 前綴壓過 WP admin 預設。
 */
( function ( wp ) {
	if ( ! wp || ! wp.apiFetch || ! document.body ) {
		return;
	}
	var apiFetch = wp.apiFetch;
	var cfg = window.moksafocouAi || {};
	var NAME = cfg.name || 'Moksa AI';
	var GREETING = cfg.greeting || '';
	var EXAMPLES = cfg.examples || [];

	var style = document.createElement( 'style' );
	style.textContent =
		'#moksafocou-ai-fab{position:fixed;right:22px;bottom:22px;z-index:99998;display:flex;align-items:center;gap:7px;' +
		'background:#1d2327;color:#fff;border:0;border-radius:24px;padding:11px 18px;' +
		'cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.28);font-size:14px;font-weight:600;transition:transform .15s,box-shadow .15s;}' +
		'#moksafocou-ai-fab:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.36);}' +
		'#moksafocou-ai-fab .dashicons{color:#d4af37;font-size:18px;width:18px;height:18px;line-height:18px;}' +
		'#moksafocou-ai-panel{position:fixed;right:22px;bottom:80px;z-index:99999;width:360px;max-width:92vw;height:520px;max-height:78vh;' +
		'display:none;flex-direction:column;background:#fff;border:1px solid #e8e8ea;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.18);overflow:hidden;' +
		'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Sans TC",sans-serif;animation:mo-ai-in .18s ease;}' +
		'@keyframes mo-ai-in{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:none;}}' +
		'#moksafocou-ai-panel .mo-ai-head{background:#fff;color:#1d2327;padding:13px 16px;display:flex;align-items:center;gap:9px;' +
		'border-bottom:2px solid;border-image:linear-gradient(90deg,#c9a227,rgba(201,162,39,0)) 1;}' +
		'#moksafocou-ai-panel .mo-ai-dot{width:9px;height:9px;border-radius:50%;background:#34d399;box-shadow:0 0 0 3px rgba(52,211,153,.25);}' +
		'#moksafocou-ai-panel .mo-ai-head b{font-size:15px;font-weight:700;}' +
		'#moksafocou-ai-panel .mo-ai-beta{font-size:11px;color:#8a8f94;font-weight:400;}' +
		'#moksafocou-ai-panel .mo-ai-clear{margin-left:auto;background:#f0f0f1;border:0;color:#50575e;border-radius:8px;padding:4px 9px;font-size:12px;cursor:pointer;box-shadow:none;}' +
		'#moksafocou-ai-panel .mo-ai-clear:hover{background:#e2e4e7;}' +
		'#moksafocou-ai-panel .mo-ai-close{background:transparent;border:0;color:#787c82;cursor:pointer;padding:2px 4px;box-shadow:none;display:flex;align-items:center;}' +
		'#moksafocou-ai-panel .mo-ai-close .dashicons{font-size:20px;width:20px;height:20px;line-height:20px;}' +
		'#moksafocou-ai-panel .mo-ai-close:hover{color:#1d2327;}' +
		'#moksafocou-ai-panel .mo-ai-msgs{flex:1;overflow-y:auto;padding:14px;background:#f6f7f7;font-size:13.5px;line-height:1.6;}' +
		'#moksafocou-ai-panel .mo-ai-row{margin:10px 0;display:flex;gap:8px;align-items:flex-start;}' +
		'#moksafocou-ai-panel .mo-ai-row.user{justify-content:flex-end;}' +
		'#moksafocou-ai-panel .mo-ai-col{display:flex;flex-direction:column;max-width:78%;}' +
		'#moksafocou-ai-panel .mo-ai-row.user .mo-ai-col{align-items:flex-end;}' +
		'#moksafocou-ai-panel .mo-ai-time{font-size:10.5px;color:#a7abb0;margin:3px 5px 0;}' +
		'#moksafocou-ai-panel .mo-ai-av{width:28px;height:28px;border-radius:50%;flex:0 0 28px;display:flex;align-items:center;justify-content:center;background:#1d2327;}' +
		'#moksafocou-ai-panel .mo-ai-av .dashicons{color:#d4af37;font-size:16px;width:16px;height:16px;line-height:16px;}' +
		'#moksafocou-ai-panel .mo-ai-bub{max-width:100%;padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-break:break-word;}' +
		'#moksafocou-ai-panel .mo-ai-bub a{color:#a16207;text-decoration:underline;}' +
		'#moksafocou-ai-panel .mo-ai-row.bot .mo-ai-bub{background:#fff;border:1px solid #e2e4e7;color:#1d2327;border-bottom-left-radius:4px;}' +
		'#moksafocou-ai-panel .mo-ai-row.user .mo-ai-bub{background:#1d2327;color:#fff;border-bottom-right-radius:4px;}' +
		'#moksafocou-ai-panel .mo-ai-row.user .mo-ai-bub a{color:#fde68a;}' +
		'#moksafocou-ai-panel .mo-ai-confirm{margin-top:9px;display:flex;gap:8px;}' +
		'#moksafocou-ai-panel .mo-ai-diff{margin:7px 0 2px;font-size:12px;}' +
		'#moksafocou-ai-panel .mo-ai-diff summary{cursor:pointer;color:#50575e;outline:none;}' +
		'#moksafocou-ai-panel .mo-ai-diff table{width:100%;border-collapse:collapse;margin-top:5px;}' +
		'#moksafocou-ai-panel .mo-ai-diff th{text-align:left;color:#646970;font-weight:500;padding:2px 8px 2px 0;white-space:nowrap;vertical-align:top;}' +
		'#moksafocou-ai-panel .mo-ai-diff td{padding:2px 0;word-break:break-word;}' +
		'#moksafocou-ai-panel .mo-ai-confirm button{border:0;border-radius:8px;padding:6px 13px;font-size:12.5px;cursor:pointer;box-shadow:none;}' +
		'#moksafocou-ai-panel .mo-ai-yes{background:#1d2327;color:#fff;}#moksafocou-ai-panel .mo-ai-yes:hover{background:#2c3338;}' +
		'#moksafocou-ai-panel .mo-ai-no{background:#f0f0f1;color:#50575e;}#moksafocou-ai-panel .mo-ai-no:hover{background:#e2e4e7;}' +
		'#moksafocou-ai-panel .mo-ai-typing span{display:inline-block;width:6px;height:6px;margin:0 1px;border-radius:50%;background:#b9bcc0;animation:mo-ai-blink 1.2s infinite both;}' +
		'#moksafocou-ai-panel .mo-ai-typing span:nth-child(2){animation-delay:.2s;}#moksafocou-ai-panel .mo-ai-typing span:nth-child(3){animation-delay:.4s;}' +
		'@keyframes mo-ai-blink{0%,80%,100%{opacity:.25;}40%{opacity:1;}}' +
		'#moksafocou-ai-panel .mo-ai-chips{padding:0 14px 8px;display:flex;flex-wrap:wrap;gap:6px;background:#f6f7f7;}' +
		'#moksafocou-ai-panel .mo-ai-chip{background:#fff;border:1px solid #e0c068;color:#a16207;border-radius:14px;padding:5px 11px;font-size:12.5px;cursor:pointer;box-shadow:none;}' +
		'#moksafocou-ai-panel .mo-ai-chip:hover{background:#fdf6e3;}' +
		'#moksafocou-ai-panel .mo-ai-foot{display:flex;gap:8px;padding:10px;border-top:1px solid #e2e4e7;background:#fff;}' +
		'#moksafocou-ai-panel .mo-ai-input{flex:1;border:1px solid #c3c4c7;border-radius:20px;padding:9px 14px;font-size:13.5px;outline:none;box-shadow:none;background:#fff;-webkit-appearance:none;appearance:none;min-height:0;}' +
		'#moksafocou-ai-panel .mo-ai-input:focus{border-color:#c9a227;box-shadow:0 0 0 2px rgba(201,162,39,.18);outline:none;}' +
		'#moksafocou-ai-panel .mo-ai-send{background:#1d2327;color:#fff;border:0;border-radius:50%;width:38px;height:38px;flex:0 0 38px;cursor:pointer;font-size:16px;box-shadow:none;}' +
		'#moksafocou-ai-panel .mo-ai-send:hover{background:#2c3338;}#moksafocou-ai-panel .mo-ai-send:disabled{opacity:.5;cursor:default;}';
	document.head.appendChild( style );

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	var fab = document.createElement( 'button' );
	fab.id = 'moksafocou-ai-fab';
	fab.type = 'button';
	fab.innerHTML = '<span class="dashicons dashicons-tag"></span><span>' + esc( NAME ) + '</span>';

	var panel = document.createElement( 'div' );
	panel.id = 'moksafocou-ai-panel';
	panel.innerHTML =
		'<div class="mo-ai-head"><span class="mo-ai-dot"></span><b>' + esc( NAME ) + '</b>' +
		'<span class="mo-ai-beta">Beta</span><button type="button" class="mo-ai-clear">' + esc( cfg.clearLabel || 'Clear' ) + '</button>' +
		'<button type="button" class="mo-ai-close" title="' + esc( cfg.closeLabel || 'Close' ) + '" aria-label="' + esc( cfg.closeLabel || 'Close' ) + '"><span class="dashicons dashicons-no-alt"></span></button></div>' +
		'<div class="mo-ai-msgs"></div>' +
		'<div class="mo-ai-chips"></div>' +
		'<div class="mo-ai-foot"><input type="text" class="mo-ai-input" placeholder="' + esc( cfg.placeholder || '' ) + '">' +
		'<button type="button" class="mo-ai-send" title="' + esc( cfg.sendLabel || 'Send' ) + '">➤</button></div>';

	document.body.appendChild( fab );
	document.body.appendChild( panel );

	var msgs = panel.querySelector( '.mo-ai-msgs' );
	var chips = panel.querySelector( '.mo-ai-chips' );
	var input = panel.querySelector( '.mo-ai-input' );
	var send = panel.querySelector( '.mo-ai-send' );
	var busy = false;
	var started = false;

	var LS_KEY = 'moksafocouAiChat:' + ( cfg.userId || 0 );
	var HISTORY = [];

	function pad2( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}
	function stamp( ms ) {
		var d = new Date( ms );
		return d.getFullYear() + '-' + pad2( d.getMonth() + 1 ) + '-' + pad2( d.getDate() ) +
			' ' + pad2( d.getHours() ) + ':' + pad2( d.getMinutes() ) + ':' + pad2( d.getSeconds() );
	}
	function save_history() {
		try {
			if ( HISTORY.length > 100 ) {
				HISTORY = HISTORY.slice( -100 );
			}
			window.localStorage.setItem( LS_KEY, JSON.stringify( HISTORY ) );
		} catch ( e ) {}
	}
	function load_history() {
		try {
			var raw = window.localStorage.getItem( LS_KEY );
			var arr = raw ? JSON.parse( raw ) : null;
			return Array.isArray( arr ) ? arr : null;
		} catch ( e ) {
			return null;
		}
	}

	function inline_into( parent, text ) {
		var re = /(\*\*([^*]+)\*\*)|(https?:\/\/[^\s]+)/g;
		var last = 0, m;
		while ( ( m = re.exec( text ) ) !== null ) {
			if ( m.index > last ) {
				parent.appendChild( document.createTextNode( text.slice( last, m.index ) ) );
			}
			if ( m[ 1 ] ) {
				var b = document.createElement( 'strong' );
				b.textContent = m[ 2 ];
				parent.appendChild( b );
			} else {
				var a = document.createElement( 'a' );
				a.href = m[ 3 ];
				a.textContent = m[ 3 ];
				parent.appendChild( a );
			}
			last = m.index + m[ 0 ].length;
		}
		if ( last < text.length ) {
			parent.appendChild( document.createTextNode( text.slice( last ) ) );
		}
	}

	function fill_bubble( bubble, text ) {
		bubble.innerHTML = '';
		text = ( text == null ? '' : String( text ) ).replace( /\r\n/g, '\n' );
		text.split( '\n' ).forEach( function ( line, i ) {
			if ( i > 0 ) {
				bubble.appendChild( document.createElement( 'br' ) );
			}
			var bullet = line.match( /^\s*[*\-]\s+(.*)$/ );
			if ( bullet ) {
				bubble.appendChild( document.createTextNode( '• ' ) );
				inline_into( bubble, bullet[ 1 ] );
			} else {
				inline_into( bubble, line );
			}
		} );
	}

	function bot_avatar() {
		var av = document.createElement( 'div' );
		av.className = 'mo-ai-av';
		av.innerHTML = '<span class="dashicons dashicons-tag"></span>';
		return av;
	}

	function make_row( who ) {
		var row = document.createElement( 'div' );
		row.className = 'mo-ai-row ' + who;
		if ( who === 'bot' ) {
			row.appendChild( bot_avatar() );
		}
		var col = document.createElement( 'div' );
		col.className = 'mo-ai-col';
		row.appendChild( col );
		return { row: row, col: col };
	}

	function add_time( col, ts ) {
		var t = document.createElement( 'div' );
		t.className = 'mo-ai-time';
		t.textContent = stamp( ts );
		col.appendChild( t );
	}

	function reset() {
		msgs.innerHTML = '';
		started = false;
		chips.style.display = 'flex';
		HISTORY = [];
		try {
			window.localStorage.removeItem( LS_KEY );
		} catch ( e ) {}
		if ( GREETING ) {
			add_msg( GREETING, 'bot' );
		}
		render_chips();
	}

	function restore() {
		var saved = load_history();
		if ( ! saved || ! saved.length ) {
			reset();
			return;
		}
		msgs.innerHTML = '';
		started = true;
		chips.style.display = 'none';
		saved.forEach( function ( m ) {
			add_msg( m.t, m.w, m.ts, true );
		} );
		HISTORY = saved.slice();
	}

	function render_chips() {
		chips.innerHTML = '';
		EXAMPLES.forEach( function ( ex ) {
			var c = document.createElement( 'button' );
			c.type = 'button';
			c.className = 'mo-ai-chip';
			c.textContent = ex;
			c.addEventListener( 'click', function () {
				input.value = ex;
				ask();
			} );
			chips.appendChild( c );
		} );
	}

	function add_msg( text, who, ts, skip_persist ) {
		ts = ts || Date.now();
		var r = make_row( who );
		var bubble = document.createElement( 'div' );
		bubble.className = 'mo-ai-bub';
		fill_bubble( bubble, text );
		r.col.appendChild( bubble );
		add_time( r.col, ts );
		msgs.appendChild( r.row );
		msgs.scrollTop = msgs.scrollHeight;
		if ( ! skip_persist ) {
			HISTORY.push( { t: String( text == null ? '' : text ), w: who, ts: ts } );
			save_history();
		}
		return bubble;
	}

	function add_typing() {
		var r = make_row( 'bot' );
		var bub = document.createElement( 'div' );
		bub.className = 'mo-ai-bub mo-ai-typing';
		bub.innerHTML = '<span></span><span></span><span></span>';
		r.col.appendChild( bub );
		msgs.appendChild( r.row );
		msgs.scrollTop = msgs.scrollHeight;
		return r.row;
	}

	function render_confirm( confirm ) {
		var r = make_row( 'bot' );
		var bubble = document.createElement( 'div' );
		bubble.className = 'mo-ai-bub';
		fill_bubble( bubble, confirm.summary );
		if ( confirm.fields && confirm.fields.length ) {
			var det = document.createElement( 'details' );
			det.className = 'mo-ai-diff';
			var sm = document.createElement( 'summary' );
			sm.textContent = cfg.diffLabel || 'View field contents';
			det.appendChild( sm );
			var tbl = document.createElement( 'table' );
			confirm.fields.forEach( function ( f ) {
				var tr = document.createElement( 'tr' );
				var th = document.createElement( 'th' );
				th.textContent = f.key;
				var td = document.createElement( 'td' );
				td.textContent = f.value;
				tr.appendChild( th );
				tr.appendChild( td );
				tbl.appendChild( tr );
			} );
			det.appendChild( tbl );
			bubble.appendChild( det );
		}
		var box = document.createElement( 'div' );
		box.className = 'mo-ai-confirm';
		var yes = document.createElement( 'button' );
		yes.type = 'button';
		yes.className = 'mo-ai-yes';
		yes.textContent = cfg.confirmYes || 'Confirm';
		var no = document.createElement( 'button' );
		no.type = 'button';
		no.className = 'mo-ai-no';
		no.textContent = cfg.confirmNo || 'Cancel';
		box.appendChild( yes );
		box.appendChild( no );
		bubble.appendChild( box );
		r.col.appendChild( bubble );
		add_time( r.col, Date.now() );
		msgs.appendChild( r.row );
		msgs.scrollTop = msgs.scrollHeight;

		no.addEventListener( 'click', function () {
			box.remove();
			add_msg( cfg.cancelled || 'Cancelled.', 'bot' );
		} );
		yes.addEventListener( 'click', function () {
			yes.disabled = true;
			no.disabled = true;
			yes.textContent = cfg.running || 'Running…';
			apiFetch( { path: '/moksafocou/v1/ai-confirm', method: 'POST', data: { token: confirm.token } } )
				.then( function ( resp ) {
					box.remove();
					add_msg( resp && resp.reply ? resp.reply : ( resp && resp.error ? '⚠ ' + resp.error : ( cfg.emptyReply || '(No reply)' ) ), 'bot' );
				} )
				.catch( function ( e ) {
					box.remove();
					add_msg( '⚠ ' + ( e && e.message ? e.message : ( cfg.errorPrefix || 'An error occurred' ) ), 'bot' );
				} );
		} );
	}

	function ask() {
		var text = ( input.value || '' ).trim();
		if ( ! text || busy ) {
			return;
		}
		if ( ! started ) {
			started = true;
			chips.style.display = 'none';
		}
		busy = true;
		send.disabled = true;
		input.value = '';
		add_msg( text, 'user' );
		var typing = add_typing();

		var prior = HISTORY.slice( 0, -1 ).slice( -10 ).map( function ( m ) {
			return { role: m.w === 'user' ? 'user' : 'assistant', text: m.t };
		} );

		apiFetch( { path: '/moksafocou/v1/ai-chat', method: 'POST', data: { message: text, history: prior } } )
			.then( function ( resp ) {
				typing.remove();
				if ( resp && resp.confirm && resp.confirm.token ) {
					render_confirm( resp.confirm );
				} else {
					add_msg( resp && resp.reply ? resp.reply : ( resp && resp.error ? '⚠ ' + resp.error : ( cfg.emptyReply || '(No reply)' ) ), 'bot' );
				}
			} )
			.catch( function ( e ) {
				typing.remove();
				add_msg( '⚠ ' + ( e && e.message ? e.message : ( cfg.errorPrefix || 'An error occurred' ) ), 'bot' );
			} )
			.finally( function () {
				busy = false;
				send.disabled = false;
				input.focus();
			} );
	}

	function open_panel() {
		panel.style.display = 'flex';
		fab.style.display = 'none';
		if ( msgs.children.length === 0 ) {
			restore();
		}
		input.focus();
	}
	function close_panel() {
		panel.style.display = 'none';
		fab.style.display = 'flex';
	}
	fab.addEventListener( 'click', open_panel );
	panel.querySelector( '.mo-ai-close' ).addEventListener( 'click', close_panel );
	panel.querySelector( '.mo-ai-clear' ).addEventListener( 'click', reset );
	send.addEventListener( 'click', ask );
	input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			ask();
		}
	} );

} )( window.wp );
