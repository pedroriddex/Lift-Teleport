<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs;

use LiftTeleport\Archive\CompressionEngine;
use LiftTeleport\Import\DatabaseImporter;
use LiftTeleport\Import\ReadOnlyMode;
use LiftTeleport\Jobs\Steps\Factory;
use LiftTeleport\Jobs\Steps\StepFailure;
use LiftTeleport\Support\ArtifactGarbageCollector;
use LiftTeleport\Support\CommandRunner;
use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;
use LiftTeleport\Support\SchemaOutOfSyncException;
use Throwable;

final class JobRunner
{
    private JobRepository $jobs;

    public function __construct(JobRepository $jobs)
    {
        $this->jobs = $jobs;
    }

    public function run(int $jobId, int $timeLimit = 8): ?array
    {
        $job = $this->jobs->get($jobId);
        if (! $job) {
            return null;
        }

        if (PHP_SAPI !== 'cli') {
            if (function_exists('ignore_user_abort')) {
                @ignore_user_abort(true);
            }

            if (function_exists('set_time_limit')) {
                $runtimeLimit = (int) apply_filters('lift_teleport_runner_max_execution_seconds', 0, $job, $timeLimit);
                if ($runtimeLimit >= 0) {
                    @set_time_limit($runtimeLimit);
                }
            }
        }

        if (in_array($job['status'], [JobRepository::STATUS_COMPLETED, JobRepository::STATUS_FAILED, JobRepository::STATUS_FAILED_ROLLBACK, JobRepository::STATUS_CANCELLED], true)) {
            return $job;
        }

        $configuredLockTtl = (int) apply_filters('lift_teleport_job_lock_ttl_seconds', 900, $job, $timeLimit);
        $lockTtl = max(30, $timeLimit + 30, $configuredLockTtl);

        $owner = 'runner-' . wp_generate_uuid4();
        if (! $this->jobs->claimLock($jobId, $owner, $lockTtl)) {
            return $job;
        }

        $deadline = microtime(true) + max(3, $timeLimit);

        try {
            if ($job['status'] === JobRepository::STATUS_PENDING) {
                $this->jobs->markRunning($jobId);
                do_action('lift_teleport_job_started', $job);
                $job = $this->jobs->get($jobId);
            }

            while ($job && microtime(true) < $deadline) {
                if ($job['status'] !== JobRepository::STATUS_RUNNING && $job['status'] !== JobRepository::STATUS_PENDING) {
                    break;
                }

                // Renew lock window before each step to avoid concurrent runners on slow hosting.
                $this->jobs->claimLock($jobId, $owner, $lockTtl);
                $this->jobs->update($jobId, [
                    'worker_heartbeat_at' => current_time('mysql', true),
                ]);

                $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
                if ($this->shouldCancelForForegroundTimeout($job, $payload)) {
                    $payload['cancel_requested'] = true;
                    $payload['cancel_requested_at'] = gmdate(DATE_ATOM);
                    $payload['cancel_reason_code'] = 'foreground_heartbeat_timeout';
                    $payload['cancel_reason_message'] = 'Cancelled: operator left Lift screen.';
                    $payload['diagnostics']['foreground_last_seen_at'] = (string) ($payload['foreground_last_seen_at'] ?? '');
                    $this->jobs->update($jobId, [
                        'payload' => $payload,
                        'message' => 'Cancelled: operator left Lift screen.',
                        'worker_heartbeat_at' => current_time('mysql', true),
                    ]);
                    $this->jobs->addEvent($jobId, 'warning', 'foreground_heartbeat_timeout_cancelled', [
                        'ttl_seconds' => (int) apply_filters('lift_teleport_foreground_heartbeat_ttl_seconds', 25, $job),
                        'foreground_last_seen_at' => (string) ($payload['foreground_last_seen_at'] ?? ''),
                    ]);

                    $job['payload'] = $payload;
                    $this->handleCancellation($job);
                    $job = $this->jobs->get($jobId);
                    break;
                }

                if (! empty($payload['cancel_requested'])) {
                    $this->handleCancellation($job);
                    $job = $this->jobs->get($jobId);
                    break;
                }

                $stepKey = (string) ($job['current_step'] ?: Factory::initialStepForType((string) $job['type']));
                $step = Factory::make($stepKey, $this->jobs);
                $this->jobs->addEvent($jobId, 'debug', sprintf('Running step: %s', $stepKey));

                $result = $step->run($job);
                $payload = array_key_exists('payload', $result) && is_array($result['payload'])
                    ? $result['payload']
                    : (is_array($job['payload'] ?? null) ? $job['payload'] : []);
                $payload['diagnostics'] = [
                    'last_step' => $stepKey,
                    'last_step_at' => gmdate(DATE_ATOM),
                ];

                if (isset($result['metrics']) && is_array($result['metrics'])) {
                    $payload['step_metrics'][$stepKey] = array_merge(
                        ['at' => gmdate(DATE_ATOM)],
                        $result['metrics']
                    );
                }

                $update = [
                    'payload' => $payload,
                    'progress' => isset($result['progress']) ? (float) $result['progress'] : (float) $job['progress'],
                    'message' => (string) ($result['message'] ?? $job['message']),
                    'attempts' => 0,
                    'worker_heartbeat_at' => current_time('mysql', true),
                ];

                $status = $result['status'] ?? 'continue';
                if ($status === 'next') {
                    $update['current_step'] = (string) ($result['next_step'] ?? $stepKey);
                    $this->jobs->update($jobId, $update);
                    $job = $this->jobs->get($jobId);
                    continue;
                }

                if ($status === 'done') {
                    $this->jobs->update($jobId, $update);
                    $freshForCleanup = $this->jobs->get($jobId);
                    if (is_array($freshForCleanup)) {
                        $this->applyTerminalCleanup($freshForCleanup, JobRepository::STATUS_COMPLETED, 'runner_done');
                    }
                    $this->jobs->markCompleted($jobId, is_array($result['result'] ?? null) ? $result['result'] : []);
                    $job = $this->jobs->get($jobId);
                    do_action('lift_teleport_job_completed', $job);
                    break;
                }

                $this->jobs->update($jobId, $update);
                break;
            }
        } catch (Throwable $e) {
            $job = $this->jobs->get($jobId);
            if ($job && ($job['status'] ?? '') === JobRepository::STATUS_CANCELLED) {
                return $job;
            }

            $jobPayload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            if (! empty($jobPayload['cancel_requested'])) {
                $this->handleCancellation($job ?: ['id' => $jobId, 'payload' => $jobPayload]);
                return $this->jobs->get($jobId);
            }

            $currentAttempts = (int) ($job['attempts'] ?? 0);
            $attempts = $currentAttempts + 1;
            $classification = $this->classifyError($e);
            $maxAttempts = $classification['retryable'] ? 3 : 1;

            $errorCode = (string) ($classification['error_code'] ?? 'lift_step_failed');
            $hint = (string) ($classification['hint'] ?? '');
            $retryable = (bool) ($classification['retryable'] ?? false);
            $classificationContext = is_array($classification['context'] ?? null)
                ? $classification['context']
                : [];

            $errorContext = array_merge($classificationContext, [
                'trace' => $e->getTraceAsString(),
                'attempt' => $attempts,
                'max_attempts' => $maxAttempts,
                'error_code' => $errorCode,
                'retryable' => $retryable,
                'hint' => $hint,
            ]);

            $this->jobs->addEvent($jobId, 'error', $e->getMessage(), $errorContext);
            if (($job['current_step'] ?? '') === 'import_validate_package') {
                do_action('lift_teleport_import_preflight_failed', $job, $errorContext);
            }

            do_action('lift_teleport_job_error_classified', $job, $errorContext, $e);

            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $payload['last_error'] = [
                'at' => gmdate(DATE_ATOM),
                'step' => (string) ($job['current_step'] ?? ''),
                'error_code' => $errorCode,
                'retryable' => $retryable,
                'hint' => $hint,
                'message' => $e->getMessage(),
            ];
            if ($classificationContext !== []) {
                $payload['last_error']['context'] = $classificationContext;
            }

            if ($attempts < $maxAttempts) {
                $this->jobs->update($jobId, [
                    'status' => JobRepository::STATUS_PENDING,
                    'attempts' => $attempts,
                    'payload' => $payload,
                    'worker_heartbeat_at' => current_time('mysql', true),
                    'message' => sprintf(
                        'Step failed (attempt %d/%d, %s): %s',
                        $attempts,
                        $maxAttempts,
                        $retryable ? 'retryable' : 'fatal',
                        $e->getMessage()
                    ),
                ]);
            } else {
                $rollbackFailed = false;
                $rollbackMessage = '';
                $rollbackAttempted = false;

                if (($job['type'] ?? '') === 'import') {
                    try {
                        $rollbackAttempted = true;
                        $rolledBack = $this->attemptRollback($job);
                        $rollbackMessage = $rolledBack ? ' Rollback completed.' : '';
                    } catch (Throwable $rollbackError) {
                        $rollbackFailed = true;
                        $rollbackMessage = sprintf(' Rollback failed: %s', $rollbackError->getMessage());
                        $this->jobs->addEvent($jobId, 'error', 'Rollback failed', ['message' => $rollbackError->getMessage()]);
                    }

                    (new ReadOnlyMode())->disable($jobId);
                    if (! empty($payload['maintenance_enabled'])) {
                        @unlink(ABSPATH . '.maintenance');
                    }
                }

                $message = sprintf('Job failed [%s]: %s.%s', $errorCode, $e->getMessage(), $rollbackMessage);
                if ($hint !== '') {
                    $message .= ' Hint: ' . $hint;
                }
                $payload['rollback_attempted'] = $rollbackAttempted;
                $payload['last_error']['terminal'] = true;

                $terminalStatus = $rollbackFailed ? JobRepository::STATUS_FAILED_ROLLBACK : JobRepository::STATUS_FAILED;
                $freshForCleanup = $this->jobs->get($jobId);
                if (is_array($freshForCleanup)) {
                    $this->applyTerminalCleanup($freshForCleanup, $terminalStatus, 'runner_failed');
                }

                $this->jobs->update($jobId, ['payload' => $payload]);
                $this->jobs->markFailed($jobId, $message, $rollbackFailed);

                $job = $this->jobs->get($jobId);
                do_action('lift_teleport_job_failed', $job, $e);
            }
        } finally {
            $this->jobs->releaseLock($jobId, $owner);
        }

        $job = $this->jobs->get($jobId);
        if ($job && in_array($job['status'], [JobRepository::STATUS_RUNNING, JobRepository::STATUS_PENDING], true)) {
            $this->scheduleProcessing();
        }

        return $job;
    }

