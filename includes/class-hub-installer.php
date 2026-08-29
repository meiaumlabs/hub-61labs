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
		add_action( 'wp_ajax_hub61_versions', array( __CLASS__, 'ajax_versions' ) );
		add_action( 'wp_ajax_hub61_update', array( __CLASS__, 'ajax_update' ) );
		// Extra Plugins (repo curado meiaumlabs/extra-plugins, com seletor de versão).
		add_action( 'wp_ajax_hub61_extra_install', array( __CLASS__, 'ajax_extra_install' ) );
		add_action( 'wp_ajax_hub61_extra_update', array( __CLASS__, 'ajax_extra_update' ) );
	}

	/**
	 * Carrega os arquivos do core necessários para o Plugin_Upgrader.
	 */
	private static function ensure_upgrader() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once HUB61_DIR . 'includes/class-hub-silent-skin.php';
	}

	/**
	 * Pasta raiz de um plugin no disco (primeiro segmento do plugin_file).
	 *
	 * @param string $plugin_file basename (pasta/arquivo.php).
	 * @return string Caminho absoluto da pasta, ou '' se for plugin de arquivo único.
	 */
	private static function plugin_root_dir( $plugin_file ) {
		if ( false === strpos( $plugin_file, '/' ) ) {
			return '';
		}
		$slug_dir = strtok( $plugin_file, '/' );
		return WP_PLUGIN_DIR . '/' . $slug_dir;
	}

	/**
	 * Baixa o pacote para um arquivo temporário local.
	 *
	 * @param string $url URL do ZIP.
	 * @return string|WP_Error Caminho do arquivo local ou erro.
	 */
	private static function download_package( $url ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return download_url( $url, 60 );
	}

	/**
	 * Lê os cabeçalhos do arquivo principal do plugin de dentro do ZIP (sem extrair).
	 *
	 * @param string $zip_file    Arquivo ZIP local.
	 * @param string $plugin_file Caminho interno pasta/arquivo.php.
	 * @return array<string,string> Cabeçalhos relevantes (podem vir vazios).
	 */
	private static function read_zip_plugin_headers( $zip_file, $plugin_file ) {
		$out = array( 'RequiresWP' => '', 'RequiresPHP' => '', 'RequiresPlugins' => '', 'Version' => '', 'Name' => '' );
		if ( ! class_exists( 'ZipArchive' ) || '' === $plugin_file ) {
			return $out;
		}
		$za = new ZipArchive();
		if ( true !== $za->open( $zip_file ) ) {
			return $out;
		}
		$content = $za->getFromName( $plugin_file );
		$za->close();
		if ( false === $content ) {
			return $out;
		}
		// Cabeçalhos só ficam no topo do arquivo.
		$content = substr( $content, 0, 8192 );
		$get = function ( $key ) use ( $content ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $key, '/' ) . '\s*:\s*(.+)$/mi', $content, $m ) ) {
				return trim( $m[1] );
			}
			return '';
		};
		$out['RequiresWP']      = $get( 'Requires at least' );
		$out['RequiresPHP']     = $get( 'Requires PHP' );
		$out['RequiresPlugins'] = $get( 'Requires Plugins' );
		$out['Version']         = $get( 'Version' );
		$out['Name']            = $get( 'Plugin Name' );
		return $out;
	}

	/**
	 * Pré-checagem de compatibilidade a partir dos cabeçalhos do ZIP.
	 * Retorna uma lista de motivos de bloqueio (vazia = ok).
	 *
	 * @param array<string,string> $h Cabeçalhos lidos do ZIP.
	 * @return array<int,string>
	 */
	private static function preflight( $h ) {
		$errs = array();
		if ( ! empty( $h['RequiresPHP'] ) && version_compare( PHP_VERSION, $h['RequiresPHP'], '<' ) ) {
			$errs[] = sprintf(
				/* translators: 1: versão exigida, 2: versão atual */
				__( 'Requer PHP %1$s (este site tem %2$s).', 'hub-61labs' ),
				$h['RequiresPHP'],
				PHP_VERSION
			);
		}
		if ( ! empty( $h['RequiresWP'] ) ) {
			$wp = get_bloginfo( 'version' );
			if ( version_compare( $wp, $h['RequiresWP'], '<' ) ) {
				$errs[] = sprintf(
					/* translators: 1: versão exigida, 2: versão atual */
					__( 'Requer WordPress %1$s (este site tem %2$s).', 'hub-61labs' ),
					$h['RequiresWP'],
					$wp
				);
			}
		}
		return $errs;
	}

	/**
	 * Verifica (via loopback) se o site passou a responder com erro crítico.
	 * Conservador: em caso de dúvida (não conseguiu checar) retorna false — não
	 * dispara rollback à toa.
	 *
	 * @return bool true se detectou erro crítico/fatal.
	 */
	private static function site_has_fatal() {
		$resp = wp_remote_get( add_query_arg( 'hub61_health', time(), home_url( '/' ) ), array(
			'timeout'     => 15,
			'sslverify'   => false,
			'redirection' => 1,
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		) );
		if ( is_wp_error( $resp ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code >= 500 ) {
			return true;
		}
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( '' !== $body && (
			false !== stripos( $body, 'There has been a critical error' )
			|| false !== stripos( $body, 'erro crítico' )
		) ) {
			return true;
		}
		return false;
	}

	/**
	 * Instala um plugin a partir de um ZIP (URL), com pré-checagem. Não ativa.
	 *
	 * @param string $zip         URL do ZIP.
	 * @param string $plugin_file Caminho interno pasta/arquivo.php (para pré-checagem).
	 * @return true|WP_Error
	 */
	private static function install_from_zip( $zip, $plugin_file = '' ) {
		self::ensure_upgrader();

		$local = self::download_package( $zip );
		if ( is_wp_error( $local ) ) {
			return $local;
		}

		$headers = self::read_zip_plugin_headers( $local, $plugin_file );
		$block   = self::preflight( $headers );
		if ( ! empty( $block ) ) {
			@unlink( $local ); // phpcs:ignore
			return new WP_Error( 'hub61_preflight', implode( ' ', $block ) );
		}

		$upgrader  = new Plugin_Upgrader( new Hub61_Silent_Skin() );
		$installed = $upgrader->install( $local ); // arquivo local: o upgrader remove o temp ao final.
		if ( is_wp_error( $installed ) ) {
			return $installed;
		}
		if ( ! $installed ) {
			$errors = $upgrader->skin->get_errors();
			return new WP_Error( 'hub61_install', is_wp_error( $errors ) && $errors->has_errors()
				? $errors->get_error_message()
				: __( 'Falha ao instalar o plugin.', 'hub-61labs' ) );
		}
		return true;
	}

	/**
	 * Atualiza (sobrescreve) um plugin instalado a partir de um ZIP (URL), com rede de
	 * segurança: pré-checagem, backup da versão atual, verificação de saúde do site e
	 * rollback automático se a nova versão quebrar o site (erro crítico).
	 *
	 * @param string $zip         URL do ZIP.
	 * @param string $plugin_file basename (pasta/arquivo.php) do plugin.
	 * @return bool|WP_Error true/false = estava ativo antes; WP_Error em falha/rollback.
	 */
	private static function update_from_zip( $zip, $plugin_file ) {
		self::ensure_upgrader();
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			WP_Filesystem();
		}

		$was_active = is_plugin_active( $plugin_file );

		// Download + pré-checagem antes de tocar no disco.
		$local = self::download_package( $zip );
		if ( is_wp_error( $local ) ) {
			return $local;
		}
		$headers = self::read_zip_plugin_headers( $local, $plugin_file );
		$block   = self::preflight( $headers );
		if ( ! empty( $block ) ) {
			@unlink( $local ); // phpcs:ignore
			return new WP_Error( 'hub61_preflight', implode( ' ', $block ) );
		}

		// Backup da pasta atual (para rollback).
		$dir     = self::plugin_root_dir( $plugin_file );
		$backup  = '';
		$can_fs  = ! empty( $wp_filesystem );
		if ( $can_fs && '' !== $dir && $wp_filesystem->is_dir( $dir ) ) {
			$upgrade_dir = WP_CONTENT_DIR . '/upgrade';
			if ( ! $wp_filesystem->is_dir( $upgrade_dir ) ) {
				$wp_filesystem->mkdir( $upgrade_dir, FS_CHMOD_DIR );
			}
			$backup = $upgrade_dir . '/hub61-bak-' . md5( $plugin_file ) . '-' . time();
			if ( $wp_filesystem->mkdir( $backup, FS_CHMOD_DIR ) ) {
				$copied = copy_dir( $dir, $backup );
				if ( is_wp_error( $copied ) ) {
					$wp_filesystem->delete( $backup, true );
					$backup = '';
				}
			} else {
				$backup = '';
			}
		}

		$upgrader = new Plugin_Upgrader( new Hub61_Silent_Skin() );
		$result   = $upgrader->run( array(
			'package'                     => $local,
			'destination'                 => WP_PLUGIN_DIR,
			'clear_destination'           => true,
			'clear_working'               => true,
			'abort_if_destination_exists' => false,
			'hook_extra'                  => array(
				'type'   => 'plugin',
				'action' => 'update',
				'plugin' => $plugin_file,
			),
		) );

		$failed = is_wp_error( $result ) || false === $result || null === $result;

		if ( ! $failed && $was_active ) {
			activate_plugin( $plugin_file );
		}

		// Verificação de saúde só faz sentido se o update aparentou sucesso.
		$fatal = ( ! $failed ) ? self::site_has_fatal() : false;

		if ( $failed || $fatal ) {
			// Rollback: restaura a pasta do backup.
			if ( '' !== $backup && '' !== $dir ) {
				$wp_filesystem->delete( $dir, true );
				$wp_filesystem->mkdir( $dir, FS_CHMOD_DIR );
				copy_dir( $backup, $dir );
				if ( $was_active ) {
					activate_plugin( $plugin_file );
				}
			}
			if ( '' !== $backup ) {
				$wp_filesystem->delete( $backup, true );
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( $fatal ) {
				return new WP_Error( 'hub61_rollback', '' !== $backup
					? __( 'A versão escolhida gerou um erro crítico no site e foi revertida para a versão anterior. Provável incompatibilidade com outro plugin (ex.: Elementor Pro exige uma versão do Elementor mais nova do que a escolhida).', 'hub-61labs' )
					: __( 'A versão escolhida gerou um erro crítico no site. Não foi possível reverter automaticamente — reinstale a última versão pela lista.', 'hub-61labs' )
				);
			}
			$errors = $upgrader->skin->get_errors();
			return new WP_Error( 'hub61_update', is_wp_error( $errors ) && $errors->has_errors()
				? $errors->get_error_message()
				: __( 'Falha ao atualizar o plugin.', 'hub-61labs' ) );
		}

		// Sucesso: descarta o backup.
		if ( '' !== $backup ) {
			$wp_filesystem->delete( $backup, true );
		}
		return $was_active;
	}

	/**
	 * Sanitiza uma string de versão (dígitos, ponto e hífen).
	 *
	 * @param mixed $v Versão bruta do request.
	 * @return string
	 */
	private static function sanitize_version( $v ) {
		return preg_replace( '/[^0-9A-Za-z.\-]/', '', (string) wp_unslash( $v ) );
	}

	/**
	 * Resolve a URL do ZIP de um extra plugin para a versão pedida (ou a última).
	 *
	 * @param array<string,mixed> $item    Item do manifest.
	 * @param string              $version Versão pedida ('' = última).
	 * @return string URL do ZIP ou ''.
	 */
	private static function extra_zip( $item, $version ) {
		if ( '' !== $version ) {
			return Hub61_Extra::version_zip( $item, $version );
		}
		return isset( $item['versions'][0]['download'] ) ? (string) $item['versions'][0]['download'] : '';
	}

	/**
	 * Instala (e ativa) uma versão específica de um extra plugin.
	 */
	public static function ajax_extra_install() {
		self::guard();
		$slug    = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$version = isset( $_POST['version'] ) ? self::sanitize_version( $_POST['version'] ) : '';
		$item    = Hub61_Extra::get( $slug );
		if ( ! $item || empty( $item['plugin_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Plugin desconhecido.', 'hub-61labs' ) ) );
		}

		// Já instalado? Apenas ativa (para trocar de versão, use "Atualizar").
		if ( 'not-installed' !== self::state( $item ) ) {
			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$result = activate_plugin( $item['plugin_file'] );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			wp_send_json_success( array( 'state' => 'active', 'message' => __( 'Plugin ativado.', 'hub-61labs' ) ) );
		}

		$zip = self::extra_zip( $item, $version );
		if ( '' === $zip ) {
			wp_send_json_error( array( 'message' => __( 'Versão indisponível.', 'hub-61labs' ) ) );
		}

		$installed = self::install_from_zip( $zip, $item['plugin_file'] );
		if ( is_wp_error( $installed ) ) {
			wp_send_json_error( array( 'message' => $installed->get_error_message() ) );
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$activated = activate_plugin( $item['plugin_file'] );
		$state     = is_wp_error( $activated ) ? 'inactive' : 'active';

		// Rede de segurança: se ativar quebrou o site (erro crítico), desativa e remove.
		if ( 'active' === $state && self::site_has_fatal() ) {
			deactivate_plugins( $item['plugin_file'], true );
			if ( self::site_has_fatal() ) {
				// Ainda quebrado: remove o plugin recém-instalado para restaurar o site.
				if ( ! function_exists( 'delete_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				delete_plugins( array( $item['plugin_file'] ) );
			}
			wp_send_json_error( array( 'message' => __( 'A versão instalada gerou um erro crítico no site e foi revertida. Tente a última versão, ou verifique se há um plugin dependente (ex.: Elementor Pro exige uma versão mais nova do Elementor).', 'hub-61labs' ) ) );
		}

		wp_send_json_success( array(
			'state'     => $state,
			'installed' => self::installed_version( $item ),
			'message'   => 'active' === $state
				? __( 'Instalado e ativado com sucesso.', 'hub-61labs' )
				: __( 'Instalado. Ative manualmente na página de Plugins.', 'hub-61labs' ),
		) );
	}

	/**
	 * Atualiza um extra plugin instalado para a versão escolhida (pode ser downgrade).
	 */
	public static function ajax_extra_update() {
		self::guard();
		$slug    = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$version = isset( $_POST['version'] ) ? self::sanitize_version( $_POST['version'] ) : '';
		$item    = Hub61_Extra::get( $slug );
		if ( ! $item || empty( $item['plugin_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Plugin desconhecido.', 'hub-61labs' ) ) );
		}
		if ( 'not-installed' === self::state( $item ) ) {
			wp_send_json_error( array( 'message' => __( 'O plugin não está instalado.', 'hub-61labs' ) ) );
		}

		$zip = self::extra_zip( $item, $version );
		if ( '' === $zip ) {
			wp_send_json_error( array( 'message' => __( 'Versão indisponível.', 'hub-61labs' ) ) );
		}

		$res = self::update_from_zip( $zip, $item['plugin_file'] );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success( array(
			'state'     => $res ? 'active' : 'inactive',
			'installed' => self::installed_version( $item ),
			'message'   => __( 'Atualizado com sucesso.', 'hub-61labs' ),
		) );
	}

	/**
	 * Versão instalada de um item (lê o cabeçalho do plugin no disco).
	 *
	 * @param array<string,string> $item Item do catálogo.
	 * @return string Versão instalada ou '' se não instalado.
	 */
	public static function installed_version( $item ) {
		$path = WP_PLUGIN_DIR . '/' . $item['plugin_file'];
		if ( ! file_exists( $path ) ) {
			return '';
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( $path, false, false );
		return isset( $data['Version'] ) ? (string) $data['Version'] : '';
	}

	/**
	 * Normaliza uma tag/versão (remove um "v" inicial).
	 *
	 * @param string $v Versão bruta.
	 * @return string
	 */
	private static function norm( $v ) {
		return ltrim( trim( (string) $v ), 'vV' );
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

		$release = self::latest_release( $item['repo'] );
		if ( is_wp_error( $release ) ) {
			wp_send_json_error( array( 'message' => $release->get_error_message() ) );
		}
		$zip = $release['zip'];

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
	 * Resolve a última Release de um repositório público: versão + URL do ZIP.
	 *
	 * @param string $repo owner/repo.
	 * @return array{version:string,zip:string}|WP_Error
	 */
	private static function latest_release( $repo ) {
		$cache_key = self::CACHE_PREFIX . md5( $repo );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached['zip'] ) ) {
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

		$version = isset( $data['tag_name'] ) ? self::norm( $data['tag_name'] ) : '';
		$release = array( 'version' => $version, 'zip' => $zip );
		set_transient( $cache_key, $release, HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Retorna versão instalada/última + flag de atualização para todos os itens.
	 * Chamada assíncrona pelo painel (as consultas ao GitHub têm cache de 1h).
	 */
	public static function ajax_versions() {
		self::guard();
		$out = array();
		foreach ( Hub61_Catalog::all() as $item ) {
			$installed = self::installed_version( $item );
			$latest    = '';
			$error     = '';
			$release   = self::latest_release( $item['repo'] );
			if ( is_wp_error( $release ) ) {
				$error = $release->get_error_message();
			} else {
				$latest = $release['version'];
			}
			$update = ( '' !== $installed && '' !== $latest && version_compare( $installed, $latest, '<' ) );
			$out[ $item['slug'] ] = array(
				'installed' => $installed,
				'latest'    => $latest,
				'update'    => $update,
				'error'     => $error,
			);
		}
		wp_send_json_success( $out );
	}

	/**
	 * Atualiza um plugin instalado para a última Release (sobrescreve os arquivos).
	 */
	public static function ajax_update() {
		self::guard();
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$item = Hub61_Catalog::get( $slug );
		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Plugin desconhecido.', 'hub-61labs' ) ) );
		}
		if ( 'not-installed' === self::state( $item ) ) {
			wp_send_json_error( array( 'message' => __( 'O plugin não está instalado.', 'hub-61labs' ) ) );
		}

		$release = self::latest_release( $item['repo'] );
		if ( is_wp_error( $release ) ) {
			wp_send_json_error( array( 'message' => $release->get_error_message() ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once HUB61_DIR . 'includes/class-hub-silent-skin.php';

		$was_active = is_plugin_active( $item['plugin_file'] );

		$upgrader = new Plugin_Upgrader( new Hub61_Silent_Skin() );
		// Espelha o fluxo de update do core: destino = pasta de plugins, o WP calcula
		// a subpasta a partir do prefixo do ZIP e limpa só ela antes de reextrair.
		$result = $upgrader->run( array(
			'package'           => $release['zip'],
			'destination'       => WP_PLUGIN_DIR,
			'clear_destination' => true,
			'clear_working'     => true,
			'abort_if_destination_exists' => false,
			'hook_extra'        => array(
				'type'   => 'plugin',
				'action' => 'update',
				'plugin' => $item['plugin_file'],
			),
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		if ( false === $result || null === $result ) {
			$errors = $upgrader->skin->get_errors();
			$msg = is_wp_error( $errors ) && $errors->has_errors()
				? $errors->get_error_message()
				: __( 'Falha ao atualizar o plugin.', 'hub-61labs' );
			wp_send_json_error( array( 'message' => $msg ) );
		}

		// Garante que o plugin siga ativo após a reextração.
		if ( $was_active ) {
			activate_plugin( $item['plugin_file'] );
		}

		wp_send_json_success( array(
			'state'     => $was_active ? 'active' : 'inactive',
			'installed' => self::installed_version( $item ),
			'latest'    => $release['version'],
			'message'   => __( 'Atualizado com sucesso.', 'hub-61labs' ),
		) );
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
