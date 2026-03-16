<?php

declare(strict_types=1);

namespace LiftTeleport\Api\Controllers;

use LiftTeleport\Api\Http\RequestValidator;
use LiftTeleport\Import\ReadOnlyMode;
use LiftTeleport\Jobs\JobRepository;
use LiftTeleport\Jobs\Steps\Factory;
use LiftTeleport\Settings\SettingsRepository;
use LiftTeleport\Support\ArtifactGarbageCollector;
use LiftTeleport\Support\DownloadToken;
use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;
use LiftTeleport\Support\SchemaOutOfSyncException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

final class JobsController
{
    public function __construct(private ControllerContext $ctx)
    {
    }

    public function canReadJob(WP_REST_Request $request): bool
    {
        if ($this->ctx->canManage()) {
            return true;
        }

        if ($this->ctx->canAccessJobWithToken($request)) {
            return true;
        }

        $token = $this->ctx->extractJobToken($request);
        if ($token === '') {
            return false;
        }

        return $this->ctx->jobs()->findByToken($token) !== null;
    }

    public function canCancelJob(WP_REST_Request $request): bool
    {
        if ($this->ctx->canManage()) {
            return true;
        }

        $job = $this->ctx->jobs()->get((int) $request['id']);
        if (! $job) {
            return false;
        }

        $status = (string) ($job['status'] ?? '');
        if (! in_array($status, [JobRepository::STATUS_UPLOADING, JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING], true)) {
            return false;
        }

        return $this->ctx->canAccessJobWithToken($request);
    }

    public function canHeartbeatJob(WP_REST_Request $request): bool
    {
        if ($this->ctx->canManage()) {
            return true;
        }

        $job = $this->ctx->jobs()->get((int) $request['id']);
        if (! $job) {
            return false;
        }

        $status = (string) ($job['status'] ?? '');
        if (! in_array($status, [JobRepository::STATUS_UPLOADING, JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING], true)) {
            return false;
        }

        return $this->ctx->canAccessJobWithToken($request);
    }

    public function createExportJob(WP_REST_Request $request)
    {
        try {
            if (($schemaError = $this->ctx->guardSchemaHealth()) instanceof \WP_Error) {
                return $schemaError;
            }
            if (($runtimeError = $this->ctx->guardRuntimeDrift()) instanceof \WP_Error) {
                return $runtimeError;
            }

            $staleWindow = (int) apply_filters('lift_teleport_active_job_create_stale_seconds', 120);
            $this->ctx->jobs()->cleanupStaleActiveJobs($staleWindow);

            if ($this->ctx->jobs()->hasActiveJob()) {
                return $this->ctx->error('lift_active_job', 'Another job is already in progress.', 409);
            }

            $password = trim((string) $request->get_param('password'));
            $jobToken = wp_generate_password(64, false, false);
            $settings = (new SettingsRepository())->forJobPayload();

            $payload = [
                'password' => $password,
                'requested_by' => get_current_user_id(),
                'job_token' => $jobToken,
                'settings' => $settings,
            ];
            $payload = $this->ctx->attachCapabilityPreflight($payload, 'export');
            $payload = $this->ctx->attachRuntimeFingerprint($payload, 'rest_export');

            $job = $this->ctx->jobs()->create('export', $payload, Factory::initialStepForType('export'));
            Paths::ensureJobDirs((int) $job['id']);
            $jobPayload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $jobPayload['job_identity'] = [
                'id' => (int) $job['id'],
                'type' => (string) ($job['type'] ?? 'export'),
                'created_at' => (string) ($job['created_at'] ?? ''),
            ];
            $this->ctx->jobs()->update((int) $job['id'], ['payload' => $jobPayload]);
            $this->ctx->recordCapabilityPreflightEvent((int) $job['id'], $jobPayload);
            $this->ctx->runner()->scheduleProcessing();
            $job = $this->ctx->jobs()->get((int) $job['id']) ?: $job;

            return new WP_REST_Response([
                'job' => $this->ctx->sanitizeJob($job, true),
                'job_token' => $jobToken,
            ], 201);
        } catch (Throwable $error) {
            if ($error instanceof SchemaOutOfSyncException) {
                return $this->ctx->schemaErrorResponse($error);
            }

            return $this->ctx->error(
                'lift_create_export_failed',
                'Unable to create export job.',
                500,
                [
                    'retryable' => true,
                    'hint' => 'Retry in a few seconds.',
                    'details' => $error->getMessage(),
                ]
            );
        }
    }

