<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Import;

use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Support\ArtifactGarbageCollector;
use LiftTeleport\Support\Filesystem;
use RuntimeException;

final class SyncContentStep extends AbstractStep
{
    public function key(): string
    {
        return 'import_sync_content';
    }

    public function run(array $job): array
    {
        $payload = $this->payload($job);
        $root = (string) ($payload['import_extracted_root'] ?? '');
        if ($root === '' || ! is_dir($root)) {
            throw new RuntimeException('Extracted package folder is missing.');
        }

        $sourceRoot = $root . '/content/wp-content';
        if (! is_dir($sourceRoot)) {
            throw new RuntimeException('Package wp-content payload is missing.');
        }

        $sourceEntries = scandir($sourceRoot);
        if ($sourceEntries === false) {
            throw new RuntimeException('Unable to scan package wp-content payload.');
        }

        $managed = [];
        foreach ($sourceEntries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if ($this->shouldSkipSourceEntry($entry)) {
                continue;
            }

            $managed[$entry] = true;

            $source = $sourceRoot . '/' . $entry;
            $target = WP_CONTENT_DIR . '/' . $entry;

            $exclude = null;
            if ($entry === 'plugins') {
                $exclude = function (string $relative): bool {
                    $normalized = trim(str_replace('\\', '/', $relative), '/');
                    return $normalized === 'lift-teleport' || str_starts_with($normalized, 'lift-teleport/');
                };
            }

            if (is_dir($source)) {
                Filesystem::ensureDirectory($target);
                Filesystem::syncDirectory($source, $target, $exclude);
                continue;
            }

            Filesystem::copyFile($source, $target);
        }

        $targetEntries = scandir(WP_CONTENT_DIR);
        if ($targetEntries !== false) {
            foreach ($targetEntries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                if ($this->shouldPreserveTargetEntry($entry)) {
                    continue;
                }

                if (! isset($managed[$entry])) {
                    Filesystem::deletePath(WP_CONTENT_DIR . '/' . $entry);
                }
            }
        }

        $cleanup = (new ArtifactGarbageCollector($this->jobs))->cleanupAfterStep($job, $this->key());
        if ((int) ($cleanup['bytes_reclaimed'] ?? 0) > 0) {
            $payload = ArtifactGarbageCollector::mergeCleanupPayload($payload, $cleanup, 'import_sync_content');
            $this->jobs->addEvent($this->jobId($job), 'info', 'artifact_cleanup_completed', [
                'reason' => 'import_sync_content',
                'bytes_reclaimed' => (int) ($cleanup['bytes_reclaimed'] ?? 0),
                'deleted_paths' => $cleanup['deleted_paths'] ?? [],
            ]);
        }

        return [
            'status' => 'next',
            'next_step' => 'import_restore_database',
            'payload' => $payload,
            'progress' => 68,
            'message' => 'wp-content synchronized.',
        ];
    }

    private function shouldPreserveTargetEntry(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return true;
        }

        return in_array($entry, [
            'lift-teleport-data',
            'lift-teleport-cache',
            // These directories can be absent from file-only archives when empty.
            // Keep them to avoid deleting runtime-critical paths (including this plugin itself).
            'plugins',
            'themes',
            'uploads',
            'mu-plugins',
            'languages',
        ], true);
    }

    private function shouldSkipSourceEntry(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return true;
        }

        // Never import runtime folders from package payload even if present.
        return in_array($entry, [
            'lift-teleport-data',
            'lift-teleport-cache',
        ], true);
    }
}
