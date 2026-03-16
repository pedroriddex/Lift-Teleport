<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Export;

use LiftTeleport\Archive\LiftPackage;
use LiftTeleport\Jobs\JobRepository;
use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Jobs\Steps\StepFailure;
use Throwable;

final class PackageStep extends AbstractStep
{
    private LiftPackage $package;

    public function __construct($jobs)
    {
        parent::__construct($jobs);
        $this->package = new LiftPackage();
    }

    public function key(): string
    {
        return 'export_package';
    }

    public function run(array $job): array
    {
        $startedAt = microtime(true);
        $jobId = $this->jobId($job);
        $payload = $this->payload($job);
        $manifest = is_array($payload['manifest'] ?? null) ? $payload['manifest'] : [];
        $password = isset($payload['password']) ? (string) $payload['password'] : null;

        $this->jobs->addEvent($jobId, 'debug', 'Starting package build.', [
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
        ]);

        $lastPersistAt = 0.0;
        $lastPhase = '';
        $lastBytesDone = 0;
        $lastBytesAt = microtime(true);
        $lastTickEventAt = 0.0;
        $lastCancelCheckAt = 0.0;
        $lastCompressionAttempt = '';

        $progressCallback = function (array $metrics) use (
            $jobId,
            &$payload,
            &$lastPersistAt,
            &$lastPhase,
            &$lastBytesDone,
            &$lastBytesAt,
            &$lastTickEventAt,
            &$lastCancelCheckAt,
            &$lastCompressionAttempt
        ): void {
            $phase = isset($metrics['phase']) ? (string) $metrics['phase'] : 'tar_build';
            $phaseStatus = isset($metrics['status']) ? (string) $metrics['status'] : 'running';
            $algorithm = isset($metrics['algorithm']) ? (string) $metrics['algorithm'] : '';
            $attempt = max(1, (int) ($metrics['attempt'] ?? 1));
            $fallbackStage = isset($metrics['fallback_stage']) ? (string) $metrics['fallback_stage'] : ($attempt === 1 ? 'primary' : 'fallback');
            $elapsedSeconds = max(0.0, (float) ($metrics['elapsed_seconds'] ?? 0));
            $outputBytes = max(0, (int) ($metrics['output_bytes'] ?? 0));
            $engine = isset($metrics['engine']) ? (string) $metrics['engine'] : '';

            $filesDone = max(0, (int) ($metrics['files_done'] ?? 0));
            $filesTotal = max($filesDone, (int) ($metrics['files_total'] ?? 0));
            $bytesDone = max(0, (int) ($metrics['bytes_done'] ?? 0));
            $bytesTotal = max($bytesDone, (int) ($metrics['bytes_total'] ?? 0));

            $now = microtime(true);
            $throughput = 0;
            if ($bytesDone >= $lastBytesDone && ($now - $lastBytesAt) >= 0.75) {
                $elapsed = max(0.001, $now - $lastBytesAt);
                $throughput = (int) floor(($bytesDone - $lastBytesDone) / $elapsed);
                $lastBytesDone = $bytesDone;
                $lastBytesAt = $now;
            }

            $phaseProgress = $this->phaseProgressPercent($phase, [
                'files_done' => $filesDone,
                'files_total' => $filesTotal,
                'bytes_done' => $bytesDone,
                'bytes_total' => $bytesTotal,
                'elapsed_seconds' => $elapsedSeconds,
            ]);
            $mappedProgress = 60 + ($phaseProgress * 0.30);

            $payload['step_metrics']['export_package'] = [
                'at' => gmdate(DATE_ATOM),
                'phase' => $phase,
                'status' => $phaseStatus,
                'files_done' => $filesDone,
                'files_total' => $filesTotal,
                'bytes_done' => $bytesDone,
                'bytes_total' => $bytesTotal,
                'elapsed_seconds' => round($elapsedSeconds, 3),
                'output_bytes' => $outputBytes,
                'algorithm' => $algorithm,
                'engine' => $engine,
                'attempt' => $attempt,
                'fallback_stage' => $fallbackStage,
                'throughput_bytes_sec' => $throughput,
                'phase_progress' => round($phaseProgress, 2),
                'last_output_bytes' => $outputBytes,
            ];
            $payload['diagnostics']['last_step'] = 'export_package';
            $payload['diagnostics']['last_step_at'] = gmdate(DATE_ATOM);

            $phaseChanged = $phase !== $lastPhase;
            if ($phaseChanged) {
                $this->jobs->addEvent($jobId, 'debug', 'export_package_phase_changed', [
                    'phase' => $phase,
                    'progress' => round($mappedProgress, 2),
                ]);
                $lastPhase = $phase;
            }

            if ($phase === 'compress_package') {
                $attemptKey = $algorithm . '#' . $attempt . '#' . $fallbackStage;
                if ($phaseStatus === 'attempt_started' && $attemptKey !== $lastCompressionAttempt) {
                    if ($algorithm !== '') {
                        $this->jobs->addEvent($jobId, 'info', 'compression_attempt_started', [
                            'algorithm' => $algorithm,
                            'attempt' => $attempt,
                            'fallback_stage' => $fallbackStage,
                        ]);
                    }
                    $lastCompressionAttempt = $attemptKey;
                }

                if ($phaseStatus === 'stalled') {
                    $this->jobs->addEvent($jobId, 'warning', 'compression_attempt_stalled', [
                        'algorithm' => $algorithm,
                        'attempt' => $attempt,
                        'fallback_stage' => $fallbackStage,
                        'error_message' => (string) ($metrics['error_message'] ?? ''),
                    ]);
                    $this->jobs->addEvent($jobId, 'warning', 'compression_fallback_applied', [
                        'algorithm' => $algorithm,
                        'attempt' => $attempt,
                    ]);
                }

                if ($phaseStatus === 'completed') {
                    $this->jobs->addEvent($jobId, 'info', 'compression_completed', [
                        'algorithm' => $algorithm,
                        'attempt' => $attempt,
                        'fallback_stage' => $fallbackStage,
                        'output_bytes' => $outputBytes,
                    ]);
                }
            }

            if ($phase === 'encrypt_package' && $phaseStatus === 'running' && $elapsedSeconds <= 0.001) {
                $this->jobs->addEvent($jobId, 'info', 'encryption_started');
            }
            if ($phase === 'encrypt_package' && $phaseStatus === 'completed') {
                $this->jobs->addEvent($jobId, 'info', 'encryption_completed', [
                    'bytes_done' => $bytesDone,
                    'bytes_total' => $bytesTotal,
                ]);
            }

            $forceCompressPersist = $phase === 'compress_package' && ($now - $lastPersistAt) >= 2.0;
            $shouldPersist = $phaseChanged || ($now - $lastPersistAt) >= 0.8 || $phase === 'finalize_package' || $forceCompressPersist;
            if ($shouldPersist) {
                $lastPersistAt = $now;
                $this->jobs->update($jobId, [
                    'payload' => $payload,
                    'progress' => min(89.9, round($mappedProgress, 2)),
                    'message' => $this->phaseMessage($phase),
                    'worker_heartbeat_at' => current_time('mysql', true),
                ]);

                if (($now - $lastTickEventAt) >= 2.0 || $phase === 'finalize_package') {
                    $this->jobs->addEvent($jobId, 'debug', 'export_package_progress_tick', [
                        'phase' => $phase,
                        'files_done' => $filesDone,
                        'files_total' => $filesTotal,
                        'bytes_done' => $bytesDone,
                        'bytes_total' => $bytesTotal,
                        'throughput_bytes_sec' => $throughput,
                    ]);
                    $lastTickEventAt = $now;
                }
            }

            if (($now - $lastCancelCheckAt) < 1.0) {
                return;
            }

            $lastCancelCheckAt = $now;
            $fresh = $this->jobs->get($jobId);
            if (! $fresh) {
                return;
            }

            if ((string) ($fresh['status'] ?? '') === JobRepository::STATUS_CANCELLED) {
                throw StepFailure::fatal(
                    'lift_job_cancelled',
                    'Cancelled by operator while packaging.'
                );
            }

            $freshPayload = is_array($fresh['payload'] ?? null) ? $fresh['payload'] : [];
            if (! empty($freshPayload['cancel_requested'])) {
                throw StepFailure::fatal(
                    'lift_job_cancelled',
                    'Cancelled by operator while packaging.'
                );
            }
        };

        try {
            $result = $this->package->buildExportPackage(
                $jobId,
                $manifest,
                $password,
                $progressCallback
            );
        } catch (StepFailure $failure) {
            throw $failure;
        } catch (Throwable $error) {
            throw StepFailure::retryable(
                'lift_export_package_failed',
                $error->getMessage() !== '' ? $error->getMessage() : 'Export package build failed.',
                'Retry the export. If the issue persists, run the same job via WP-CLI.',
                [],
                $error
            );
        }

        $payload['package'] = $result;
        $this->jobs->addEvent($jobId, 'info', 'Package build completed.', [
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
            'compression' => (string) ($result['compression'] ?? 'unknown'),
            'size_bytes' => (int) ($result['size'] ?? 0),
            'checksum_files' => (int) ($result['checksum_files'] ?? 0),
            'format' => (string) ($result['format'] ?? 'lift-v1'),
            'format_revision' => (string) ($result['format_revision'] ?? ''),
            'payload_summary' => is_array($result['payload_summary'] ?? null) ? $result['payload_summary'] : [],
        ]);

        $exclusions = is_array($result['exclusions'] ?? null) ? $result['exclusions'] : [];
        $excludedCount = (int) ($exclusions['count'] ?? 0);
        if ($excludedCount > 0) {
            $this->jobs->addEvent($jobId, 'info', 'export_exclusions_applied', [
                'excluded_paths' => $excludedCount,
                'excluded_bytes' => (int) ($exclusions['bytes'] ?? 0),
                'sample_paths' => is_array($exclusions['sample_paths'] ?? null) ? $exclusions['sample_paths'] : [],
            ]);
        }

        return [
            'status' => 'next',
            'next_step' => 'export_finalize',
            'payload' => $payload,
            'progress' => 90,
            'message' => 'Finalizing .lift package...',
        ];
    }

