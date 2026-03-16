<?php

declare(strict_types=1);

namespace LiftTeleport;

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    private static function autoload(string $class): void
    {
        $prefix = __NAMESPACE__ . '\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = LIFT_TELEPORT_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

        if (file_exists($path)) {
            require_once $path;
        }
    }
}
