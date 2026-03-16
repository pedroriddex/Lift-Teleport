<?php
/**
 * Plugin Name: Lift Teleport
 * Plugin URI: https://lift.example
 * Description: Export and import full WordPress websites in one click using the .lift format.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Lift
 * License: GPL-2.0-or-later
 * Text Domain: lift-teleport
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('LIFT_TELEPORT_VERSION', '0.1.0');
define('LIFT_TELEPORT_FILE', __FILE__);
define('LIFT_TELEPORT_DIR', plugin_dir_path(__FILE__));
define('LIFT_TELEPORT_URL', plugin_dir_url(__FILE__));
define('LIFT_TELEPORT_BASENAME', plugin_basename(__FILE__));

$autoload = LIFT_TELEPORT_DIR . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once LIFT_TELEPORT_DIR . 'src/Autoloader.php';
    LiftTeleport\Autoloader::register();
}

register_activation_hook(LIFT_TELEPORT_FILE, ['LiftTeleport\\Plugin', 'activate']);
register_deactivation_hook(LIFT_TELEPORT_FILE, ['LiftTeleport\\Plugin', 'deactivate']);

LiftTeleport\Plugin::boot();