    public function scheduleProcessing(): void
    {
        if (wp_next_scheduled('lift_teleport_process_jobs')) {
            do_action('lift_teleport_dispatch_worker', [
                'source' => 'schedule_processing',
            ]);
            return;
        }

        $delay = (int) apply_filters('lift_teleport_schedule_processing_delay_seconds', 2);
        $delay = max(1, min(30, $delay));
        wp_schedule_single_event(time() + $delay, 'lift_teleport_process_jobs');

        do_action('lift_teleport_dispatch_worker', [
            'source' => 'schedule_processing',
        ]);
    }

    /**
     * @param array<string,mixed> $job
     */
    private function attemptRollback(array $job): bool
    {
        global $table_prefix;

        $jobId = (int) $job['id'];
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];

        if (empty($payload['rollback_ready'])) {
            return false;
        }

        $this->jobs->addEvent($jobId, 'warning', 'Starting automatic rollback.');

        $dbSnapshot = (string) ($payload['rollback_db_file'] ?? '');
        if ($dbSnapshot !== '' && file_exists($dbSnapshot)) {
            $importer = new DatabaseImporter();
            $state = [];
            do {
                $result = $importer->importIncremental($dbSnapshot, $table_prefix, $table_prefix, $state, 5);
                $state = $result['state'];
            } while (! $result['completed']);
        }