    public function createImportJob(WP_REST_Request $request)
    {
        try {
            if (($schemaError = $this->ctx->guardSchemaHealth()) instanceof \WP_Error) {
                return $schemaError;
            }
            if (($runtimeError = $this->ctx->guardRuntimeDrift()) instanceof \WP_Error) {
                return $runtimeError;
            }

            $staleWindow = (int) apply_filters('lift_teleport_active_job_create_stale_seconds', 120);
            $this->ctx->jobs()->cleanupStaleActiveJobs($staleWindow);

            if ($this->ctx->jobs()->hasActiveJob()) {
                return $this->ctx->error('lift_active_job', 'Another job is already in progress.', 409);
            }

            $totalBytes = (int) $request->get_param('total_bytes');
            if ($totalBytes <= 0) {
                return $this->ctx->error('lift_invalid_total', 'total_bytes is required and must be > 0.', 400);
            }

            $maxUploadBytes = (int) apply_filters('lift_teleport_max_upload_bytes', 20 * 1024 * 1024 * 1024);
            if ($maxUploadBytes > 0 && $totalBytes > $maxUploadBytes) {
                return $this->ctx->error(
                    'lift_upload_too_large',
                    sprintf('Import file exceeds the configured maximum size (%d bytes).', $maxUploadBytes),
                    413,
                    ['max_upload_bytes' => $maxUploadBytes]
                );
            }

            $fileName = sanitize_file_name((string) $request->get_param('file_name'));
            if ($fileName === '' || ! str_ends_with(strtolower($fileName), '.lift')) {
                return $this->ctx->error('lift_invalid_file_name', 'file_name must end with .lift.', 400);
            }

            $fileSha256 = strtolower(trim((string) $request->get_param('file_sha256')));
            if ($fileSha256 !== '' && preg_match('/^[a-f0-9]{64}$/', $fileSha256) !== 1) {
                return $this->ctx->error('lift_invalid_file_hash', 'file_sha256 must be a valid SHA-256 hash.', 400);
            }

            $jobToken = wp_generate_password(64, false, false);
            $requestedBy = get_current_user_id();
            $settings = (new SettingsRepository())->forJobPayload();

            $payload = [
                'password' => trim((string) $request->get_param('password')),
                'file_name' => $fileName,
                'total_bytes' => $totalBytes,
                'uploaded_bytes' => 0,
                'requested_by' => $requestedBy,
                'job_token' => $jobToken,
                'settings' => $settings,
                'operator_session_continuity' => false,
                'operator_session_restored' => false,
                'operator_session_restore_error' => null,
            ];
            if ($fileSha256 !== '') {
                $payload['total_sha256'] = $fileSha256;
            }
            $payload = $this->ctx->attachCapabilityPreflight($payload, 'import');
            $payload = $this->ctx->attachRuntimeFingerprint($payload, 'rest_import');

            $job = $this->ctx->jobs()->create('import', $payload, Factory::initialStepForType('import'), JobRepository::STATUS_UPLOADING);
            Paths::ensureJobDirs((int) $job['id']);

            $tempPath = Paths::jobInput((int) $job['id']) . '/upload.lift.part';
            $finalPath = Paths::jobInput((int) $job['id']) . '/upload.lift';

            $jobPayload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $jobPayload['upload_temp_path'] = $tempPath;
            $jobPayload['upload_path'] = $finalPath;
            $jobPayload['job_identity'] = [
                'id' => (int) $job['id'],
                'type' => (string) ($job['type'] ?? 'import'),
                'created_at' => (string) ($job['created_at'] ?? ''),
            ];

            $this->ctx->jobs()->update((int) $job['id'], ['payload' => $jobPayload]);
            $this->ctx->recordCapabilityPreflightEvent((int) $job['id'], $jobPayload);
            $this->ctx->runner()->scheduleProcessing();

            $job = $this->ctx->jobs()->get((int) $job['id']);

            return new WP_REST_Response([
                'job' => $this->ctx->sanitizeJob($job ?: [], true),
                'job_token' => $jobToken,
            ], 201);
        } catch (Throwable $error) {
            if ($error instanceof SchemaOutOfSyncException) {
                return $this->ctx->schemaErrorResponse($error);
            }

            return $this->ctx->error(
                'lift_create_import_failed',
                'Unable to create import job.',
                500,
                [
                    'retryable' => true,
                    'hint' => 'Retry in a few seconds.',
                    'details' => $error->getMessage(),
                ]
            );
        }
    }

