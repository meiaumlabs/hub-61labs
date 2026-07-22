<?php
/**
 * Desinstalação do Hub 61 Labs.
 *
 * O Hub não cria opções persistentes além de transients de cache das Releases.
 * Aqui limpamos esses transients. Os plugins instalados PELO hub NÃO são
 * removidos — eles são plugins independentes e continuam funcionando.
 *
 * @package Hub61
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove os transients de cache de Releases (hub61_rel_*).
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_hub61\_rel\_%'
	    OR option_name LIKE '\_transient\_timeout\_hub61\_rel\_%'"
);