    /**
     * @param array<string,mixed> $metrics
     */
    private function phaseProgressPercent(string $phase, array $metrics): float
    {
        $weights = [
            'scan_content' => [0.0, 12.0],
            'checksum_payload' => [12.0, 45.0],
            'tar_build' => [45.0, 82.0],
            'compress_package' => [82.0, 94.0],
            'encrypt_package' => [94.0, 98.0],
            'finalize_package' => [98.0, 100.0],
        ];

        [$start, $end] = $weights[$phase] ?? [45.0, 82.0];
        $range = max(0.0, $end - $start);

        $filesDone = (int) ($metrics['files_done'] ?? 0);
        $filesTotal = (int) ($metrics['files_total'] ?? 0);
        $bytesDone = (int) ($metrics['bytes_done'] ?? 0);
        $bytesTotal = (int) ($metrics['bytes_total'] ?? 0);
        $elapsedSeconds = max(0.0, (float) ($metrics['elapsed_seconds'] ?? 0));

        $ratio = 0.0;
        if ($phase === 'compress_package') {
            $byteRatio = $bytesTotal > 0 ? min(1.0, $bytesDone / $bytesTotal) : 0.0;
            $timeRatio = 1 - exp(-$elapsedSeconds / 75.0);
            $ratio = min(0.995, max($byteRatio, $timeRatio));
        } elseif ($phase === 'encrypt_package') {
            if ($bytesTotal > 0) {
                $ratio = min(1.0, $bytesDone / $bytesTotal);
            } else {
                $ratio = min(0.98, 1 - exp(-$elapsedSeconds / 20.0));
            }
        } elseif ($bytesTotal > 0) {
            $ratio = min(1.0, $bytesDone / $bytesTotal);
        } elseif ($filesTotal > 0) {
            $ratio = min(1.0, $filesDone / $filesTotal);
        } else {
            $ratio = in_array($phase, ['compress_package', 'encrypt_package', 'finalize_package'], true) ? 1.0 : 0.0;
        }

        return min(100.0, max(0.0, $start + ($range * $ratio)));
    }

    private function phaseMessage(string $phase): string
    {
        return match ($phase) {
            'scan_content' => 'Scanning files...',
            'checksum_payload' => 'Checksumming payload...',
            'tar_build' => 'Building tar archive...',
            'compress_package' => 'Compressing package...',
            'encrypt_package' => 'Encrypting package...',
            'finalize_package' => 'Finalizing .lift...',
            default => 'Packaging files into .lift...',
        };
    }
}
