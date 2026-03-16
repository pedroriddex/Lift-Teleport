<?php

declare(strict_types=1);

namespace LiftTeleport\Support;

use LiftTeleport\Jobs\JobRepository;

final class Environment
{
    /**
     * @return array<string,mixed>
     */
    public static function runtimeFingerprint(): array
    {
        $pluginDir = defined('LIFT_TELEPORT_DIR') ? (string) LIFT_TELEPORT_DIR : '';
        $pluginRealpath = $pluginDir !== '' ? (string) (realpath($pluginDir) ?: '') : '';
        $canonical = (string) apply_filters(
            'lift_teleport_canonical_plugin_dir',
            '/Users/pedroreyes/Studio/bracar/wp-content/plugins/lift-teleport'
        );
        $canonicalRealpath = $canonical !== '' ? (string) (realpath($canonical) ?: '') : '';

        return [
            'plugin_version' => defined('LIFT_TELEPORT_VERSION') ? (string) LIFT_TELEPORT_VERSION : 'unknown',
            'build_hash' => self::buildHash(),
            'plugin_realpath' => $pluginRealpath,
            'canonical_plugin_realpath' => $canonicalRealpath,
            'runtime_matches_canonical' => $pluginRealpath !== '' && $canonicalRealpath !== '' && $pluginRealpath === $canonicalRealpath,
            'php_version' => PHP_VERSION,
            'wp_version' => self::wpVersion(),
            'captured_at' => gmdate(DATE_ATOM),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function diagnostics(?JobRepository $jobs = null, bool $refreshCapabilities = false): array
    {
        $fingerprint = self::runtimeFingerprint();
        $pluginDir = defined('LIFT_TELEPORT_DIR') ? (string) LIFT_TELEPORT_DIR : '';
        $canonical = (string) apply_filters(
            'lift_teleport_canonical_plugin_dir',
            '/Users/pedroreyes/Studio/bracar/wp-content/plugins/lift-teleport'
        );
        $storage = (new ArtifactGarbageCollector($jobs))->storageSummary();
        $capabilities = (new CapabilityPreflight())->snapshot($refreshCapabilities);
        $recommendedExecution = is_array($capabilities['decisions'] ?? null)
            ? $capabilities['decisions']
            : [];

        return [
            'plugin_version' => (string) ($fingerprint['plugin_version'] ?? 'unknown'),
            'plugin_file' => defined('LIFT_TELEPORT_FILE') ? (string) LIFT_TELEPORT_FILE : '',
            'plugin_dir' => $pluginDir,
            'plugin_realpath' => (string) ($fingerprint['plugin_realpath'] ?? ''),
            'canonical_plugin_dir' => $canonical,
            'canonical_plugin_realpath' => (string) ($fingerprint['canonical_plugin_realpath'] ?? ''),
            'runtime_matches_canonical' => (bool) ($fingerprint['runtime_matches_canonical'] ?? false),
            'wp_content_dir' => defined('WP_CONTENT_DIR') ? (string) WP_CONTENT_DIR : '',
            'wp_content_realpath' => defined('WP_CONTENT_DIR') ? (string) (realpath((string) WP_CONTENT_DIR) ?: '') : '',
            'build_hash' => (string) ($fingerprint['build_hash'] ?? ''),
            'php_version' => (string) ($fingerprint['php_version'] ?? PHP_VERSION),
            'wp_version' => (string) ($fingerprint['wp_version'] ?? self::wpVersion()),
            'runtime_fingerprint' => $fingerprint,
            'lift_data_bytes' => (int) ($storage['lift_data_bytes'] ?? 0),
            'lift_jobs_bytes' => (int) ($storage['lift_jobs_bytes'] ?? 0),
            'reclaimable_bytes' => (int) ($storage['reclaimable_bytes'] ?? 0),
            'largest_job_bytes' => (int) ($storage['largest_job_bytes'] ?? 0),
            'oldest_retained_job' => $storage['oldest_retained_job'] ?? null,
            'storage' => $storage,
            'capabilities' => $capabilities,
            'recommended_execution' => $recommendedExecution,
            'preflight_checked_at' => (string) ($capabilities['checked_at'] ?? ''),
        ];
    }

    public static function buildHash(): string
    {
        if (! defined('LIFT_TELEPORT_DIR')) {
            return '';
        }

        $file = rtrim((string) LIFT_TELEPORT_DIR, '/') . '/build/index.js';
        if (! file_exists($file)) {
            return '';
        }

        $hash = @hash_file('sha256', $file);
        return is_string($hash) ? $hash : '';
    }

    private static function wpVersion(): string
    {
        global $wp_version;
        return isset($wp_version) ? (string) $wp_version : '';
    }
}
