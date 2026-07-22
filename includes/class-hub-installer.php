<?php
/**
 * Hub61_Installer — instala/ativa plugins da 61 Labs a partir das Releases do GitHub.
 *
 * O Hub NÃO empacota os outros plugins: ele consulta a API pública de Releases do
 * GitHub (org meiaumlabs), pega o ZIP anexado à última Release e o instala usando o
 * próprio mecanismo do WordPress (Plugin_Upgrader). Depois de instalado, cada plugin
 * mantém sua própria atualização automática (PUC).
 *
 * @package Hub61
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hub61_Installer {

	const CACHE_PREFIX = 'hub61_rel_';

	public static function init() {
		add_action( 'wp_ajax_hub61_install', array( __CLASS__, 'ajax_install' ) );
		add_action( 'wp_ajax_hub61_activate', array( __CLASS__, 'ajax_activate' ) );
		add_action( 'wp_ajax_hub61_status', array( __CLASS__, 'ajax_status' ) );
	}

	/**
	 * Estado de um item do catálogo: not-installed | inactive | active.
	 *
	 * @param array<string,string> $item Item do catálogo.
	 * @return string
	 */
	public static function state( $item ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = $item['plugin_file'];
		$installed = file_exists( WP_PLUGIN_DIR . '/' . $file );
		if ( ! $installed ) {
			return 'not-installed';
		}
		return is_plugin_active( $file ) ? 'active' : 'inactive';
	}

	/**
	 * Devolve o estado de todos os itens (para atualizar a grade após uma ação).
	 */
	public static function ajax_status() {
		self::guard();
		$out = array();
		foreach ( Hub61_Catalog::all() as $item ) {
			$out[ $item['slug'] ] = self::state( $item );
		}
		wp_send_json_success( $out );
	}

	/**
	 * Ativa um plugin já instalado.
	 */
	public static function ajax_activate() {
		self::guard();
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$item = Hub61_Catalog::get( $slug );
		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Plugin desconhecido.', 'hub-61labs' ) ) );
		}
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$result = activate_plugin( $item['plugin_file'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array(
			'state'   => 'active',
			'message' => __( 'Plugin ativado.', 'hub-61labs' ),
		) );
	}

	/**
	 * Instala (e ativa) um plugin a partir da última Release do GitHub.
	 */
	public static function ajax_install() {
		self::guard();
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$item = Hub61_Catalog::get( $slug );
		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Plugin desconhecido.', 'hub-61labs' ) ) );
		}

		// Já instalado? Apenas ativa.
		if ( 'not-installed' !== self::state( $item ) ) {
			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$result = activate_plugin( $item['plugin_file'] );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			wp_send_json_success( array(
				'state'   => 'active',
				'message' => __( 'Plugin ativado.', 'hub-61labs' ),
			) );
		}

		$zip = self::latest_release_zip( $item['repo'] );
		if ( is_wp_error( $zip ) ) {
			wp_send_json_error( array( 'message' => $zip->get_error_message() ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		// Automatic_Upgrader_Skin já foi carregada por class-wp-upgrader.php.
		require_once HUB61_DIR . 'includes/class-hub-silent-skin.php';

		$upgrader = new Plugin_Upgrader( new Hub61_Silent_Skin() );
		$installed = $upgrader->install( $zip );

		if ( is_wp_error( $installed ) ) {
			wp_send_json_error( array( 'message' => $installed->get_error_message() ) );
		}
		if ( ! $installed ) {
			$errors = $upgrader->skin->get_errors();
			$msg = is_wp_error( $errors ) && $errors->has_errors()
				? $errors->get_error_message()
				: __( 'Falha ao instalar o plugin.', 'hub-61labs' );
			wp_send_json_error( array( 'message' => $msg ) );
		}

		// Ativa após instalar.
		$activated = activate_plugin( $item['plugin_file'] );
		if ( is_wp_error( $activated ) ) {
			wp_send_json_success( array(
				'state'   => 'inactive',
				'message' => __( 'Instalado. Ative manualmente na página de Plugins.', 'hub-61labs' ),
			) );
		}

		wp_send_json_success( array(
			'state'   => 'active',
			'message' => __( 'Instalado e ativado com sucesso.', 'hub-61labs' ),
		) );
	}

	/**
	 * Resolve a URL do ZIP da última Release de um repositório público.
	 *
	 * @param string $repo owner/repo.
	 * @return string|WP_Error URL do ZIP ou erro.
	 */
	private static function latest_release_zip( $repo ) {
		$cache_key = self::CACHE_PREFIX . md5( $repo );
		$cached = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$api = 'https://api.github.com/repos/' . $repo . '/releases/latest';
		$response = wp_remote_get( $api, array(
			'timeout' => 20,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'Hub61Labs/' . HUB61_VERSION,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'hub61_release', sprintf(
				/* translators: %1$s repo, %2$d HTTP code */
				__( 'Não foi possível consultar as Releases de %1$s (HTTP %2$d).', 'hub-61labs' ),
				$repo,
				(int) $code
			) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
			return new WP_Error( 'hub61_asset', __( 'A última Release não tem um arquivo ZIP anexado.', 'hub-61labs' ) );
		}

		$zip = '';
		foreach ( $data['assets'] as $asset ) {
			if ( isset( $asset['browser_download_url'], $asset['name'] )
				&& '.zip' === strtolower( substr( $asset['name'], -4 ) ) ) {
				$zip = $asset['browser_download_url'];
				break;
			}
		}

		if ( '' === $zip ) {
			return new WP_Error( 'hub61_asset', __( 'A última Release não tem um arquivo ZIP anexado.', 'hub-61labs' ) );
		}

		set_transient( $cache_key, $zip, HOUR_IN_SECONDS );
		return $zip;
	}

	/**
	 * Verificação de permissão + nonce comum a todas as ações AJAX.
	 */
	private static function guard() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Você não tem permissão para instalar plugins.', 'hub-61labs' ) ), 403 );
		}
		check_ajax_referer( 'hub61_admin', 'nonce' );
	}
}
