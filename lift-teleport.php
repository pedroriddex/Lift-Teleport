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

require_once LIFT_TELEPORT_PATH . 'includes/class-lift-teleport-logger.php';
require_once LIFT_TELEPORT_PATH . 'includes/class-lift-teleport-migration-manager.php';
require_once LIFT_TELEPORT_PATH . 'includes/class-lift-teleport-admin.php';
require_once LIFT_TELEPORT_PATH . 'includes/class-lift-teleport-package-validator.php';
require_once LIFT_TELEPORT_PATH . 'includes/class-lift-teleport-exporter.php';
require_once LIFT_TELEPORT_PATH . 'includes/class-lift-teleport-importer.php';
require_once LIFT_TELEPORT_PATH . 'includes/class-lift-teleport-security.php';

function lift_teleport_bootstrap() {
	$validator = new Lift_Teleport_Package_Validator();

	new Lift_Teleport_Admin();
	new Lift_Teleport_Exporter( $validator );
	new Lift_Teleport_Importer( $validator );
	$security = new Lift_Teleport_Security();
	$security->register_hooks();
	$logger            = new Lift_Teleport_Logger();
	$migration_manager = new Lift_Teleport_Migration_Manager( $logger );
	new Lift_Teleport_Admin( $migration_manager );
}

add_action( 'plugins_loaded', 'lift_teleport_bootstrap' );
