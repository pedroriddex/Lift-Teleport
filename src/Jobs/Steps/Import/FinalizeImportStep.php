<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Import;

use LiftTeleport\Import\ReadOnlyMode;
use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Support\ArtifactGarbageCollector;

final class FinalizeImportStep extends AbstractStep
{
    public function key(): string
    {
        return 'import_finalize';
    }

    public function run(array $job): array
    {
        $payload = $this->payload($job);
        $jobId = $this->jobId($job);

        if (! empty($payload['readonly_enabled'])) {
            (new ReadOnlyMode())->disable($jobId);
            $payload['readonly_enabled'] = false;
        }

        if (! empty($payload['maintenance_enabled'])) {
            @unlink(ABSPATH . '.maintenance');
            $payload['maintenance_enabled'] = false;
        }

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        flush_rewrite_rules(false);

        $consistency = $this->ensurePostImportConsistency($jobId);

        $cleanup = (new ArtifactGarbageCollector($this->jobs))->cleanupTerminal($job, 'completed');
        if ((int) ($cleanup['bytes_reclaimed'] ?? 0) > 0) {
            $payload = ArtifactGarbageCollector::mergeCleanupPayload($payload, $cleanup, 'import_finalize');
            $this->jobs->addEvent($jobId, 'info', 'artifact_cleanup_completed', [
                'reason' => 'import_finalize',
                'bytes_reclaimed' => (int) ($cleanup['bytes_reclaimed'] ?? 0),
                'deleted_paths' => $cleanup['deleted_paths'] ?? [],
            ]);
        }

        $payload['readonly_lifecycle'][] = [
            'event' => 'disabled',
            'at' => gmdate(DATE_ATOM),
            'source' => 'import_finalize',
        ];
        $payload['integrity_stage'] = 'completed';

        return [
            'status' => 'done',
            'payload' => $payload,
            'progress' => 100,
            'message' => 'Import completed.',
            'result' => [
                'site_url' => site_url(),
                'home_url' => home_url(),
                'checksum_verified' => (bool) ($payload['checksum_verified'] ?? false),
                'integrity_stage' => (string) ($payload['integrity_stage'] ?? ''),
                'consistency' => $consistency,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ensurePostImportConsistency(int $jobId): array
    {
        $result = [
            'active_theme' => (string) get_option('stylesheet', ''),
            'theme_fallback_applied' => false,
            'missing_active_plugins' => [],
        ];

        $stylesheet = trim((string) get_option('stylesheet', ''));
        $themePath = $stylesheet !== '' ? WP_CONTENT_DIR . '/themes/' . $stylesheet : '';
        if ($stylesheet !== '' && ! is_dir($themePath)) {
            $fallback = $this->resolveSafeThemeFallback();
            if ($fallback !== '' && function_exists('switch_theme')) {
                switch_theme($fallback);
                $result['theme_fallback_applied'] = true;
                $result['active_theme'] = $fallback;
                $this->jobs->addEvent($jobId, 'warning', 'active_theme_missing_fallback_applied', [
                    'missing_theme' => $stylesheet,
                    'fallback_theme' => $fallback,
                ]);
            } else {
                $this->jobs->addEvent($jobId, 'error', 'active_theme_missing_no_fallback', [
                    'missing_theme' => $stylesheet,
                ]);
            }
        }

        $activePlugins = get_option('active_plugins', []);
        if (! is_array($activePlugins)) {
            $activePlugins = [];
        }

        $kept = [];
        $missing = [];
        foreach ($activePlugins as $pluginFile) {
            if (! is_string($pluginFile) || $pluginFile === '') {
                continue;
            }

            $path = WP_CONTENT_DIR . '/plugins/' . ltrim(str_replace('\\', '/', $pluginFile), '/');
            if (file_exists($path)) {
                $kept[] = $pluginFile;
                continue;
            }

            $missing[] = $pluginFile;
        }

        if ($missing !== []) {
            update_option('active_plugins', array_values($kept), false);
            $result['missing_active_plugins'] = $missing;
            $this->jobs->addEvent($jobId, 'warning', 'missing_active_plugins_deactivated', [
                'missing_count' => count($missing),
                'missing_plugins' => $missing,
            ]);
        }

        return $result;
    }

    private function resolveSafeThemeFallback(): string
    {
        $candidate = defined('WP_DEFAULT_THEME') ? trim((string) WP_DEFAULT_THEME) : '';
        if ($candidate !== '' && is_dir(WP_CONTENT_DIR . '/themes/' . $candidate)) {
            return $candidate;
        }

        $themesRoot = WP_CONTENT_DIR . '/themes';
        if (! is_dir($themesRoot)) {
            return '';
        }

        $entries = scandir($themesRoot);
        if ($entries === false) {
            return '';
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir($themesRoot . '/' . $entry)) {
                return $entry;
            }
        }

        return '';
    }
}