        $contentSnapshot = (string) ($payload['rollback_content_snapshot'] ?? '');
        if ($contentSnapshot !== '' && file_exists($contentSnapshot)) {
            foreach (['plugins', 'themes', 'uploads', 'mu-plugins'] as $dir) {
                Filesystem::deletePath(WP_CONTENT_DIR . '/' . $dir);
            }

            if (CommandRunner::commandExists('tar')) {
                $command = sprintf(
                    'tar -xf %s -C %s',
                    escapeshellarg($contentSnapshot),
                    escapeshellarg(WP_CONTENT_DIR)
                );
                CommandRunner::run($command);
            } else {
                (new CompressionEngine())->extractTar($contentSnapshot, WP_CONTENT_DIR);
            }
        }

        if (! empty($payload['maintenance_enabled'])) {
            @unlink(ABSPATH . '.maintenance');
        }

        $this->jobs->addEvent($jobId, 'warning', 'Rollback completed.');
        return true;
    }

    /**
     * @param array<string,mixed> $job
     */
    private function handleCancellation(array $job): void
    {
        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId <= 0) {
            return;
        }

        if (($job['type'] ?? '') === 'import') {
            (new ReadOnlyMode())->disable($jobId);
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            if (! empty($payload['maintenance_enabled'])) {
                @unlink(ABSPATH . '.maintenance');
            }
        }

        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $message = trim((string) ($payload['cancel_reason_message'] ?? ''));
        if ($message === '') {
            $message = 'Cancelled by user.';
        }

        $this->jobs->update($jobId, [
            'status' => JobRepository::STATUS_CANCELLED,
            'finished_at' => current_time('mysql', true),
            'lock_owner' => null,
            'locked_until' => null,
            'heartbeat_at' => null,
            'worker_heartbeat_at' => null,
            'message' => $message,
        ]);

        $this->jobs->addEvent($jobId, 'warning', $message, [
            'reason_code' => (string) ($payload['cancel_reason_code'] ?? 'user_requested'),
        ]);

        $cancelledJob = $this->jobs->get($jobId);
        if (is_array($cancelledJob)) {
            $this->applyTerminalCleanup($cancelledJob, JobRepository::STATUS_CANCELLED, 'runner_cancelled');
        }
    }

    /**
     * @param array<string,mixed> $job
     */
    private function applyTerminalCleanup(array $job, string $terminalStatus, string $reason): void
    {
        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId <= 0) {
            return;
        }

        $cleanup = (new ArtifactGarbageCollector($this->jobs))->cleanupTerminal($job, $terminalStatus);
        $bytes = (int) ($cleanup['bytes_reclaimed'] ?? 0);
        if ($bytes <= 0) {
            return;
        }

        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $payload = ArtifactGarbageCollector::mergeCleanupPayload($payload, $cleanup, $reason);

        $this->jobs->update($jobId, ['payload' => $payload]);
        $this->jobs->addEvent($jobId, 'info', 'artifact_cleanup_completed', [
            'reason' => $reason,
            'terminal_status' => $terminalStatus,
            'bytes_reclaimed' => $bytes,
            'deleted_paths' => $cleanup['deleted_paths'] ?? [],
        ]);
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $payload
     */
    private function shouldCancelForForegroundTimeout(array $job, array $payload): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $enforced = (bool) apply_filters('lift_teleport_foreground_heartbeat_enforced', false, $job, $payload);
        if (! $enforced) {
            return false;
        }

        if (empty($payload['foreground_required'])) {
            return false;
        }

        $status = (string) ($job['status'] ?? '');
        if (! in_array($status, [JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING], true)) {
            return false;
        }

        $lastSeen = isset($payload['foreground_last_seen_at']) ? strtotime((string) $payload['foreground_last_seen_at']) : false;
        if ($lastSeen === false || $lastSeen <= 0) {
            return false;
        }

        $ttl = (int) apply_filters('lift_teleport_foreground_heartbeat_ttl_seconds', 25, $job);
        $ttl = max(10, $ttl);

        return (time() - $lastSeen) > $ttl;
    }

    /**
     * @return array{
     *   error_code:string,
     *   retryable:bool,
     *   hint:string,
     *   context:array<string,mixed>
     * }
     */
    private function classifyError(Throwable $error): array
    {
        if ($error instanceof StepFailure) {
            return [
                'error_code' => $error->errorCodeName(),
                'retryable' => $error->isRetryable(),
                'hint' => $error->hint(),
                'context' => $error->context(),
            ];
        }

        if ($error instanceof SchemaOutOfSyncException) {
            return [
                'error_code' => $error->errorCodeName(),
                'retryable' => false,
                'hint' => 'Schema mismatch detected in lift_jobs. Auto-repair attempted.',
                'context' => [
                    'schema_context' => $error->context(),
                ],
            ];
        }

        return [
            'error_code' => 'lift_unclassified_error',
            'retryable' => true,
            'hint' => 'Retry the job. If it persists, run with WP-CLI to capture full diagnostics.',
            'context' => [],
        ];
    }
}
