<?php
/**
 * Hub61_Extra — catálogo dos "Extra Plugins" da 61 Labs.
 *
 * Diferente do catálogo principal (um repo por plugin, auto-descoberto), os Extra
 * Plugins vivem num ÚNICO repositório privado/curado (meiaumlabs/extra-plugins). O Hub
 * lê o `manifest.json` da raiz desse repo (1 requisição, cache de 6h) e monta a seção
 * "Extra Plugins", onde cada plugin traz VÁRIAS versões e o usuário escolhe qual
 * instalar/atualizar.
 *
 * Acesso restrito: quando o repo for privado, basta informar uma "Chave de acesso"
 * (token read-only) — a tubulação já está aqui (ver token()). Sem chave configurada, o
 * Hub tenta acesso público (modo de teste atual).
 *
 * @package Hub61
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hub61_Extra {

	/** Repositório único dos extra plugins (org meiaumlabs). */
	const REPO = 'meiaumlabs/extra-plugins';

	/** URL do manifest na raiz do repo. */
	const MANIFEST_URL = 'https://raw.githubusercontent.com/meiaumlabs/extra-plugins/HEAD/manifest.json';

	/** Chave do transient com a grade já resolvida. */
	const CACHE_KEY = 'hub61_extra_manifest';

	/** Nome da option que guarda a chave de acesso (token read-only). */
	const TOKEN_OPTION = 'hub61_extra_token';

	/**
	 * Chave de acesso (token) para o repo de extra plugins.
	 * Hoje o repo é público (teste) → normalmente vazio. Para restringir, torne o repo
	 * privado e grave o token na option `hub61_extra_token` (ou use o filtro).
	 *
	 * @return string
	 */
	public static function token() {
		$token = defined( 'HUB61_EXTRA_TOKEN' ) ? (string) HUB61_EXTRA_TOKEN : (string) get_option( self::TOKEN_OPTION, '' );
		/** Permite injetar/rotacionar o token programaticamente. */
		return (string) apply_filters( 'hub61_extra_token', $token );
	}

	/**
	 * Headers HTTP para as requisições ao GitHub (inclui auth se houver token).
	 *
	 * @param string $accept Valor do header Accept.
	 * @return array<string,string>
	 */
	private static function headers( $accept = 'application/json' ) {
		$headers = array(
			'Accept'     => $accept,
			'User-Agent' => 'Hub61Labs/' . HUB61_VERSION,
		);
		$token = self::token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return $headers;
	}

	/**
	 * Lista os extra plugins do manifest (com cache de 6h).
	 *
	 * @param bool $force Ignora o cache e rebusca.
	 * @return array<int,array<string,mixed>> Lista de plugins (pode ser vazia).
	 */
	public static function all( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get( self::MANIFEST_URL, array(
			'timeout' => 20,
			'headers' => self::headers(),
		) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Falha (repo privado sem chave, offline, etc.): cacheia vazio por 10min pra não martelar.
			set_transient( self::CACHE_KEY, array(), 10 * MINUTE_IN_SECONDS );
			return array();
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$plugins = ( is_array( $data ) && ! empty( $data['plugins'] ) && is_array( $data['plugins'] ) )
			? array_values( $data['plugins'] )
			: array();

		set_transient( self::CACHE_KEY, $plugins, 6 * HOUR_IN_SECONDS );
		return $plugins;
	}

	/**
	 * Busca um extra plugin pelo slug.
	 *
	 * @param string $slug Slug do plugin.
	 * @return array<string,mixed>|null
	 */
	public static function get( $slug ) {
		foreach ( self::all() as $item ) {
			if ( isset( $item['slug'] ) && $item['slug'] === $slug ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * URL de download (ZIP) de uma versão específica de um extra plugin.
	 *
	 * @param array<string,mixed> $item    Item do manifest.
	 * @param string              $version Versão desejada.
	 * @return string URL do ZIP ou '' se a versão não existir.
	 */
	public static function version_zip( $item, $version ) {
		if ( empty( $item['versions'] ) || ! is_array( $item['versions'] ) ) {
			return '';
		}
		foreach ( $item['versions'] as $v ) {
			if ( isset( $v['version'] ) && (string) $v['version'] === (string) $version && ! empty( $v['download'] ) ) {
				return (string) $v['download'];
			}
		}
		return '';
	}

	/**
	 * Limpa o cache do manifest (chamar após configurar/trocar a chave de acesso).
	 */
	public static function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}
}
