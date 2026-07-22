/* Hub 61 Labs — painel administrativo.
 * Instala/ativa plugins da 61 Labs via AJAX e monta o e-mail de ideia. */
( function () {
	'use strict';

	var cfg = window.HUB61 || {};
	var i18n = cfg.i18n || {};

	function post( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.set( k, data[ k ] );
		} );
		return fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function message( foot, text, ok ) {
		var el = foot.querySelector( '.hub61-msg' );
		if ( ! el ) {
			el = document.createElement( 'p' );
			el.className = 'hub61-msg';
			foot.appendChild( el );
		}
		el.textContent = text;
		el.classList.toggle( 'hub61-msg-ok', !! ok );
		el.classList.toggle( 'hub61-msg-err', ! ok );
	}

	function renderState( card, state ) {
		var foot = card.querySelector( '.hub61-card-foot' );
		if ( ! foot ) {
			return;
		}
		foot.setAttribute( 'data-state', state );
		var adminUrl = card.getAttribute( 'data-admin' );

		if ( state === 'active' ) {
			foot.innerHTML =
				'<span class="hub61-status hub61-status-active"><span aria-hidden="true">\u25CF</span> Ativo</span>' +
				'<a class="hub61-btn hub61-btn-ghost hub61-open" href="' + adminUrl + '">Abrir</a>';
			return;
		}
		if ( state === 'inactive' ) {
			foot.innerHTML =
				'<button type="button" class="hub61-btn hub61-btn-primary hub61-do" data-act="activate">Ativar</button>';
			return;
		}
		foot.innerHTML =
			'<button type="button" class="hub61-btn hub61-btn-primary hub61-do" data-act="install">Instalar</button>';
	}

	function handleAction( btn ) {
		var card = btn.closest( '.hub61-card' );
		var foot = card.querySelector( '.hub61-card-foot' );
		var slug = card.getAttribute( 'data-slug' );
		var act = btn.getAttribute( 'data-act' );
		var action = act === 'activate' ? 'hub61_activate' : 'hub61_install';

		btn.classList.add( 'is-busy' );
		btn.disabled = true;
		btn.textContent = act === 'activate' ? ( i18n.activating || 'Ativando…' ) : ( i18n.installing || 'Instalando…' );

		post( action, { slug: slug } ).then( function ( res ) {
			if ( res && res.success ) {
				renderState( card, res.data.state || 'active' );
				if ( res.data.message ) {
					message( card.querySelector( '.hub61-card-foot' ), res.data.message, true );
				}
			} else {
				btn.classList.remove( 'is-busy' );
				btn.disabled = false;
				btn.textContent = act === 'activate' ? 'Ativar' : 'Instalar';
				message( foot, ( res && res.data && res.data.message ) || i18n.error || 'Erro.', false );
			}
		} ).catch( function () {
			btn.classList.remove( 'is-busy' );
			btn.disabled = false;
			btn.textContent = act === 'activate' ? 'Ativar' : 'Instalar';
			message( foot, i18n.error || 'Erro.', false );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.hub61-do' );
		if ( btn ) {
			e.preventDefault();
			handleAction( btn );
		}
	} );

	// Envio de ideia → monta um e-mail para a 61 Labs.
	var ideaForm = document.getElementById( 'hub61-idea-form' );
	if ( ideaForm ) {
		var ideaCfgEl = document.getElementById( 'hub61-idea-cfg' );
		var ideaCfg = {};
		try {
			ideaCfg = JSON.parse( ideaCfgEl.textContent );
		} catch ( err ) {
			ideaCfg = {};
		}
		ideaForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var text = ( document.getElementById( 'hub61-idea-text' ).value || '' ).trim();
			if ( ! text ) {
				return;
			}
			var body = text + '\n\n— Enviado de ' + ( ideaCfg.site || '' );
			var href = 'mailto:' + encodeURIComponent( ideaCfg.email || '' ) +
				'?subject=' + encodeURIComponent( ideaCfg.subject || 'Ideia para a 61 Labs' ) +
				'&body=' + encodeURIComponent( body );
			window.location.href = href;
		} );
	}
}() );
