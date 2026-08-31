/* Hub 61 Labs — construtor de Relatórios (modelos + pré-visualização + envio). */
( function () {
	'use strict';
	var cfg = window.HUB61R || {};
	var i18n = cfg.i18n || {};

	function el( id ) { return document.getElementById( id ); }
	function val( id ) { var e = el( id ); return e ? e.value : ''; }
	function checked( id ) { var e = el( id ); return e && e.checked ? '1' : ''; }

	function post( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			if ( Array.isArray( v ) ) { v.forEach( function ( x ) { body.append( k, x ); } ); }
			else { body.set( k, v ); }
		} );
		return fetch( cfg.ajax, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} );
	}
	function postJSON( action, data ) { return post( action, data ).then( function ( r ) { return r.json(); } ); }

	function plugins() {
		var out = [];
		document.querySelectorAll( '.tpl-plugin:checked' ).forEach( function ( c ) { out.push( c.value ); } );
		return out;
	}

	// Config do modelo (usada por preview, salvar, enviar).
	function tplPayload() {
		return {
			tpl_id: val( 'tpl_id' ),
			tpl_name: val( 'tpl_name' ),
			make_active: checked( 'make_active' ),
			logo: val( 'logo' ),
			color_primary: val( 'color_primary' ),
			color_ink: val( 'color_ink' ),
			intro: val( 'intro' ),
			footer: val( 'footer' ),
			plugins: plugins(),
			range: val( 'range' ),
			show_kpis: checked( 'show_kpis' ),
			show_charts: checked( 'show_charts' ),
			show_tables: checked( 'show_tables' )
		};
	}
	function cfgPayload() {
		return {
			evo_url: val( 'evo_url' ), evo_key: val( 'evo_key' ), evo_instance: val( 'evo_instance' ),
			schedule: val( 'schedule' ), optin: checked( 'optin' ), email: val( 'email' ), whatsapp: val( 'whatsapp' )
		};
	}

	function msg( text, ok ) {
		var e = el( 'hub61-report-msg' ); if ( ! e ) { return; }
		e.textContent = text; e.hidden = false;
		e.className = 'hub61-notice ' + ( ok ? 'hub61-notice-ok' : 'hub61-notice-warn' );
		e.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}
	function busy( btn, on, label ) { if ( btn ) { btn.disabled = on; btn.classList.toggle( 'is-busy', on ); if ( label ) { btn.textContent = label; } } }

	/* ---- Pré-visualização HTML ao vivo (debounced) ---- */
	var timer = null;
	function refreshPreview() {
		var spin = el( 'preview_spin' ); if ( spin ) { spin.textContent = '…'; }
		postJSON( 'hub61_report_preview_html', tplPayload() ).then( function ( res ) {
			if ( spin ) { spin.textContent = ''; }
			var frame = el( 'preview_frame' );
			if ( frame && res && res.success ) { frame.srcdoc = res.data.html; }
		} ).catch( function () { if ( spin ) { spin.textContent = ''; } } );
	}
	function schedulePreview() { clearTimeout( timer ); timer = setTimeout( refreshPreview, 500 ); }

	/* ---- Preencher o formulário a partir de um modelo ---- */
	function fillForm( id, tpl ) {
		el( 'tpl_id' ).value = id || '';
		el( 'tpl_name' ).value = tpl.name || '';
		el( 'logo' ).value = tpl.logo || '';
		var prev = document.querySelector( '.hub61-logo-prev' );
		if ( prev ) { prev.innerHTML = tpl.logo ? '<img src="' + tpl.logo + '" alt="">' : ''; }
		el( 'color_primary' ).value = tpl.color_primary || '#16a34a';
		el( 'color_ink' ).value = tpl.color_ink || '#0f172a';
		el( 'intro' ).value = tpl.intro || '';
		el( 'footer' ).value = tpl.footer || '';
		el( 'range' ).value = tpl.range || '28d';
		el( 'show_kpis' ).checked = !! tpl.show_kpis;
		el( 'show_charts' ).checked = !! tpl.show_charts;
		el( 'show_tables' ).checked = !! tpl.show_tables;
		var want = tpl.plugins || [];
		document.querySelectorAll( '.tpl-plugin' ).forEach( function ( c ) { c.checked = want.indexOf( c.value ) !== -1; } );
		refreshPreview();
	}

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) { fn(); }
		else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}

	ready( function () {
		refreshPreview();

		// Qualquer mudança no formulário do modelo → atualiza a prévia.
		var form = el( 'hub61-builder' );
		if ( form ) {
			form.addEventListener( 'input', function ( e ) {
				if ( e.target.closest( '#evo_url, #evo_key, #evo_instance, #email, #whatsapp' ) ) { return; }
				schedulePreview();
			} );
			form.addEventListener( 'change', function ( e ) {
				if ( e.target.classList && e.target.classList.contains( 'tpl-plugin' ) ) { schedulePreview(); }
			} );
		}

		// Logo via biblioteca de mídia.
		var frame;
		var pick = el( 'logo_pick' );
		if ( pick && window.wp && window.wp.media ) {
			pick.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( frame ) { frame.open(); return; }
				frame = window.wp.media( { title: i18n.chooseLogo || 'Logotipo', button: { text: i18n.use || 'Usar' }, multiple: false, library: { type: 'image' } } );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					el( 'logo' ).value = a.url;
					var prev = document.querySelector( '.hub61-logo-prev' );
					if ( prev ) { prev.innerHTML = '<img src="' + a.url + '" alt="">'; }
					refreshPreview();
				} );
				frame.open();
			} );
		}
		var clr = el( 'logo_clear' );
		if ( clr ) { clr.addEventListener( 'click', function ( e ) { e.preventDefault(); el( 'logo' ).value = ''; var p = document.querySelector( '.hub61-logo-prev' ); if ( p ) { p.innerHTML = ''; } refreshPreview(); } ); }

		// Trocar de modelo.
		var sel = el( 'tpl_select' );
		if ( sel ) {
			sel.addEventListener( 'change', function () {
				postJSON( 'hub61_report_tpl_get', { tpl_id: sel.value } ).then( function ( res ) {
					if ( res && res.success ) { fillForm( res.data.id, res.data.tpl ); }
				} );
			} );
		}
		// Novo modelo (limpa em branco).
		var neu = el( 'tpl_new' );
		if ( neu ) { neu.addEventListener( 'click', function () { fillForm( '', { name: 'Novo modelo', color_primary: '#16a34a', color_ink: '#0f172a', footer: '© 61 Labs — os dados são seus.', range: '28d', show_kpis: true, show_charts: true, show_tables: true, plugins: plugins() } ); el( 'tpl_name' ).focus(); } ); }
		// Excluir modelo.
		var del = el( 'tpl_delete' );
		if ( del ) { del.addEventListener( 'click', function () {
			if ( ! window.confirm( 'Excluir este modelo?' ) ) { return; }
			postJSON( 'hub61_report_tpl_delete', { tpl_id: val( 'tpl_id' ) } ).then( function ( res ) {
				if ( res && res.success ) { window.location.reload(); } else { msg( ( res && res.data && res.data.message ) || 'Erro', false ); }
			} );
		} ); }

		// Salvar modelo.
		var save = el( 'tpl_savebtn' );
		if ( save ) { save.addEventListener( 'click', function () {
			busy( save, true );
			postJSON( 'hub61_report_tpl_save', tplPayload() ).then( function ( res ) {
				busy( save, false );
				if ( res && res.success ) { msg( res.data.message || 'Salvo.', true ); el( 'tpl_id' ).value = res.data.id; setTimeout( function () { window.location.reload(); }, 500 ); }
				else { msg( ( res && res.data && res.data.message ) || 'Erro', false ); }
			} ).catch( function () { busy( save, false ); msg( 'Erro de rede.', false ); } );
		} ); }

		// Salvar conexão/agendamento.
		var csave = el( 'cfg_save' );
		if ( csave ) { csave.addEventListener( 'click', function () {
			busy( csave, true );
			postJSON( 'hub61_report_save', cfgPayload() ).then( function ( res ) {
				busy( csave, false ); msg( ( res && res.data && res.data.message ) || 'OK', !!( res && res.success ) );
			} ).catch( function () { busy( csave, false ); msg( 'Erro de rede.', false ); } );
		} ); }

		// Testar Evolution.
		var test = el( 'hub61-evo-test' );
		if ( test ) { test.addEventListener( 'click', function () {
			var st = el( 'hub61-evo-status' ); if ( st ) { st.textContent = 'testando…'; st.style.color = ''; }
			busy( test, true );
			postJSON( 'hub61_report_test', { evo_url: val( 'evo_url' ), evo_key: val( 'evo_key' ), evo_instance: val( 'evo_instance' ) } ).then( function ( res ) {
				busy( test, false ); var ok = !!( res && res.success );
				if ( st ) { st.textContent = ( res && res.data && res.data.message ) || ( ok ? 'ok' : 'falhou' ); st.style.color = ok ? '#16a34a' : '#b91c1c'; }
			} ).catch( function () { busy( test, false ); if ( st ) { st.textContent = 'erro de rede'; st.style.color = '#b91c1c'; } } );
		} ); }

		// Ver PDF (reflete edições não salvas): POST → blob → nova aba.
		var vpdf = el( 'preview_pdf' );
		if ( vpdf ) { vpdf.addEventListener( 'click', function () {
			busy( vpdf, true, 'Gerando…' );
			post( 'hub61_report_preview', tplPayload() ).then( function ( r ) { return r.blob(); } ).then( function ( blob ) {
				busy( vpdf, false, 'Ver PDF' );
				var url = URL.createObjectURL( blob ); window.open( url, '_blank' );
				setTimeout( function () { URL.revokeObjectURL( url ); }, 60000 );
			} ).catch( function () { busy( vpdf, false, 'Ver PDF' ); msg( 'Falha ao gerar o PDF.', false ); } );
		} ); }

		// Enviar agora (usa o modelo em edição).
		var send = el( 'send_now' );
		if ( send ) { send.addEventListener( 'click', function () {
			if ( ! window.confirm( 'Gerar e enviar este relatório agora (e-mail + WhatsApp) para você e para o administrador da 61 Labs?' ) ) { return; }
			busy( send, true, 'Enviando…' );
			// Salva conexão/recebimento antes, para usar a config atual.
			postJSON( 'hub61_report_save', cfgPayload() ).then( function () {
				return postJSON( 'hub61_report_send', tplPayload() );
			} ).then( function ( res ) {
				busy( send, false, 'Enviar agora' );
				msg( ( res && res.data && res.data.message ) || 'OK', !!( res && res.success ) );
			} ).catch( function () { busy( send, false, 'Enviar agora' ); msg( 'Erro de rede.', false ); } );
		} ); }
	} );
}() );