    public function resolveJob(WP_REST_Request $request)
    {
        try {
            $token = $this->ctx->extractJobToken($request);
            $expectedType = trim((string) $request->get_param('type'));
            $resolvedBy = 'token';
            $job = null;

            if ($token !== '') {
                $job = $this->ctx->jobs()->findByToken($token);
            }

            if (! $job && $this->ctx->canManage()) {
                $requestedBy = get_current_user_id();
                if ($requestedBy > 0 && $expectedType !== '') {
                    $window = (int) apply_filters('lift_teleport_resolve_recent_window_seconds', 900);
                    $job = $this->ctx->jobs()->findRecentByRequester($requestedBy, $expectedType, $window);
                    if ($job) {
                        $resolvedBy = 'recent';
                    }
                }
            }

            if (! $job) {
                return $this->ctx->error(
                    'lift_job_resolve_failed',
                    'Unable to resolve job from token.',
                    404,
                    [
                        'retryable' => true,
                        'hint' => 'Start a new export/import if this browser state is stale.',
                    ]
                );
            }

            if ($expectedType !== '' && (string) ($job['type'] ?? '') !== $expectedType) {
                return $this->ctx->error(
                    'lift_job_resolve_failed',
                    sprintf('Resolved job type mismatch. Expected %s.', $expectedType),
                    409,
                    [
                        'retryable' => true,
                        'resolved_job_id' => (int) $job['id'],
                        'resolved_job_type' => (string) ($job['type'] ?? ''),
                    ]
                );
            }

            if (in_array((string) ($job['status'] ?? ''), [JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING], true)) {
                $this->ctx->runner()->scheduleProcessing();
            }

            return new WP_REST_Response([
                'resolved' => true,
                'resolved_by' => $resolvedBy,
                'job' => $this->ctx->sanitizeJob($job, $this->ctx->canManage()),
            ]);
        } catch (Throwable $error) {
            return $this->ctx->error(
                'lift_job_resolve_failed',
                'Unable to resolve job at this moment.',
                500,
                [
                    'retryable' => true,
                    'hint' => 'Retry in a few seconds.',
                    'details' => $error->getMessage(),
                ]
            );
        }
    }

