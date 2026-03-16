<?php

declare(strict_types=1);

namespace LiftTeleport\Api\Controllers;

use LiftTeleport\Api\Http\ErrorResponder;
use LiftTeleport\Jobs\JobRepository;
use LiftTeleport\Jobs\JobRunner;
use LiftTeleport\Support\CapabilityPreflight;
use LiftTeleport\Support\CliWorkerLauncher;
use LiftTeleport\Support\DownloadToken;
use LiftTeleport\Support\Environment;
use LiftTeleport\Support\Paths;
use LiftTeleport\Support\SchemaOutOfSyncException;
use WP_Error;
use WP_REST_Request;

final class ControllerContext
{
    public function __construct(
        private JobRepository $jobs,
        private JobRunner $runner
    ) {
    }

    public function jobs(): JobRepository
    {
        return $this->jobs;
    }

    public function runner(): JobRunner
    {
        return $this->runner;
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public function sanitizeJob(array $job, bool $includeSensitive = false): array
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        if (isset($payload['password'])) {
            $payload['password'] = $payload['password'] !== '' ? '***' : '';
        }
        $payload = $this->sanitizePayloadForResponse($payload, $includeSensitive);

        if (! $includeSensitive) {
            return [
                'id' => (int) ($job['id'] ?? 0),
                'type' => (string) ($job['type'] ?? ''),
                'status' => (string) ($job['status'] ?? ''),
                'current_step' => (string) ($job['current_step'] ?? ''),
                'progress' => (float) ($job['progress'] ?? 0),
                'message' => (string) ($job['message'] ?? ''),
                'result' => is_array($job['result'] ?? null) ? $job['result'] : [],
                'created_at' => (string) ($job['created_at'] ?? ''),
                'updated_at' => (string) ($job['updated_at'] ?? ''),
                'started_at' => (string) ($job['started_at'] ?? ''),
                'finished_at' => (string) ($job['finished_at'] ?? ''),
            ];
        }

        $job['payload'] = $payload;

        return $job;
    }

