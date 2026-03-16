<?php

declare(strict_types=1);

namespace LiftTeleport\Support;

use LiftTeleport\Jobs\JobRepository;

final class ArtifactGarbageCollector
{
    private const ACTIVE_STATUSES = [
        JobRepository::STATUS_UPLOADING,
        JobRepository::STATUS_PENDING,
        JobRepository::STATUS_RUNNING,
    ];

    private const TERMINAL_STATUSES = [
        JobRepository::STATUS_COMPLETED,
        JobRepository::STATUS_FAILED,
        JobRepository::STATUS_FAILED_ROLLBACK,
        JobRepository::STATUS_CANCELLED,
    ];

    private ?JobRepository $jobs;

    public function __construct(?JobRepository $jobs = null)
    {
        $this->jobs = $jobs;
    }

    /**
     * @param array<string,mixed> $job
     * @return array{bytes_reclaimed:int,deleted_paths:array<int,string>,reason:string,available_before:int|null,available_after:int|null}
     */
    public function cleanupAfterStep(array $job, string $step): array
    {
        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId <= 0) {
            return $this->emptyReport('invalid_job');
        }

        $type = (string) ($job['type'] ?? '');
        if ($type !== 'import') {
            return $this->emptyReport('step_not_applicable');
        }

        $report = $this->emptyReport('step_cleanup');
        $report['available_before'] = Filesystem::freeDiskSpace(Paths::dataRoot());

        switch ($step) {
            case 'import_extract_package':
                $this->trackedDelete(Paths::jobInput($jobId) . '/upload.lift', $report);
                $this->trackedDelete(Paths::jobInput($jobId) . '/upload.lift.part', $report);
                $this->trackedDelete(Paths::jobWorkspace($jobId) . '/decrypted-package.lift', $report);
                break;

            case 'import_sync_content':
                $this->trackedDelete(Paths::jobWorkspace($jobId) . '/extracted/content', $report);
                break;

            case 'import_restore_database':
                $this->trackedDelete(Paths::jobWorkspace($jobId) . '/extracted/db', $report);
                $this->trackedDelete(Paths::jobWorkspace($jobId) . '/import-package.tar', $report);
                break;
        }

        $report['available_after'] = Filesystem::freeDiskSpace(Paths::dataRoot());

