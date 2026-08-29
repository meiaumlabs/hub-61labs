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
		} else if ( state === 'inactive' ) {
			foot.innerHTML =
				'<button type="button" class="hub61-btn hub61-btn-primary hub61-do" data-act="activate">Ativar</button>';
		} else {
			foot.innerHTML =
				'<button type="button" class="hub61-btn hub61-btn-primary hub61-do" data-act="install">Instalar</button>';
		}
		// Reaplica o botão de atualização se ainda houver update pendente.
		if ( card.__update ) {
			applyUpdateUI( card, card.__update );
		}
	}

	// Preenche/atualiza a meta de versões e o botão de atualização de um card.
	function applyVersions( card, info ) {
		var latestEl = card.querySelector( '.hub61-ver-latest-num' );
		var instWrap = card.querySelector( '.hub61-ver-installed' );
		var instEl = card.querySelector( '.hub61-ver-installed-num' );

		if ( info.installed && instWrap && instEl ) {
			instEl.textContent = 'v' + info.installed;
			instWrap.hidden = false;
			card.setAttribute( 'data-installed', info.installed );
		}
		if ( latestEl ) {
			latestEl.textContent = info.latest ? ( 'v' + info.latest ) : '—';
		}
		card.__update = info.update ? info.latest : null;
		applyUpdateUI( card, card.__update );
	}

	// Mostra o badge "Atualização disponível" e injeta o botão Atualizar.
	function applyUpdateUI( card, latest ) {
		var badge = card.querySelector( '.hub61-ver-badge' );
		var foot = card.querySelector( '.hub61-card-foot' );
		if ( latest ) {
			if ( badge ) {
				badge.textContent = ( i18n.updateAvail || 'Atualização disponível' ) + ' \u2192 v' + latest;
				badge.hidden = false;
				badge.classList.add( 'is-update' );
			}
			if ( foot && ! foot.querySelector( '.hub61-update' ) ) {
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'hub61-btn hub61-btn-signal hub61-update';
				btn.setAttribute( 'data-act', 'update' );
				btn.textContent = i18n.update || 'Atualizar';
				foot.appendChild( btn );
			}
		} else if ( badge ) {
			badge.hidden = true;
			badge.classList.remove( 'is-update' );
		}
	}

	// Consulta as versões (instalada + última) de todos os cards de uma vez.
	function loadVersions() {
		post( 'hub61_versions', {} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				return;
			}
			Object.keys( res.data ).forEach( function ( slug ) {
				var card = document.querySelector( '.hub61-card[data-slug="' + slug + '"]' );
				if ( card ) {
					applyVersions( card, res.data[ slug ] );
				}
			} );
		} ).catch( function () {} );
	}

	// Re-renderiza o rodapé de um card de Extra Plugin (mantém o seletor de versão intacto).
	function renderExtraState( card, state ) {
		var foot = card.querySelector( '.hub61-card-foot' );
		if ( ! foot ) {
			return;
		}
		foot.setAttribute( 'data-state', state );
		var adminUrl = card.getAttribute( 'data-admin' );
		var updateBtn = '<button type="button" class="hub61-btn hub61-btn-ghost hub61-do" data-act="extra-update">Instalar versão</button>';
		if ( state === 'active' ) {
			foot.innerHTML =
				'<span class="hub61-status hub61-status-active"><span aria-hidden="true">●</span> Ativo</span>' +
				'<button type="button" class="hub61-btn hub61-btn-primary hub61-do" data-act="extra-update">Instalar versão</button>' +
				( adminUrl ? '<a class="hub61-btn hub61-btn-ghost hub61-open" href="' + adminUrl + '">Abrir</a>' : '' );
		} else if ( state === 'inactive' ) {
			foot.innerHTML =
				'<button type="button" class="hub61-btn hub61-btn-primary hub61-do" data-act="extra-install">Ativar</button>' + updateBtn;
		} else {
			foot.innerHTML =
				'<button type="button" class="hub61-btn hub61-btn-primary hub61-do" data-act="extra-install">Instalar</button>';
		}
	}

	// Atualiza a meta (versão instalada + badge de atualização) de um card extra.
	function applyExtraMeta( card, installed ) {
		var latest = card.getAttribute( 'data-latest' ) || '';
		var instWrap = card.querySelector( '.hub61-ver-installed' );
		var instEl = card.querySelector( '.hub61-ver-installed-num' );
		if ( installed && instWrap && instEl ) {
			instEl.textContent = 'v' + installed;
			instWrap.hidden = false;
			card.setAttribute( 'data-installed', installed );
		}
		var badge = card.querySelector( '.hub61-ver-badge' );
		if ( badge ) {
			var hasUpdate = installed && latest && cmpVer( installed, latest ) < 0;
			badge.hidden = ! hasUpdate;
		}
	}

	// Compara duas versões (ex.: "3.8.10.1" vs "4.2.0"). Retorna -1, 0 ou 1.
	function cmpVer( a, b ) {
		var pa = String( a ).split( '.' );
		var pb = String( b ).split( '.' );
		var n = Math.max( pa.length, pb.length );
		for ( var i = 0; i < n; i++ ) {
			var na = parseInt( pa[ i ] || '0', 10 );
			var nb = parseInt( pb[ i ] || '0', 10 );
			if ( na > nb ) { return 1; }
			if ( na < nb ) { return -1; }
		}
		return 0;
	}

	function handleAction( btn ) {
		var card = btn.closest( '.hub61-card' );
		var foot = card.querySelector( '.hub61-card-foot' );
		var slug = card.getAttribute( 'data-slug' );
		var act = btn.getAttribute( 'data-act' );
		var isExtra = card.getAttribute( 'data-extra' ) === '1';
		var actionMap = {
			activate: 'hub61_activate', update: 'hub61_update', install: 'hub61_install',
			'extra-install': 'hub61_extra_install', 'extra-update': 'hub61_extra_update'
		};
		var busyMap = {
			activate: i18n.activating || 'Ativando…', update: i18n.updating || 'Atualizando…', install: i18n.installing || 'Instalando…',
			'extra-install': i18n.installing || 'Instalando…', 'extra-update': i18n.updating || 'Atualizando…'
		};
		var labelMap = {
			activate: 'Ativar', update: i18n.update || 'Atualizar', install: 'Instalar',
			'extra-install': 'Instalar', 'extra-update': 'Instalar versão'
		};

		var payload = { slug: slug };
		if ( isExtra ) {
			var sel = card.querySelector( '.hub61-ver-select' );
			payload.version = sel ? sel.value : '';
		}

		btn.classList.add( 'is-busy' );
		btn.disabled = true;
		btn.textContent = busyMap[ act ];

		post( actionMap[ act ] || 'hub61_install', payload ).then( function ( res ) {
			if ( res && res.success ) {
				if ( isExtra ) {
					applyExtraMeta( card, res.data.installed || card.getAttribute( 'data-installed' ) );
					renderExtraState( card, res.data.state || 'active' );
					if ( res.data.message ) {
						message( card.querySelector( '.hub61-card-foot' ), res.data.message, true );
					}
					return;
				}
				if ( act === 'update' ) {
					// Update concluído: limpa o pendente e atualiza a meta de versões.
					card.__update = null;
					var badge = card.querySelector( '.hub61-ver-badge' );
					if ( badge ) { badge.hidden = true; }
					applyVersions( card, {
						installed: res.data.installed || card.getAttribute( 'data-installed' ),
						latest: res.data.latest || '',
						update: false
					} );
				}
				renderState( card, res.data.state || 'active' );
				if ( res.data.message ) {
					message( card.querySelector( '.hub61-card-foot' ), res.data.message, true );
				}
			} else {
				btn.classList.remove( 'is-busy' );
				btn.disabled = false;
				btn.textContent = labelMap[ act ];
				message( foot, ( res && res.data && res.data.message ) || i18n.error || 'Erro.', false );
			}
		} ).catch( function () {
			btn.classList.remove( 'is-busy' );
			btn.disabled = false;
			btn.textContent = labelMap[ act ];
			message( foot, i18n.error || 'Erro.', false );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.hub61-do, .hub61-update' );
		if ( btn ) {
			e.preventDefault();
			handleAction( btn );
		}
	} );

	// Busca versões e disponibilidade de atualização assim que o painel carrega.
	loadVersions();

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
