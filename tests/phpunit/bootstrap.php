<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/lift-teleport-abspath/');
}

if (! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
}

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool
    {
        return is_dir($target) || mkdir($target, 0777, true);
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

if (! function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
    }
}

if (! function_exists('__')) {
    function __(string $text, ?string $domain = null): string
    {
        return $text;
    }
}

if (! function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($value, $flags, $depth);
    }
}

if (! function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $specialChars = true, bool $extraSpecialChars = false): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $value = '';
        for ($i = 0; $i < $length; $i++) {
            $value .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $value;
    }
}

if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';

        public function prepare(string $query, mixed ...$args): string
        {
            if ($args === []) {
                return $query;
            }

            $safe = array_map(static function (mixed $arg): string {
                if (is_int($arg) || is_float($arg)) {
                    return (string) $arg;
                }

                return "'" . str_replace("'", "\\'", (string) $arg) . "'";
            }, $args);

            return (string) @vsprintf(str_replace(['%d', '%s', '%f'], ['%s', '%s', '%s'], $query), $safe);
        }

        public function get_row(string $query, string|int $output = ARRAY_A): array|null
        {
            return null;
        }

        public function get_results(string $query, string|int $output = ARRAY_A): array
        {
            return [];
        }

        public function replace(string $table, array $data, ?array $format = null): int|false
        {
            return 1;
        }

        public function delete(string $table, array $where, ?array $whereFormat = null): int|false
        {
            return 1;
        }

        public function insert(string $table, array $data, ?array $format = null): int|false
        {
            return 1;
        }
    }
}

if (! function_exists('is_serialized')) {
    function is_serialized(string $data): bool
    {
        if ($data === 'N;') {
            return true;
        }

        $data = trim($data);
        if ($data === '') {
            return false;
        }

        if (! preg_match('/^[aOsbid]:/', $data)) {
            return false;
        }

        set_error_handler(static fn (): bool => true);
        $result = @unserialize($data);
        restore_error_handler();

        return $result !== false || $data === 'b:0;';
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            public string $code = '',
            public string $message = '',
            public array $data = []
        ) {
        }
    }
}

$GLOBALS['lift_test_options'] = $GLOBALS['lift_test_options'] ?? [];

if (! function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['lift_test_options'][$key] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $key, mixed $value, bool $autoload = true): bool
    {
        $GLOBALS['lift_test_options'][$key] = $value;
        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $key): bool
    {
        unset($GLOBALS['lift_test_options'][$key]);
        return true;
    }
}

if (! function_exists('lift_test_reset_options')) {
    function lift_test_reset_options(): void
    {
        $GLOBALS['lift_test_options'] = [];
    }
}

if (! function_exists('clean_user_cache')) {
    function clean_user_cache(int $userId): void
    {
    }
}

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once dirname(__DIR__, 2) . '/src/Autoloader.php';
    LiftTeleport\Autoloader::register();
}