        return $report;
    }

    /**
     * @param array<string,mixed> $job
     * @return array{bytes_reclaimed:int,deleted_paths:array<int,string>,reason:string,available_before:int|null,available_after:int|null}
     */
    public function cleanupTerminal(array $job, string $terminalStatus): array
    {
        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId <= 0) {
            return $this->emptyReport('invalid_job');
        }

        if (! in_array($terminalStatus, self::TERMINAL_STATUSES, true)) {
            return $this->emptyReport('status_not_terminal');
        }

        $type = (string) ($job['type'] ?? '');
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $report = $this->emptyReport('terminal_cleanup');
        $report['available_before'] = Filesystem::freeDiskSpace(Paths::dataRoot());

        if ($type === 'import') {
            if ($terminalStatus === JobRepository::STATUS_FAILED_ROLLBACK) {
                $report['reason'] = 'failed_rollback_retained';
                $report['available_after'] = $report['available_before'];
                return $report;
            }

            $this->trackedDelete(Paths::jobRoot($jobId), $report);
            $report['reason'] = 'import_terminal_cleanup';
            $report['available_after'] = Filesystem::freeDiskSpace(Paths::dataRoot());
            return $report;
        }

        if ($type === 'export') {
            if ($terminalStatus === JobRepository::STATUS_COMPLETED) {
                $this->cleanupExportArtifacts($job, false, $report);
                $report['reason'] = 'export_compacted';
                $report['available_after'] = Filesystem::freeDiskSpace(Paths::dataRoot());
                return $report;
            }

            $this->trackedDelete(Paths::jobRoot($jobId), $report);
            $report['reason'] = 'export_terminal_cleanup';
            $report['available_after'] = Filesystem::freeDiskSpace(Paths::dataRoot());
            return $report;
        }

        if ($type === 'unzipper' && $terminalStatus !== JobRepository::STATUS_COMPLETED) {
            $this->trackedDelete(Paths::jobRoot($jobId), $report);
            $report['reason'] = 'unzipper_terminal_cleanup';
        }

        $report['available_after'] = Filesystem::freeDiskSpace(Paths::dataRoot());
        return $report;
    }

    /**
     * @return array{bytes_reclaimed:int,available_before:int|null,available_after:int|null,required_free_bytes:int,cleaned_jobs:array<int,int>,deleted_paths:array<int,string>,reason:string}
     */
    public function cleanupForDiskPressure(int $requiredFreeBytes, ?int $excludeJobId = null): array
    {
        $mode = (string) apply_filters('lift_teleport_storage_cleanup_mode', 'aggressive');
        if ($mode !== 'aggressive') {
            return [
                'bytes_reclaimed' => 0,
                'available_before' => Filesystem::freeDiskSpace(Paths::dataRoot()),
                'available_after' => Filesystem::freeDiskSpace(Paths::dataRoot()),
                'required_free_bytes' => max(1, $requiredFreeBytes),
                'cleaned_jobs' => [],
                'deleted_paths' => [],
                'reason' => 'cleanup_mode_disabled',
            ];
        }

        $minimumFreeBytes = (int) apply_filters('lift_teleport_storage_min_free_bytes', 15 * 1024 * 1024 * 1024);
        $minimumFreeBytes = max(1, $minimumFreeBytes);
        $requiredFreeBytes = max(1, $requiredFreeBytes, $minimumFreeBytes);
        $report = [
            'bytes_reclaimed' => 0,
            'available_before' => Filesystem::freeDiskSpace(Paths::dataRoot()),
            'available_after' => null,
            'required_free_bytes' => $requiredFreeBytes,
            'cleaned_jobs' => [],
            'deleted_paths' => [],
            'reason' => 'disk_pressure_cleanup',
        ];

        $orphan = $this->cleanupOrphanedJobDirectories(500);
        $report['bytes_reclaimed'] += $orphan['bytes_reclaimed'];
        $report['deleted_paths'] = array_merge($report['deleted_paths'], $orphan['deleted_paths']);

        $dirs = $this->listJobDirectories();
        foreach ($dirs as $dir) {
            $jobId = (int) basename($dir);
            if ($jobId <= 0 || ($excludeJobId !== null && $jobId === $excludeJobId)) {
                continue;
            }

            $job = $this->safeGetJob($jobId);
            if (! $job) {
                continue;
            }

            $status = (string) ($job['status'] ?? '');
            if (in_array($status, self::ACTIVE_STATUSES, true)) {
                continue;
            }

            $cleanup = $this->cleanupTerminalByPolicy($job, true);
            if ($cleanup['bytes_reclaimed'] > 0) {
                $report['bytes_reclaimed'] += $cleanup['bytes_reclaimed'];
                $report['deleted_paths'] = array_merge($report['deleted_paths'], $cleanup['deleted_paths']);
                $report['cleaned_jobs'][] = $jobId;
            }

            $availableNow = Filesystem::freeDiskSpace(Paths::dataRoot());
            if (is_int($availableNow) && $availableNow >= $requiredFreeBytes) {
                break;
            }
        }

        $report['available_after'] = Filesystem::freeDiskSpace(Paths::dataRoot());

        return $report;
    }

    /**
     * @return array{bytes_reclaimed:int,deleted_paths:array<int,string>,cleaned_jobs:array<int,int>,reason:string}
     */
    public function cleanupRetention(): array
    {
        $aggregate = [
            'bytes_reclaimed' => 0,
            'deleted_paths' => [],
            'cleaned_jobs' => [],
            'reason' => 'retention_cleanup',
        ];

        $orphan = $this->cleanupOrphanedJobDirectories(1000);
        $aggregate['bytes_reclaimed'] += $orphan['bytes_reclaimed'];
        $aggregate['deleted_paths'] = array_merge($aggregate['deleted_paths'], $orphan['deleted_paths']);

        foreach ($this->listJobDirectories() as $dir) {
            $jobId = (int) basename($dir);
            if ($jobId <= 0) {
                continue;
            }

            $job = $this->jobs?->get($jobId);
            if (! $job) {
                continue;
            }

            $status = (string) ($job['status'] ?? '');
            if (in_array($status, self::ACTIVE_STATUSES, true)) {
                continue;
            }

            $cleanup = $this->cleanupTerminalByPolicy($job, false);
            if ($cleanup['bytes_reclaimed'] <= 0) {
                continue;
            }

            $aggregate['bytes_reclaimed'] += $cleanup['bytes_reclaimed'];
            $aggregate['deleted_paths'] = array_merge($aggregate['deleted_paths'], $cleanup['deleted_paths']);
            $aggregate['cleaned_jobs'][] = $jobId;
        }

        return $aggregate;
    }

    /**
     * @return array{bytes_reclaimed:int,deleted_paths:array<int,string>,reason:string}
     */
    public function cleanupOrphanedJobDirectories(int $limit = 200): array
    {
        $report = [
            'bytes_reclaimed' => 0,
            'deleted_paths' => [],
            'reason' => 'orphan_cleanup',
        ];

        $count = 0;
        foreach ($this->listJobDirectories() as $dir) {
            if ($count >= $limit) {
                break;
            }

            $jobId = (int) basename($dir);
            if ($jobId <= 0) {
                continue;
            }

            if ($this->jobs !== null && $this->safeGetJob($jobId)) {
                continue;
            }

            $count++;
            $this->trackedDelete($dir, $report);
        }

        return $report;
    }

    /**
     * @return array{
     *   lift_data_bytes:int,
     *   lift_jobs_bytes:int,
     *   reclaimable_bytes:int,
     *   largest_job_bytes:int,
     *   largest_job_id:int,
     *   oldest_retained_job:array<string,mixed>|null,
     *   by_type:array<string,int>
     * }
     */
    public function storageSummary(): array
    {
        $root = Paths::dataRoot();
        $jobsRoot = Paths::jobsRoot();
        $liftDataBytes = is_dir($root) ? Filesystem::directorySize($root) : 0;
        $jobsBytes = is_dir($jobsRoot) ? Filesystem::directorySize($jobsRoot) : 0;

        $reclaimable = 0;
        $largestBytes = 0;
        $largestJobId = 0;
        $oldestRetained = null;
        $byType = [];

        foreach ($this->listJobDirectories() as $dir) {
            $jobId = (int) basename($dir);
            if ($jobId <= 0) {
                continue;
            }

            $size = is_dir($dir) ? Filesystem::directorySize($dir) : (file_exists($dir) ? (int) filesize($dir) : 0);
            if ($size > $largestBytes) {
                $largestBytes = $size;
                $largestJobId = $jobId;
            }

            $job = $this->safeGetJob($jobId);
            $type = $job ? (string) ($job['type'] ?? 'unknown') : 'orphan';
            $byType[$type] = (int) ($byType[$type] ?? 0) + $size;

            if ($job && $this->isReclaimableNow($job)) {
                $reclaimable += $size;
            }

            if ($job && ! $this->isReclaimableNow($job)) {
                $ts = $this->jobTimestamp($job);
                if ($ts > 0 && ($oldestRetained === null || $ts < (int) ($oldestRetained['timestamp'] ?? PHP_INT_MAX))) {
                    $oldestRetained = [
                        'job_id' => $jobId,
                        'type' => (string) ($job['type'] ?? ''),
                        'status' => (string) ($job['status'] ?? ''),
                        'timestamp' => $ts,
                        'at' => gmdate(DATE_ATOM, $ts),
                    ];
                }
            }
        }

        ksort($byType);

        return [
            'lift_data_bytes' => $liftDataBytes,
            'lift_jobs_bytes' => $jobsBytes,
            'reclaimable_bytes' => $reclaimable,
            'largest_job_bytes' => $largestBytes,
            'largest_job_id' => $largestJobId,
            'oldest_retained_job' => $oldestRetained,
            'by_type' => $byType,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $cleanup
     * @return array<string,mixed>
     */
    public static function mergeCleanupPayload(array $payload, array $cleanup, string $reason): array
    {
        $storage = is_array($payload['storage_cleanup'] ?? null) ? $payload['storage_cleanup'] : [];
        $storage['last_cleanup_at'] = gmdate(DATE_ATOM);
        $storage['reason'] = $reason;
        $storage['last_bytes_reclaimed'] = max(0, (int) ($cleanup['bytes_reclaimed'] ?? 0));
        $storage['bytes_reclaimed_total'] = max(0, (int) ($storage['bytes_reclaimed_total'] ?? 0)) + $storage['last_bytes_reclaimed'];
        $deletedPaths = is_array($cleanup['deleted_paths'] ?? null) ? $cleanup['deleted_paths'] : [];
        $storage['deleted_paths'] = array_slice(array_values(array_unique(array_map('strval', $deletedPaths))), 0, 25);

        $payload['storage_cleanup'] = $storage;

        return $payload;
    }

    /**
     * @param array<string,mixed> $job
     * @return array{bytes_reclaimed:int,deleted_paths:array<int,string>,reason:string,available_before:int|null,available_after:int|null}
     */
    private function cleanupTerminalByPolicy(array $job, bool $forDiskPressure): array
    {
        $status = (string) ($job['status'] ?? '');
        $type = (string) ($job['type'] ?? '');

        if (! in_array($status, self::TERMINAL_STATUSES, true)) {
            return $this->emptyReport('status_not_terminal');
        }

        if ($type === 'import' && $status === JobRepository::STATUS_FAILED_ROLLBACK) {
            if (! $this->isFailedRollbackExpired($job)) {
                return $this->emptyReport('failed_rollback_retained');
            }
        }

        if ($type === 'export' && $status === JobRepository::STATUS_COMPLETED) {
            $report = $this->emptyReport('export_retention');
            $report['available_before'] = Filesystem::freeDiskSpace(Paths::dataRoot());
            $this->cleanupExportArtifacts($job, $forDiskPressure, $report);
            $report['available_after'] = Filesystem::freeDiskSpace(Paths::dataRoot());
            return $report;
        }

        if ($type === 'import' || $type === 'export' || ($forDiskPressure && $type === 'unzipper')) {
            $report = $this->emptyReport('policy_delete_job_root');
            $report['available_before'] = Filesystem::freeDiskSpace(Paths::dataRoot());
            $this->trackedDelete(Paths::jobRoot((int) ($job['id'] ?? 0)), $report);
            $report['available_after'] = Filesystem::freeDiskSpace(Paths::dataRoot());
            return $report;
        }

        return $this->emptyReport('policy_skip');
    }

    /**
     * @param array<string,mixed> $job
     * @param array{bytes_reclaimed:int,deleted_paths:array<int,string>,reason:string,available_before:int|null,available_after:int|null} $report
     */
    private function cleanupExportArtifacts(array $job, bool $forDiskPressure, array &$report): void
    {
        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId <= 0) {
            return;
        }

        $jobRoot = Paths::jobRoot($jobId);
        if (! is_dir($jobRoot)) {
            return;
        }

        $keepFile = $this->resolveExportOutputFile($job);

        $this->trackedDelete(Paths::jobInput($jobId), $report);
        $this->trackedDelete(Paths::jobWorkspace($jobId), $report);
        $this->trackedDelete(Paths::jobRollback($jobId), $report);

        $outputDir = Paths::jobOutput($jobId);
        if (is_dir($outputDir)) {
            $entries = @scandir($outputDir);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..' || $entry === 'index.php' || $entry === '.htaccess' || $entry === 'web.config') {
                        continue;
                    }

                    $candidate = $outputDir . '/' . $entry;
                    if ($keepFile !== '' && $this->normalizePath($candidate) === $this->normalizePath($keepFile)) {
                        continue;
                    }

                    $this->trackedDelete($candidate, $report);
                }
            }
        }

        if ($forDiskPressure || $this->isExportExpired($job)) {
            $this->trackedDelete($jobRoot, $report);
            $report['reason'] = 'export_expired_or_pressure_deleted';
            return;
        }

        $report['reason'] = 'export_compacted_keep_download';
    }

    /**
     * @param array<string,mixed> $job
     */
    private function isExportExpired(array $job): bool
    {
        $retention = (int) apply_filters('lift_teleport_retention_export_seconds', DAY_IN_SECONDS);
        $retention = max(HOUR_IN_SECONDS, $retention);

        $downloadGrace = (int) apply_filters('lift_teleport_retention_export_download_grace_seconds', HOUR_IN_SECONDS);
        $downloadGrace = max(300, $downloadGrace);

        $now = time();
        $baseTs = $this->jobTimestamp($job);
        if ($baseTs <= 0) {
            return false;
        }

        $expiresAt = $baseTs + $retention;

        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $download = is_array($payload['download'] ?? null) ? $payload['download'] : [];
        $downloadedAt = isset($download['downloaded_at']) ? strtotime((string) $download['downloaded_at']) : false;
        if ($downloadedAt !== false && $downloadedAt > 0) {
            $expiresAt = min($expiresAt, $downloadedAt + $downloadGrace);
        }

        return $now >= $expiresAt;
    }

    /**
     * @param array<string,mixed> $job
     */
    private function isFailedRollbackExpired(array $job): bool
    {
        $retention = (int) apply_filters('lift_teleport_retention_failed_rollback_seconds', DAY_IN_SECONDS);
        $retention = max(HOUR_IN_SECONDS, $retention);

        $ts = $this->jobTimestamp($job);
        if ($ts <= 0) {
            return false;
        }

        return time() >= ($ts + $retention);
    }

    /**
     * @param array<string,mixed> $job
     */
    private function isReclaimableNow(array $job): bool
    {
        $status = (string) ($job['status'] ?? '');
        $type = (string) ($job['type'] ?? '');

        if (in_array($status, self::ACTIVE_STATUSES, true)) {
            return false;
        }

        if ($type === 'import') {
            if ($status === JobRepository::STATUS_FAILED_ROLLBACK) {
                return $this->isFailedRollbackExpired($job);
            }

            return in_array($status, self::TERMINAL_STATUSES, true);
        }

        if ($type === 'export') {
            if ($status === JobRepository::STATUS_COMPLETED) {
                return $this->isExportExpired($job);
            }

            return in_array($status, self::TERMINAL_STATUSES, true);
        }

        return false;
    }

    /**
     * @param array<string,mixed> $job
     */
    private function resolveExportOutputFile(array $job): string
    {
        $result = is_array($job['result'] ?? null) ? $job['result'] : [];
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];

        $file = (string) ($result['file'] ?? '');
        if ($file === '' && isset($payload['package']['file']) && is_string($payload['package']['file'])) {
            $file = (string) $payload['package']['file'];
        }

        if ($file === '') {
            return '';
        }

        $normalized = $this->normalizePath($file);
        $allowedPrefix = $this->normalizePath(Paths::jobOutput((int) ($job['id'] ?? 0))) . '/';
        if (! str_starts_with($normalized, $allowedPrefix)) {
            return '';
        }

        return $normalized;
    }

    /**
     * @return array<int,string>
     */
    private function listJobDirectories(): array
    {
        $jobsRoot = Paths::jobsRoot();
        if (! is_dir($jobsRoot)) {
            return [];
        }

        $entries = @scandir($jobsRoot);
        if (! is_array($entries)) {
            return [];
        }

        $dirs = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! ctype_digit((string) $entry)) {
                continue;
            }

            $path = $jobsRoot . '/' . $entry;
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }

        usort($dirs, static function (string $left, string $right): int {
            $leftMtime = @filemtime($left) ?: 0;
            $rightMtime = @filemtime($right) ?: 0;

            if ($leftMtime === $rightMtime) {
                return strcmp($left, $right);
            }

            return $leftMtime <=> $rightMtime;
        });

        return $dirs;
    }

    /**
     * @param array{bytes_reclaimed:int,deleted_paths:array<int,string>} $report
     */
    private function trackedDelete(string $path, array &$report): void
    {
        $normalized = $this->normalizePath($path);
        if ($normalized === '' || ! $this->isWithinJobsRoot($normalized) || ! file_exists($normalized)) {
            return;
        }

        $bytes = is_file($normalized)
            ? (int) filesize($normalized)
            : Filesystem::directorySize($normalized);

        Filesystem::deletePath($normalized);

        if (file_exists($normalized)) {
            return;
        }

        $report['bytes_reclaimed'] += max(0, $bytes);
        $report['deleted_paths'][] = $normalized;
    }

    /**
     * @param array<string,mixed> $job
     */
    private function jobTimestamp(array $job): int
    {
        foreach (['finished_at', 'updated_at', 'started_at', 'created_at'] as $field) {
            $value = isset($job[$field]) ? (string) $job[$field] : '';
            if ($value === '') {
                continue;
            }

            $ts = strtotime($value);
            if ($ts !== false && $ts > 0) {
                return $ts;
            }
        }

        return 0;
    }

    private function isWithinJobsRoot(string $path): bool
    {
        $jobsRoot = $this->normalizePath(Paths::jobsRoot());
        if ($jobsRoot === '') {
            return false;
        }

        return $path === $jobsRoot || str_starts_with($path, $jobsRoot . '/');
    }

    private function normalizePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        return rtrim(str_replace('\\', '/', $trimmed), '/');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function safeGetJob(int $jobId): ?array
    {
        if ($this->jobs === null) {
            return null;
        }

        try {
            $job = $this->jobs->get($jobId);
            return is_array($job) ? $job : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{bytes_reclaimed:int,deleted_paths:array<int,string>,reason:string,available_before:int|null,available_after:int|null}
     */
    private function emptyReport(string $reason): array
    {
        return [
            'bytes_reclaimed' => 0,
            'deleted_paths' => [],
            'reason' => $reason,
            'available_before' => null,
            'available_after' => null,
        ];
    }
}
