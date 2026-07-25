<?php
/**
 * Plugin Name:       Hub 61 Labs
 * Plugin URI:        https://61labs.com.br
 * Description:       Central de plugins da 61 Labs. Descubra e instale todas as ferramentas da 61 Labs em um só lugar, envie ideias e fale com o suporte — direto do painel do WordPress.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            61 Labs
 * Author URI:        https://61labs.com.br
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hub-61labs
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HUB61_VERSION', '1.2.0' );
define( 'HUB61_FILE', __FILE__ );
define( 'HUB61_DIR', plugin_dir_path( __FILE__ ) );
define( 'HUB61_URL', plugin_dir_url( __FILE__ ) );

/*
 * URL do repositório público no GitHub usado para atualização automática do Hub.
 * Ajuste OWNER/REPO se o repositório real for diferente antes do primeiro push.
 */
define( 'HUB61_GITHUB_URL', 'https://github.com/meiaumlabs/hub-61labs/' );

/*
 * ------------------------------------------------------------------
 * Atualização automática via GitHub (Plugin Update Checker).
 * Fluxo de release: publique uma Release com a tag "vX.Y.Z" e anexe o
 * ZIP do plugin como asset. O WordPress detecta e oferece a atualização.
 * ------------------------------------------------------------------
 */
require_once HUB61_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

$hub61_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	HUB61_GITHUB_URL,
	HUB61_FILE,
	'hub-61labs'
);
$hub61_update_checker->getVcsApi()->enableReleaseAssets();

require_once HUB61_DIR . 'includes/class-hub-catalog.php';
require_once HUB61_DIR . 'includes/class-hub-installer.php';
require_once HUB61_DIR . 'includes/class-hub-admin.php';

Hub61_Installer::init();
Hub61_Admin::init();
