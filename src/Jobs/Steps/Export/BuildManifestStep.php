<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Export;

use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Support\Filesystem;

final class BuildManifestStep extends AbstractStep
{
    public function key(): string
    {
        return 'export_build_manifest';
    }

    public function run(array $job): array
    {
        global $wp_version, $table_prefix;

        $payload = $this->payload($job);

        $wpContentSize = Filesystem::directorySize(WP_CONTENT_DIR);
        $dbDumpFile = (string) ($payload['db_dump_file'] ?? '');
        $dbSize = $dbDumpFile !== '' && file_exists($dbDumpFile) ? filesize($dbDumpFile) : 0;

        $manifest = [
            'format' => 'lift-v2',
            'format_revision' => '2.0',
            'site' => [
                'site_url' => site_url(),
                'home_url' => home_url(),
                'abspath' => ABSPATH,
                'wp_content_dir' => WP_CONTENT_DIR,
                'db_prefix' => $table_prefix,
                'wp_version' => (string) $wp_version,
                'php_version' => PHP_VERSION,
            ],
            'payload' => [
                'db_dump' => 'db/dump.sql',
                'wp_content' => [
                    'root' => 'content/wp-content',
                    'plugins' => 'content/wp-content/plugins',
                    'themes' => 'content/wp-content/themes',
                    'uploads' => 'content/wp-content/uploads',
                    'mu_plugins' => 'content/wp-content/mu-plugins',
                ],
            ],
            'sizes' => [
                'db_dump_bytes' => (int) $dbSize,
                'wp_content_bytes' => (int) $wpContentSize,
                'estimated_total_bytes' => (int) ($dbSize + $wpContentSize),
            ],
            'encryption' => [
                'enabled' => ! empty($payload['password']),
            ],
            'generator' => [
                'plugin' => 'lift-teleport',
                'version' => defined('LIFT_TELEPORT_VERSION') ? (string) LIFT_TELEPORT_VERSION : '',
            ],
        ];

        $payload['manifest'] = $manifest;

        return [
            'status' => 'next',
            'next_step' => 'export_package',
            'payload' => $payload,
            'progress' => 60,
            'message' => 'Manifest generated.',
        ];
    }
}
