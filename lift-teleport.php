<?php
/**
 * Plugin Name: Lift Teleport
 * Description: Exporta e importa sitios WordPress completos con archivos .lift desde una interfaz simple y moderna.
 * Version: 0.1.0
 * Author: Lift
 * Text Domain: lift-teleport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LIFT_TELEPORT_VERSION', '0.1.0' );
define( 'LIFT_TELEPORT_FILE', __FILE__ );
define( 'LIFT_TELEPORT_PATH', plugin_dir_path( __FILE__ ) );
define( 'LIFT_TELEPORT_URL', plugin_dir_url( __FILE__ ) );

require_once LIFT_TELEPORT_PATH . 'includes/class-lift-teleport-admin.php';

function lift_teleport_bootstrap() {
	new Lift_Teleport_Admin();
}

add_action( 'plugins_loaded', 'lift_teleport_bootstrap' );
