<?php
/**
 * Hub61_Evolution — cliente da Evolution API (WhatsApp) para envio de relatórios.
 *
 * Configuração (global, nível de site) guardada em options:
 *   - hub61_evo_url      Base URL da instância Evolution (ex.: https://evo.seudominio.com)
 *   - hub61_evo_key      API key (apikey)
 *   - hub61_evo_instance Nome da instância
 *
 * Envia texto e documento (PDF em base64) via endpoints sendText / sendMedia (Evolution v2).
 *
 * @package Hub61
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hub61_Evolution {

	/** Número do dono (sempre recebe cópia do relatório). */
	const OWNER_NUMBER = '5561992847170';

	const OPT_URL      = 'hub61_evo_url';
	const OPT_KEY      = 'hub61_evo_key';
	const OPT_INSTANCE = 'hub61_evo_instance';

	/**
	 * Configuração atual.
	 *
	 * @return array{url:string,key:string,instance:string}
	 */
	public static function config() {
		return array(
			'url'      => untrailingslashit( (string) get_option( self::OPT_URL, '' ) ),
			'key'      => (string) get_option( self::OPT_KEY, '' ),
			'instance' => (string) get_option( self::OPT_INSTANCE, '' ),
		);
	}

	/**
	 * A conexão está configurada (url + key + instance preenchidos)?
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$c = self::config();
		return '' !== $c['url'] && '' !== $c['key'] && '' !== $c['instance'];
	}

	/**
	 * Normaliza um número para o formato da Evolution (apenas dígitos, com DDI).
	 * Números brasileiros sem DDI recebem 55 na frente.
	 *
	 * @param string $number Número bruto.
	 * @return string Apenas dígitos, ou '' se inválido.
	 */
	public static function normalize_number( $number ) {
		$digits = preg_replace( '/\D+/', '', (string) $number );
		if ( '' === $digits ) {
			return '';
		}
		// Heurística BR: 10–11 dígitos (DDD + número) sem DDI → prefixa 55.
		if ( strlen( $digits ) <= 11 ) {
			$digits = '55' . $digits;
		}
		return $digits;
	}

	/**
	 * Requisição base à Evolution API.
	 *
	 * @param string $path Caminho relativo (ex.: /message/sendText/INSTANCE).
	 * @param array  $body Corpo JSON.
	 * @return array|WP_Error Resposta decodificada ou erro.
	 */
	private static function request( $path, $body ) {
		$c = self::config();
		if ( ! self::is_configured() ) {
			return new WP_Error( 'hub61_evo_cfg', __( 'A conexão com a Evolution API não está configurada.', 'hub-61labs' ) );
		}
		$resp = wp_remote_post( $c['url'] . $path, array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
				'apikey'       => $c['key'],
			),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['message'] )
				? ( is_array( $data['message'] ) ? implode( '; ', $data['message'] ) : $data['message'] )
				: sprintf( __( 'Evolution API respondeu HTTP %d.', 'hub-61labs' ), $code );
			return new WP_Error( 'hub61_evo_http', $msg );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Estado da conexão da instância (para o botão "Testar conexão").
	 *
	 * @return array|WP_Error
	 */
	public static function test_connection() {
		$c = self::config();
		if ( ! self::is_configured() ) {
			return new WP_Error( 'hub61_evo_cfg', __( 'Preencha URL, API key e instância antes de testar.', 'hub-61labs' ) );
		}
		$resp = wp_remote_get( $c['url'] . '/instance/connectionState/' . rawurlencode( $c['instance'] ), array(
			'timeout' => 20,
			'headers' => array( 'apikey' => $c['key'] ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['message'] )
				? ( is_array( $data['message'] ) ? implode( '; ', $data['message'] ) : $data['message'] )
				: sprintf( __( 'Evolution API respondeu HTTP %d.', 'hub-61labs' ), $code );
			return new WP_Error( 'hub61_evo_http', $msg );
		}
		// Evolution v2: { instance: { instanceName, state: 'open'|'connecting'|'close' } }
		$state = '';
		if ( isset( $data['instance']['state'] ) ) {
			$state = (string) $data['instance']['state'];
		} elseif ( isset( $data['state'] ) ) {
			$state = (string) $data['state'];
		}
		return array( 'state' => $state, 'raw' => $data );
	}

	/**
	 * Envia uma mensagem de texto.
	 *
	 * @param string $number Número (será normalizado).
	 * @param string $text   Texto.
	 * @return true|WP_Error
	 */
	public static function send_text( $number, $text ) {
		$c   = self::config();
		$num = self::normalize_number( $number );
		if ( '' === $num ) {
			return new WP_Error( 'hub61_evo_num', __( 'Número de WhatsApp inválido.', 'hub-61labs' ) );
		}
		$r = self::request( '/message/sendText/' . rawurlencode( $c['instance'] ), array(
			'number' => $num,
			'text'   => $text,
		) );
		return is_wp_error( $r ) ? $r : true;
	}

	/**
	 * Envia um documento (PDF) como base64.
	 *
	 * @param string $number   Número (será normalizado).
	 * @param string $binary   Conteúdo binário do arquivo.
	 * @param string $filename Nome do arquivo (ex.: relatorio.pdf).
	 * @param string $caption  Legenda opcional.
	 * @return true|WP_Error
	 */
	public static function send_document( $number, $binary, $filename, $caption = '' ) {
		$c   = self::config();
		$num = self::normalize_number( $number );
		if ( '' === $num ) {
			return new WP_Error( 'hub61_evo_num', __( 'Número de WhatsApp inválido.', 'hub-61labs' ) );
		}
		$r = self::request( '/message/sendMedia/' . rawurlencode( $c['instance'] ), array(
			'number'    => $num,
			'mediatype' => 'document',
			'mimetype'  => 'application/pdf',
			'media'     => base64_encode( $binary ),
			'fileName'  => $filename,
			'caption'   => $caption,
		) );
		return is_wp_error( $r ) ? $r : true;
	}
}
