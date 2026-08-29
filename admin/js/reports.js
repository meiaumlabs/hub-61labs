/* Hub 61 Labs — página de Relatórios de Performance. */
( function () {
	'use strict';
	var cfg = window.HUB61R || {};

	function post( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( item ) { body.append( k, item ); } );
			} else {
				body.set( k, v );
			}
		} );
		return fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) { return r.json(); } );
	}

	function val( id ) { var el = document.getElementById( id ); return el ? el.value : ''; }
	function checked( id ) { var el = document.getElementById( id ); return el && el.checked ? '1' : ''; }

	function selectedPlugins() {
		var out = [];
		document.querySelectorAll( 'input[name="plugins[]"]:checked' ).forEach( function ( c ) { out.push( c.value ); } );
		return out;
	}

	function payload() {
		return {
			evo_url: val( 'evo_url' ),
			evo_key: val( 'evo_key' ),
			evo_instance: val( 'evo_instance' ),
			plugins: selectedPlugins(),
			schedule: val( 'schedule' ),
			range: val( 'hub61-range' ),
			optin: checked( 'optin' ),
			email: val( 'email' ),
			whatsapp: val( 'whatsapp' )
		};
	}

	function msg( text, ok ) {
		var el = document.getElementById( 'hub61-report-msg' );
		if ( ! el ) { return; }
		el.textContent = text;
		el.hidden = false;
		el.className = 'hub61-notice ' + ( ok ? 'hub61-notice-ok' : 'hub61-notice-warn' );
		el.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function busy( btn, on, label ) {
		if ( ! btn ) { return; }
		btn.disabled = on;
		btn.classList.toggle( 'is-busy', on );
		if ( label ) { btn.textContent = label; }
	}

	document.addEventListener( 'click', function ( e ) {
		var save = e.target.closest( '#hub61-save' );
		var test = e.target.closest( '#hub61-evo-test' );
		var send = e.target.closest( '#hub61-send-now' );

		if ( save ) {
			e.preventDefault();
			busy( save, true );
			post( 'hub61_report_save', payload() ).then( function ( res ) {
				busy( save, false );
				msg( ( res && res.data && res.data.message ) || 'OK', !!( res && res.success ) );
			} ).catch( function () { busy( save, false ); msg( 'Erro de rede.', false ); } );
		}

		if ( test ) {
			e.preventDefault();
			var st = document.getElementById( 'hub61-evo-status' );
			if ( st ) { st.textContent = 'testando…'; }
			busy( test, true );
			post( 'hub61_report_test', { evo_url: val( 'evo_url' ), evo_key: val( 'evo_key' ), evo_instance: val( 'evo_instance' ) } ).then( function ( res ) {
				busy( test, false );
				var ok = !!( res && res.success );
				if ( st ) { st.textContent = ( res && res.data && res.data.message ) || ( ok ? 'ok' : 'falhou' ); st.style.color = ok ? '#16a34a' : '#b91c1c'; }
			} ).catch( function () { busy( test, false ); if ( st ) { st.textContent = 'erro de rede'; st.style.color = '#b91c1c'; } } );
		}

		if ( send ) {
			e.preventDefault();
			if ( ! window.confirm( 'Gerar e enviar o relatório agora (e-mail + WhatsApp) para você e para o administrador da 61 Labs?' ) ) { return; }
			busy( send, true, 'Enviando…' );
			// Salva antes, para usar a config atual.
			post( 'hub61_report_save', payload() ).then( function () {
				return post( 'hub61_report_send', {} );
			} ).then( function ( res ) {
				busy( send, false, 'Enviar relatório agora' );
				msg( ( res && res.data && res.data.message ) || 'OK', !!( res && res.success ) );
			} ).catch( function () { busy( send, false, 'Enviar relatório agora' ); msg( 'Erro de rede.', false ); } );
		}
	} );
}() );
