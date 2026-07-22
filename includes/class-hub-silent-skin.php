<?php
/**
 * Hub61_Silent_Skin — skin silenciosa para o Plugin_Upgrader durante o AJAX.
 *
 * Este arquivo só deve ser carregado DEPOIS de
 * wp-admin/includes/class-wp-upgrader.php (que carrega Automatic_Upgrader_Skin).
 *
 * @package Hub61
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Hub61_Silent_Skin' ) ) {
	class Hub61_Silent_Skin extends \Automatic_Upgrader_Skin {
		public function feedback( $feedback, ...$args ) {}
		public function header() {}
		public function footer() {}
	}
}