    public function uploadChunk(WP_REST_Request $request)
    {
        $job = $this->ctx->jobs()->get((int) $request['id']);
        if (! $job) {
            return $this->ctx->error('lift_job_not_found', 'Job not found.', 404);
        }

        if (($job['status'] ?? '') !== JobRepository::STATUS_UPLOADING) {
            return $this->ctx->error('lift_invalid_status', 'Job is not accepting upload chunks.', 409);
        }

        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $offset = (int) $request->get_param('offset');
        $total = (int) ($payload['total_bytes'] ?? 0);

        $files = $request->get_file_params();
        if (! isset($files['chunk']['tmp_name']) || ! is_uploaded_file($files['chunk']['tmp_name'])) {
            return $this->ctx->error('lift_missing_chunk', 'Chunk upload is missing.', 400);
        }

        $tempPath = (string) ($payload['upload_temp_path'] ?? '');
        if ($tempPath === '') {
            return $this->ctx->error('lift_missing_temp_path', 'Temporary upload path is missing.', 500);
        }

        $inputRoot = Paths::jobInput((int) $job['id']);
        if (! $this->ctx->isPathInsideRoot($tempPath, $inputRoot)) {
            return $this->ctx->error(
                'lift_invalid_upload_path',
                'Upload destination path is invalid.',
                400,
                ['hint' => 'Restart the import to regenerate upload paths.']
            );
        }

        Filesystem::ensureDirectory(dirname($tempPath));

        $chunkData = file_get_contents($files['chunk']['tmp_name']);
        if ($chunkData === false) {
            return $this->ctx->error('lift_chunk_read_failed', 'Unable to read uploaded chunk.', 400);
        }

        $chunkHash = strtolower(trim((string) $request->get_param('chunk_sha256')));
        if ($chunkHash !== '') {
            if (preg_match('/^[a-f0-9]{64}$/', $chunkHash) !== 1) {
                return $this->ctx->error('lift_invalid_chunk_hash', 'chunk_sha256 must be a valid SHA-256 hash.', 400);
            }

            $actualChunkHash = hash('sha256', $chunkData);
            if (! hash_equals($chunkHash, (string) $actualChunkHash)) {
                return $this->ctx->error('lift_chunk_hash_mismatch', 'Chunk integrity check failed.', 400);
            }
        }

        $maxChunkBytes = (int) apply_filters('lift_teleport_max_chunk_bytes', 8 * 1024 * 1024);
        if ($maxChunkBytes > 0 && strlen($chunkData) > $maxChunkBytes) {
            return $this->ctx->error(
                'lift_chunk_too_large',
                sprintf('Chunk exceeds allowed size (%d bytes).', $maxChunkBytes),
                413
            );
        }

        $bounds = RequestValidator::validateChunkBounds($offset, strlen($chunkData), $total);
        if (! (bool) ($bounds['valid'] ?? false)) {
            return $this->ctx->error(
                (string) ($bounds['error_code'] ?? 'lift_invalid_chunk'),
                (string) ($bounds['message'] ?? 'Chunk request is invalid.'),
                (int) ($bounds['status'] ?? 400),
                is_array($bounds['context'] ?? null) ? $bounds['context'] : []
            );
        }

        $uploadedBytes = (int) ($payload['uploaded_bytes'] ?? 0);
        $fp = @fopen($tempPath, 'c+b');
        if ($fp === false) {
            return $this->ctx->error('lift_chunk_write_failed', 'Unable to open temporary upload file.', 500);
        }

        if (! @flock($fp, LOCK_EX)) {
            fclose($fp);
            return $this->ctx->error('lift_chunk_lock_failed', 'Unable to lock temporary upload file.', 503, [
                'retryable' => true,
                'hint' => 'Retry the same chunk in a few seconds.',
            ]);
        }

        $stats = fstat($fp);
        $actualSize = is_array($stats) && isset($stats['size']) ? (int) $stats['size'] : 0;

        if ($offset > $actualSize) {
            @flock($fp, LOCK_UN);
            fclose($fp);
            return $this->ctx->error(
                'lift_offset_gap',
                'Chunk offset is ahead of the uploaded cursor.',
                409,
                ['expected_offset' => $actualSize, 'received_offset' => $offset]
            );
        }

        if ($offset < $actualSize) {
            fseek($fp, $offset);
            $existingChunk = fread($fp, strlen($chunkData));
            if (is_string($existingChunk) && strlen($existingChunk) === strlen($chunkData) && hash_equals($existingChunk, $chunkData)) {
                $payload['uploaded_bytes'] = max($uploadedBytes, $actualSize);
                @flock($fp, LOCK_UN);
                fclose($fp);

                $this->ctx->jobs()->update((int) $job['id'], [
                    'payload' => $payload,
                    'message' => 'Uploading package...',
                    'progress' => min(100, ((float) $payload['uploaded_bytes'] / (float) max(1, (int) $payload['total_bytes'])) * 100),
                ]);

                return new WP_REST_Response([
                    'uploaded_bytes' => (int) $payload['uploaded_bytes'],
                    'total_bytes' => (int) ($payload['total_bytes'] ?? 0),
                    'deduplicated' => true,
                ]);
            }

            @flock($fp, LOCK_UN);
            fclose($fp);
            return $this->ctx->error(
                'lift_chunk_conflict',
                'Chunk offset conflicts with uploaded data. Resume from the expected offset.',
                409,
                ['expected_offset' => $actualSize, 'received_offset' => $offset]
            );
        }

        fseek($fp, $offset);
        $written = fwrite($fp, $chunkData);
        fflush($fp);
        $afterStats = fstat($fp);
        $actualAfterSize = is_array($afterStats) && isset($afterStats['size'])
            ? (int) $afterStats['size']
            : max($actualSize, $offset + strlen($chunkData));
        @flock($fp, LOCK_UN);
        fclose($fp);
        if (! is_int($written) || $written !== strlen($chunkData)) {
            return $this->ctx->error('lift_chunk_write_incomplete', 'Unable to persist full chunk payload.', 500);
        }

        $payload['uploaded_bytes'] = max((int) ($payload['uploaded_bytes'] ?? 0), $offset + strlen($chunkData), $actualAfterSize);

        $this->ctx->jobs()->update((int) $job['id'], [
            'payload' => $payload,
            'message' => 'Uploading package...',
            'progress' => min(100, ((float) $payload['uploaded_bytes'] / (float) max(1, (int) $payload['total_bytes'])) * 100),
        ]);

        return new WP_REST_Response([
            'uploaded_bytes' => (int) $payload['uploaded_bytes'],
            'total_bytes' => (int) ($payload['total_bytes'] ?? 0),
        ]);
    }