    /**
     * @param array<string,mixed> $job
     */
    public function shouldRunInline(array $job): bool
    {
        $step = (string) ($job['current_step'] ?? '');
        $default = false;

        return (bool) apply_filters('lift_teleport_run_inline_for_step', $default, $job, $step);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function sanitizePayloadForResponse(array $payload, bool $includeSensitive): array
    {
        unset(
            $payload['operator_session_snapshot'],
            $payload['db_dump_state'],
            $payload['db_import_state'],
            $payload['replace_state'],
            $payload['replace_batch_state']
        );

        if (! $includeSensitive) {
            unset($payload['job_token'], $payload['password']);
        }

        if (isset($payload['manifest']['checksums']['files']) && is_array($payload['manifest']['checksums']['files'])) {
            $payload['manifest']['checksums']['files_count'] = count($payload['manifest']['checksums']['files']);
            unset($payload['manifest']['checksums']['files']);
        }

        if (isset($payload['package']) && is_array($payload['package'])) {
            if (isset($payload['package']['manifest']) && is_array($payload['package']['manifest'])) {
                if (isset($payload['package']['manifest']['checksums']['files']) && is_array($payload['package']['manifest']['checksums']['files'])) {
                    $payload['package']['manifest']['checksums']['files_count'] = count($payload['package']['manifest']['checksums']['files']);
                    unset($payload['package']['manifest']['checksums']['files']);
                }

                $manifestSummary = [
                    'format' => (string) ($payload['package']['manifest']['format'] ?? ''),
                    'format_revision' => (string) ($payload['package']['manifest']['format_revision'] ?? ''),
                ];
                $payload['package']['manifest_summary'] = $manifestSummary;
                unset($payload['package']['manifest']);
            }
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function attachCapabilityPreflight(array $payload, string $jobType): array
    {
        $snapshot = (new CapabilityPreflight())->forJobPayload($jobType);
        $payload['capability_preflight'] = $snapshot;
        $payload['execution_plan'] = array_merge(
            is_array($snapshot['decisions'] ?? null) ? $snapshot['decisions'] : [],
            [
                'job_type' => $jobType,
                'selected_at' => gmdate(DATE_ATOM),
            ]
        );

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function recordCapabilityPreflightEvent(int $jobId, array $payload): void
    {
        $preflight = is_array($payload['capability_preflight'] ?? null)
            ? $payload['capability_preflight']
            : [];
        if ($preflight === []) {
            return;
        }

        $tests = is_array($preflight['tests'] ?? null) ? $preflight['tests'] : [];
        $context = [
            'checked_at' => (string) ($preflight['checked_at'] ?? ''),
            'source' => (string) ($preflight['source'] ?? ''),
            'host_profile' => (string) ($preflight['host_profile'] ?? ''),
            'runner' => (string) ($preflight['decisions']['runner'] ?? ''),
            'archive_engine' => (string) ($preflight['decisions']['archive_engine'] ?? ''),
            'risk_level' => (string) ($preflight['decisions']['risk_level'] ?? ''),
            'tar_gzip_status' => (string) ($tests['tar_gzip']['status'] ?? ''),
            'proc_open_status' => (string) ($tests['proc_open']['status'] ?? ''),
            'cli_status' => (string) ($tests['cli']['status'] ?? ''),
        ];

        $this->jobs->addEvent($jobId, 'info', 'capability_preflight_completed', $context);
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $payload
     * @return array{
     *   payload:array<string,mixed>,
     *   schedule_processing:bool,
     *   execution_mode:string,
     *   message:string
     * }
     */
    public function resolveExecutionModeForStart(array $job, array $payload): array
    {
        $jobType = (string) ($job['type'] ?? '');
        $jobId = (int) ($job['id'] ?? 0);
        $plannedRunner = (string) ($payload['execution_plan']['runner'] ?? 'web_runner');
        $existingMode = (string) ($payload['execution_mode_used'] ?? '');

        $payload['diagnostics'] = is_array($payload['diagnostics'] ?? null) ? $payload['diagnostics'] : [];
        $payload['diagnostics']['planned_runner'] = $plannedRunner;

        if ($existingMode === 'cli_worker' && ! empty($payload['cli_worker']['started'])) {
            $payload['diagnostics']['execution_mode'] = 'cli_worker';
            return [
                'payload' => $payload,
                'schedule_processing' => false,
                'execution_mode' => 'cli_worker',
                'message' => 'CLI worker is processing the job.',
            ];
        }

        if (! in_array($jobType, ['export', 'import'], true)) {
            $payload['execution_mode_used'] = 'web_runner';
            $payload['execution_mode_used_at'] = gmdate(DATE_ATOM);
            $payload['execution_fallback_reason'] = 'unsupported_job_type';
            $payload['diagnostics']['execution_mode'] = 'web_runner';
            $this->jobs->addEvent($jobId, 'info', 'execution_mode_selected', [
                'mode' => 'web_runner',
                'reason' => 'unsupported_job_type',
            ]);

            return [
                'payload' => $payload,
                'schedule_processing' => true,
                'execution_mode' => 'web_runner',
                'message' => 'Job scheduled with web runner.',
            ];
        }

        if ($plannedRunner !== 'cli_worker') {
            $payload['execution_mode_used'] = 'web_runner';
            $payload['execution_mode_used_at'] = gmdate(DATE_ATOM);
            $payload['execution_fallback_reason'] = 'execution_plan_web_runner';
            $payload['diagnostics']['execution_mode'] = 'web_runner';
            $this->jobs->addEvent($jobId, 'info', 'execution_mode_selected', [
                'mode' => 'web_runner',
                'reason' => 'execution_plan_web_runner',
            ]);

            return [
                'payload' => $payload,
                'schedule_processing' => true,
                'execution_mode' => 'web_runner',
                'message' => 'Worker is starting...',
            ];
        }

        $timeoutSeconds = (int) apply_filters('lift_teleport_cli_worker_timeout_seconds', 14400, $job);
        $timeoutSeconds = max(60, $timeoutSeconds);
        $launch = (new CliWorkerLauncher())->launch($jobId, (string) ABSPATH, $timeoutSeconds);

        if (! empty($launch['started'])) {
            $payload['execution_mode_used'] = 'cli_worker';
            $payload['execution_mode_used_at'] = gmdate(DATE_ATOM);
            $payload['execution_fallback_reason'] = '';
            $payload['cli_worker'] = $launch;
            $payload['diagnostics']['execution_mode'] = 'cli_worker';
            $payload['diagnostics']['cli_worker_started_at'] = (string) ($launch['started_at'] ?? gmdate(DATE_ATOM));

            $this->jobs->addEvent($jobId, 'info', 'execution_mode_selected', [
                'mode' => 'cli_worker',
                'command_used' => (string) ($launch['command_used'] ?? ''),
                'pid' => (int) ($launch['pid'] ?? 0),
            ]);

            return [
                'payload' => $payload,
                'schedule_processing' => false,
                'execution_mode' => 'cli_worker',
                'message' => 'CLI worker started.',
            ];
        }

        $reason = (string) ($launch['reason'] ?? 'cli_worker_launch_failed');
        $payload['execution_mode_used'] = 'web_runner';
        $payload['execution_mode_used_at'] = gmdate(DATE_ATOM);
        $payload['execution_fallback_reason'] = $reason;
        $payload['cli_worker'] = $launch;
        $payload['diagnostics']['execution_mode'] = 'web_runner';

        $this->jobs->addEvent($jobId, 'warning', 'cli_worker_launch_failed_fallback', [
            'reason' => $reason,
            'message' => (string) ($launch['message'] ?? ''),
            'errors' => $launch['errors'] ?? [],
        ]);
        $this->jobs->addEvent($jobId, 'info', 'execution_mode_selected', [
            'mode' => 'web_runner',
            'reason' => $reason,
        ]);

        return [
            'payload' => $payload,
            'schedule_processing' => true,
            'execution_mode' => 'web_runner',
            'message' => 'CLI worker unavailable, switching to web runner.',
        ];
    }

    public function isBackupInUseByActiveImport(string $backupPath): bool
    {
        $normalizedBackupPath = $this->normalizePath($backupPath);
        if ($normalizedBackupPath === '') {
            return false;
        }

        $activeStatuses = [
            JobRepository::STATUS_UPLOADING,
            JobRepository::STATUS_PENDING,
            JobRepository::STATUS_RUNNING,
        ];

        $recentJobs = $this->jobs->recent(100);
        foreach ($recentJobs as $job) {
            if (! is_array($job) || (string) ($job['type'] ?? '') !== 'import') {
                continue;
            }

            if (! in_array((string) ($job['status'] ?? ''), $activeStatuses, true)) {
                continue;
            }

            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $candidatePaths = [];
            foreach (['source_file', 'import_lift_file', 'upload_path'] as $key) {
                $value = isset($payload[$key]) && is_string($payload[$key]) ? $payload[$key] : '';
                if ($value !== '') {
                    $candidatePaths[] = $value;
                }
            }

            foreach ($candidatePaths as $candidatePath) {
                if ($this->normalizePath($candidatePath) === $normalizedBackupPath) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function validateJobType(int $jobId, string $expectedType)
    {
        $job = $this->jobs->get($jobId);
        if (! $job) {
            return $this->error('lift_job_not_found', 'Job not found.', 404);
        }

        if ((string) ($job['type'] ?? '') !== $expectedType) {
            return $this->error(
                'lift_invalid_job_type',
                sprintf('Job %d is not a %s job.', $jobId, $expectedType),
                409,
                [
                    'expected_type' => $expectedType,
                    'actual_type' => (string) ($job['type'] ?? ''),
                ]
            );
        }

        return $job;
    }

    public function canAccessJobWithToken(WP_REST_Request $request): bool
    {
        $jobId = (int) $request['id'];
        if ($jobId <= 0) {
            return false;
        }

        $job = $this->jobs->get($jobId);
        if (! $job) {
            return false;
        }

        $token = $this->extractJobToken($request);
        if ($token === '') {
            return false;
        }

        $stored = isset($job['job_token']) && is_string($job['job_token'])
            ? (string) $job['job_token']
            : '';
        if ($stored === '') {
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $stored = isset($payload['job_token']) && is_string($payload['job_token']) ? $payload['job_token'] : '';
        }

        return $stored !== '' && hash_equals($stored, $token);
    }

    public function extractJobToken(WP_REST_Request $request): string
    {
        $token = (string) $request->get_param('job_token');
        if ($token !== '') {
            return $token;
        }

        $header = $request->get_header('x-lift-job-token');
        return is_string($header) ? $header : '';
    }

    public function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }

    public function isPathInsideRoot(string $path, string $root): bool
    {
        $path = $this->normalizePath($path);
        $root = $this->normalizePath($root);

        if ($path === '' || $root === '') {
            return false;
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }

    /**
     * @param array<int,string> $roots
     */
    public function isPathInsideAnyRoot(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if (! is_string($root) || $root === '') {
                continue;
            }

            if ($this->isPathInsideRoot($path, $root)) {
                return true;
            }
        }

        return false;
    }

    public function looksLikeLiftPackage(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $head = fread($handle, 4);
        fseek($handle, 0);
        $firstSix = fread($handle, 6);
        fseek($handle, 257);
        $tarMagic = fread($handle, 5);
        fclose($handle);

        if (! is_string($head) || $head === '') {
            return false;
        }

        if ($firstSix === 'LIFT1E') {
            return true;
        }

        if ($head === "\x28\xB5\x2F\xFD" || substr($head, 0, 2) === "\x1F\x8B") {
            return true;
        }

        return $tarMagic === 'ustar';
    }

    public function guardSchemaHealth(): ?WP_Error
    {
        $schema = $this->jobs->inspectSchema(true);
        if (! empty($schema['health'])) {
            return null;
        }

        $repair = $this->jobs->repairSchemaIfNeeded();
        $schema = $this->jobs->inspectSchema(true);
        if (! empty($schema['health'])) {
            return null;
        }

        return $this->error(
            'lift_schema_out_of_sync',
            'Schema mismatch detected in lift_jobs. Auto-repair attempted.',
            503,
            [
                'retryable' => false,
                'hint' => 'Check Diagnostics → schema health and run a schema repair from plugin bootstrap or WP-CLI.',
                'schema' => $schema,
                'repair_result' => $repair,
            ]
        );
    }

    public function guardRuntimeDrift(): ?WP_Error
    {
        $fingerprint = Environment::runtimeFingerprint();
        $canonicalRealpath = (string) ($fingerprint['canonical_plugin_realpath'] ?? '');
        if ($canonicalRealpath === '') {
            return null;
        }

        $matches = (bool) ($fingerprint['runtime_matches_canonical'] ?? true);
        if ($matches) {
            return null;
        }

        $allowDrift = (bool) apply_filters('lift_teleport_allow_runtime_drift', false, $fingerprint);
        if ($allowDrift) {
            return null;
        }

        return $this->error(
            'lift_runtime_drift_detected',
            'Runtime plugin path differs from canonical build path.',
            409,
            [
                'retryable' => false,
                'hint' => 'Sync canonical and runtime plugin trees before running migration jobs.',
                'runtime_fingerprint' => $fingerprint,
            ]
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function attachRuntimeFingerprint(array $payload, string $source): array
    {
        $payload['runtime_fingerprint'] = Environment::runtimeFingerprint();
        $payload['runtime_fingerprint_source'] = $source;
        return $payload;
    }

    public function schemaErrorResponse(SchemaOutOfSyncException $error): WP_Error
    {
        return $this->error(
            $error->errorCodeName(),
            $error->getMessage(),
            503,
            [
                'retryable' => false,
                'hint' => 'Schema mismatch detected in lift_jobs. Auto-repair attempted.',
                'schema_context' => $error->context(),
            ]
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    public function error(string $code, string $message, int $status, array $context = []): WP_Error
    {
        return ErrorResponder::error($code, $message, $status, $context);
    }

    public function missingJobError(int $requestedId, string $token = ''): WP_Error
    {
        $active = $this->jobs->getNextRunnable();
        $tokenJob = null;
        if ($token !== '') {
            $tokenJob = $this->jobs->findByToken($token);
        }

        return $this->error(
            'lift_job_not_found',
            'Job not found.',
            404,
            [
                'requested_job_id' => $requestedId,
                'active_job_id' => (int) ($active['id'] ?? 0),
                'active_job_type' => (string) ($active['type'] ?? ''),
                'token_job_id' => (int) ($tokenJob['id'] ?? 0),
                'token_job_type' => (string) ($tokenJob['type'] ?? ''),
                'token_job_status' => (string) ($tokenJob['status'] ?? ''),
                'retryable' => true,
                'hint' => 'Job may have been cleaned/replaced. Refresh diagnostics and resume active job.',
            ]
        );
    }
}