    public function uploadComplete(WP_REST_Request $request)
    {
        try {
            $job = $this->ctx->jobs()->get((int) $request['id']);
            if (! $job) {
                return $this->ctx->error('lift_job_not_found', 'Job not found.', 404);
            }

            if ((string) ($job['status'] ?? '') !== JobRepository::STATUS_UPLOADING) {
                return $this->ctx->error('lift_invalid_status', 'Job is not accepting upload chunks.', 409);
            }

            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];

            $tempPath = (string) ($payload['upload_temp_path'] ?? '');
            $finalPath = (string) ($payload['upload_path'] ?? '');
            $inputRoot = Paths::jobInput((int) $job['id']);
            if (
                ! $this->ctx->isPathInsideRoot($tempPath, $inputRoot)
                || ! $this->ctx->isPathInsideRoot($finalPath, $inputRoot)
            ) {
                return $this->ctx->error('lift_invalid_upload_path', 'Upload destination path is invalid.', 400, [
                    'hint' => 'Restart the import upload and try again.',
                ]);
            }
            if (! file_exists($tempPath)) {
                return $this->ctx->error('lift_upload_missing', 'Temporary uploaded file not found.', 400);
            }

            $uploaded = (int) filesize($tempPath);
            $expected = (int) ($payload['total_bytes'] ?? 0);

            if ($expected > 0 && $uploaded !== $expected) {
                return $this->ctx->error('lift_upload_size_mismatch', 'Uploaded file size does not match expected total.', 400);
            }

            $expectedSha = strtolower(trim((string) ($payload['total_sha256'] ?? '')));
            if ($expectedSha !== '') {
                $actualSha = hash_file('sha256', $tempPath);
                $actualSha = is_string($actualSha) ? strtolower($actualSha) : '';
                if ($actualSha === '' || ! hash_equals($expectedSha, $actualSha)) {
                    return $this->ctx->error(
                        'lift_upload_hash_mismatch',
                        'Uploaded file hash does not match the expected SHA-256.',
                        400,
                        [
                            'expected_sha256' => $expectedSha,
                            'actual_sha256' => $actualSha,
                            'hint' => 'Re-upload the package and ensure upload is not interrupted.',
                        ]
                    );
                }
                $payload['import_lift_sha256'] = $actualSha;
            }

            if (! $this->ctx->looksLikeLiftPackage($tempPath)) {
                return $this->ctx->error('lift_invalid_package_magic', 'Uploaded file is not a valid .lift package.', 400);
            }

            Filesystem::ensureDirectory(dirname($finalPath));
            @unlink($finalPath);
            if (! @rename($tempPath, $finalPath)) {
                if (! @copy($tempPath, $finalPath)) {
                    return $this->ctx->error('lift_finalize_upload_failed', 'Unable to finalize uploaded file.', 500);
                }
                @unlink($tempPath);
            }

            $payload['upload_path'] = $finalPath;
            $payload['import_lift_file'] = $finalPath;
            $payload['import_lift_size'] = $uploaded;

            $this->ctx->jobs()->update((int) $job['id'], [
                'status' => JobRepository::STATUS_PENDING,
                'payload' => $payload,
                'message' => 'Upload completed.',
                'progress' => 0,
            ]);
            $this->ctx->runner()->scheduleProcessing();

            $job = $this->ctx->jobs()->get((int) $job['id']);
            return new WP_REST_Response(['job' => $this->ctx->sanitizeJob($job ?: [], true)]);
        } catch (Throwable $error) {
            return $this->ctx->error(
                'lift_upload_complete_failed',
                'Unable to finalize upload.',
                500,
                [
                    'retryable' => true,
                    'hint' => 'Retry upload complete or restart the import.',
                    'details' => $error->getMessage(),
                ]
            );
        }
    }

    public function startJob(WP_REST_Request $request)
    {
        try {
            if (($schemaError = $this->ctx->guardSchemaHealth()) instanceof \WP_Error) {
                return $schemaError;
            }

            $job = $this->ctx->jobs()->get((int) $request['id']);
            if (! $job) {
                return $this->ctx->error('lift_job_not_found', 'Job not found.', 404);
            }

            if (! in_array($job['status'], [JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING], true)) {
                return $this->ctx->error('lift_invalid_status', 'Job cannot be started in current status.', 409);
            }

            $jobId = (int) $job['id'];
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $foregroundRequiredParam = $request->get_param('foreground_required');
            $foregroundRequired = false;
            if ($foregroundRequiredParam !== null) {
                $parsedForeground = filter_var($foregroundRequiredParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsedForeground !== null) {
                    $foregroundRequired = (bool) $parsedForeground;
                }
            }

            if ($foregroundRequired) {
                $payload['foreground_required'] = true;
                $payload['foreground_last_seen_at'] = gmdate(DATE_ATOM);
            } else {
                $payload['foreground_required'] = false;
                unset($payload['foreground_last_seen_at']);
            }

            $payload['diagnostics']['start_accepted_at'] = gmdate(DATE_ATOM);
            $payload['diagnostics']['foreground_mode'] = $foregroundRequired ? 'required' : 'background';
            if (isset($payload['foreground_last_seen_at'])) {
                $payload['diagnostics']['foreground_last_seen_at'] = (string) $payload['foreground_last_seen_at'];
            }

            $this->ctx->jobs()->update($jobId, [
                'payload' => $payload,
                'message' => (string) ($job['message'] ?? 'Worker is starting...'),
            ]);

            $this->ctx->jobs()->addEvent($jobId, 'info', 'start_accepted', [
                'foreground_required' => $foregroundRequired,
            ]);

            $execution = $this->ctx->resolveExecutionModeForStart($job, $payload);
            $payload = is_array($execution['payload'] ?? null) ? $execution['payload'] : $payload;
            $scheduleProcessing = ! empty($execution['schedule_processing']);
            $executionMode = (string) ($execution['execution_mode'] ?? ($payload['execution_mode_used'] ?? 'web_runner'));
            $message = (string) ($execution['message'] ?? (string) ($job['message'] ?? 'Worker is starting...'));

            $this->ctx->jobs()->update($jobId, [
                'payload' => $payload,
                'message' => $message,
            ]);

            if ($scheduleProcessing) {
                $this->ctx->runner()->scheduleProcessing();
            }

            $runInline = (bool) apply_filters('lift_teleport_start_job_run_inline', false, $job);
            if ($runInline && $executionMode !== 'cli_worker') {
                $this->ctx->runner()->run($jobId, 2);
            }

            $job = $this->ctx->jobs()->get($jobId);
            if (! $job) {
                return $this->ctx->error(
                    'lift_job_start_lost',
                    'Job start could not be confirmed.',
                    409,
                    [
                        'retryable' => false,
                        'requested_job_id' => $jobId,
                        'hint' => 'Refresh diagnostics and resolve the active job.',
                    ]
                );
            }

            if (
                $scheduleProcessing
                && in_array((string) ($job['status'] ?? ''), [JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING], true)
            ) {
                $this->ctx->runner()->scheduleProcessing();
            }

            return new WP_REST_Response(['job' => $this->ctx->sanitizeJob($job ?: [], true)]);
        } catch (SchemaOutOfSyncException $error) {
            return $this->ctx->schemaErrorResponse($error);
        } catch (Throwable $error) {
            return $this->ctx->error(
                'lift_start_job_failed',
                'Unable to start or continue the job.',
                500,
                [
                    'retryable' => true,
                    'hint' => 'Retry start, or continue with WP-CLI.',
                    'details' => $error->getMessage(),
                ]
            );
        }
    }

    public function heartbeatJob(WP_REST_Request $request)
    {
        try {
            $jobId = (int) $request['id'];
            $job = $this->ctx->jobs()->get($jobId);
            if (! $job) {
                return $this->ctx->error('lift_job_not_found', 'Job not found.', 404);
            }

            $status = (string) ($job['status'] ?? '');
            if (! in_array($status, [JobRepository::STATUS_UPLOADING, JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING], true)) {
                return $this->ctx->error('lift_invalid_status', 'Heartbeat is only allowed for active jobs.', 409);
            }

            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $foregroundRequiredParam = $request->get_param('foreground_required');
            $foregroundRequired = false;
            if ($foregroundRequiredParam !== null) {
                $parsedForeground = filter_var($foregroundRequiredParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsedForeground !== null) {
                    $foregroundRequired = (bool) $parsedForeground;
                }
            }

            if ($foregroundRequired) {
                $payload['foreground_required'] = true;
            } elseif (! isset($payload['foreground_required'])) {
                $payload['foreground_required'] = false;
            }

            $nowAtom = gmdate(DATE_ATOM);
            $payload['foreground_last_seen_at'] = $nowAtom;
            $payload['diagnostics']['foreground_mode'] = ! empty($payload['foreground_required']) ? 'required' : 'background';
            $payload['diagnostics']['foreground_last_seen_at'] = $nowAtom;

            $this->ctx->jobs()->update($jobId, [
                'payload' => $payload,
            ]);

            $lastEventAt = isset($payload['foreground_last_event_at']) ? strtotime((string) $payload['foreground_last_event_at']) : false;
            if ($lastEventAt === false || (time() - $lastEventAt) >= 30) {
                $payload['foreground_last_event_at'] = $nowAtom;
                $this->ctx->jobs()->update($jobId, ['payload' => $payload]);
                $this->ctx->jobs()->addEvent($jobId, 'debug', 'foreground_heartbeat_received', [
                    'foreground_required' => ! empty($payload['foreground_required']),
                    'foreground_last_seen_at' => $nowAtom,
                ]);
            }

            $this->ctx->runner()->scheduleProcessing();

            return new WP_REST_Response([
                'ok' => true,
                'job_id' => $jobId,
                'foreground_last_seen_at' => $nowAtom,
            ]);
        } catch (SchemaOutOfSyncException $error) {
            return $this->ctx->schemaErrorResponse($error);
        } catch (Throwable $error) {
            return $this->ctx->error(
                'lift_heartbeat_failed',
                'Unable to register heartbeat.',
                500,
                [
                    'retryable' => true,
                    'hint' => 'Retry in a few seconds.',
                    'details' => $error->getMessage(),
                ]
            );
        }
    }

    public function cancelJob(WP_REST_Request $request)
    {
        $job = $this->ctx->jobs()->get((int) $request['id']);
        if (! $job) {
            return $this->ctx->error('lift_job_not_found', 'Job not found.', 404);
        }

        $status = (string) ($job['status'] ?? '');
        $isAdmin = $this->ctx->canManage();
        $activeStatuses = [JobRepository::STATUS_UPLOADING, JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING];
        if (! $isAdmin && ! in_array($status, $activeStatuses, true)) {
            return $this->ctx->error('lift_invalid_status', 'Job cannot be cancelled in current status.', 409);
        }

        $reason = sanitize_key((string) $request->get_param('reason'));
        $reasonCode = $reason === 'page_leave' ? 'operator_left_screen' : 'user_requested';
        $cancelMessage = $reason === 'page_leave'
            ? 'Cancelled: operator left Lift screen.'
            : 'Cancelled by user.';

        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $payload['cancel_requested'] = true;
        $payload['cancel_requested_at'] = gmdate(DATE_ATOM);
        $payload['cancel_reason_code'] = $reasonCode;
        $payload['cancel_reason_message'] = $cancelMessage;
        $this->ctx->jobs()->update((int) $job['id'], ['payload' => $payload]);

        if (($job['type'] ?? '') === 'import') {
            (new ReadOnlyMode())->disable((int) $job['id']);
            if (! empty($payload['maintenance_enabled'])) {
                @unlink(ABSPATH . '.maintenance');
            }
        }

        $wasBackgroundActive = in_array($status, [JobRepository::STATUS_RUNNING, JobRepository::STATUS_PENDING], true);
        if (in_array($status, [JobRepository::STATUS_RUNNING, JobRepository::STATUS_PENDING], true)) {
            $this->ctx->jobs()->requestCancel((int) $job['id']);
        } else {
            $this->ctx->jobs()->update((int) $job['id'], [
                'status' => JobRepository::STATUS_CANCELLED,
                'finished_at' => current_time('mysql', true),
                'lock_owner' => null,
                'locked_until' => null,
                'heartbeat_at' => null,
                'worker_heartbeat_at' => null,
                'message' => $cancelMessage,
            ]);
            $this->ctx->jobs()->addEvent((int) $job['id'], 'warning', $cancelMessage, [
                'reason_code' => $reasonCode,
            ]);
        }
        $job = $this->ctx->jobs()->get((int) $job['id']);

        if (! $wasBackgroundActive && is_array($job)) {
            $cleanup = (new ArtifactGarbageCollector($this->ctx->jobs()))->cleanupTerminal($job, JobRepository::STATUS_CANCELLED);
            if ((int) ($cleanup['bytes_reclaimed'] ?? 0) > 0) {
                $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
                $payload = ArtifactGarbageCollector::mergeCleanupPayload($payload, $cleanup, 'routes_cancel');
                $this->ctx->jobs()->update((int) $job['id'], ['payload' => $payload]);
                $this->ctx->jobs()->addEvent((int) $job['id'], 'info', 'artifact_cleanup_completed', [
                    'reason' => 'routes_cancel',
                    'terminal_status' => JobRepository::STATUS_CANCELLED,
                    'bytes_reclaimed' => (int) ($cleanup['bytes_reclaimed'] ?? 0),
                    'deleted_paths' => $cleanup['deleted_paths'] ?? [],
                ]);
                $job = $this->ctx->jobs()->get((int) $job['id']) ?: $job;
            }
        }

        return new WP_REST_Response(['job' => $this->ctx->sanitizeJob($job ?: [], true)]);
    }

    public function getJob(WP_REST_Request $request)
    {
        try {
            $job = $this->ctx->jobs()->get((int) $request['id']);
            if (! $job) {
                return $this->ctx->missingJobError((int) $request['id'], $this->ctx->extractJobToken($request));
            }

            if (in_array((string) ($job['status'] ?? ''), [JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING], true)) {
                if ($this->ctx->shouldRunInline($job)) {
                    $this->ctx->runner()->run((int) $job['id'], 5);
                } else {
                    $this->ctx->runner()->scheduleProcessing();
                }
                $job = $this->ctx->jobs()->get((int) $job['id']) ?: $job;
            }

            return new WP_REST_Response(['job' => $this->ctx->sanitizeJob($job, $this->ctx->canManage())]);
        } catch (SchemaOutOfSyncException $error) {
            return $this->ctx->schemaErrorResponse($error);
        } catch (Throwable $error) {
            return $this->ctx->error(
                'lift_job_status_failed',
                'Unable to fetch job status at this moment.',
                500,
                [
                    'retryable' => true,
                    'hint' => 'Retry in a few seconds or use WP-CLI: wp lift jobs run <job-id> --until-terminal',
                    'details' => $error->getMessage(),
                ]
            );
        }
    }

    public function getEvents(WP_REST_Request $request)
    {
        try {
            $jobId = (int) $request['id'];
            $job = $this->ctx->jobs()->get($jobId);
            if (! $job) {
                return $this->ctx->missingJobError($jobId, $this->ctx->extractJobToken($request));
            }

            if (in_array((string) ($job['status'] ?? ''), [JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING], true)) {
                if ($this->ctx->shouldRunInline($job)) {
                    $this->ctx->runner()->run($jobId, 5);
                } else {
                    $this->ctx->runner()->scheduleProcessing();
                }
            }

            $events = $this->ctx->jobs()->events(
                $jobId,
                max(1, (int) $request->get_param('page')),
                max(1, min(200, (int) $request->get_param('per_page') ?: 50))
            );

            if (! $this->ctx->canManage()) {
                $events = array_map(static function (array $event): array {
                    unset($event['context']);
                    return $event;
                }, $events);
            }

            return new WP_REST_Response(['events' => $events]);
        } catch (SchemaOutOfSyncException $error) {
            return $this->ctx->schemaErrorResponse($error);
        } catch (Throwable $error) {
            return $this->ctx->error(
                'lift_job_events_failed',
                'Unable to fetch job events at this moment.',
                500,
                [
                    'retryable' => true,
                    'hint' => 'Retry in a few seconds.',
                    'details' => $error->getMessage(),
                ]
            );
        }
    }

    public function downloadExport(WP_REST_Request $request)
    {
        $job = $this->ctx->jobs()->get((int) $request['id']);
        if (! $job) {
            return $this->ctx->error('lift_job_not_found', 'Job not found.', 404);
        }

        $token = (string) $request->get_param('token');
        $expires = (int) $request->get_param('expires');
        $isAdminRequest = current_user_can('manage_options');

        if (! $isAdminRequest && ! DownloadToken::verify((int) $job['id'], $expires, $token)) {
            $this->ctx->jobs()->addEvent((int) $job['id'], 'warning', 'Download denied: invalid token.');
            return $this->ctx->error('lift_download_forbidden', 'Invalid or expired download token.', 403);
        }

        $result = is_array($job['result'] ?? null) ? $job['result'] : [];
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];

        $file = (string) ($result['file'] ?? '');
        if ($file === '' && isset($payload['package']['file']) && is_string($payload['package']['file'])) {
            $file = $payload['package']['file'];
        }

        if ($file === '' || ! file_exists($file) || ! is_readable($file)) {
            $this->ctx->jobs()->addEvent((int) $job['id'], 'error', 'Download failed: file missing.', ['file' => $file]);
            return $this->ctx->error('lift_file_missing', 'Export file not found.', 404);
        }

        $allowedRoots = apply_filters(
            'lift_teleport_export_download_allowed_roots',
            [Paths::dataRoot()],
            $job,
            $file
        );
        if (! is_array($allowedRoots)) {
            $allowedRoots = [Paths::dataRoot()];
        }
        if (! $this->ctx->isPathInsideAnyRoot($file, $allowedRoots)) {
            $this->ctx->jobs()->addEvent((int) $job['id'], 'warning', 'Download denied: path outside allowed roots.', ['file' => $file]);
            return $this->ctx->error('lift_file_forbidden_path', 'Export file path is outside allowed download roots.', 403);
        }

        $download = is_array($payload['download'] ?? null) ? $payload['download'] : [];
        $download['downloaded_at'] = gmdate(DATE_ATOM);
        $payload['download'] = $download;
        $this->ctx->jobs()->update((int) $job['id'], ['payload' => $payload]);

        if (function_exists('ini_set')) {
            @ini_set('display_errors', '0');
            @ini_set('zlib.output_compression', 'Off');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        nocache_headers();
        status_header(200);
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . (string) filesize($file));

        $stream = fopen($file, 'rb');
        if ($stream === false) {
            return $this->ctx->error('lift_stream_failed', 'Unable to stream export file.', 500);
        }

        $this->ctx->jobs()->addEvent((int) $job['id'], 'info', 'Download started.', ['file' => $file]);

        fpassthru($stream);
        fclose($stream);
        exit;
    }
}
