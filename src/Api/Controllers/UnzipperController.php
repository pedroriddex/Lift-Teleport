<?php

declare(strict_types=1);

namespace LiftTeleport\Api\Controllers;

use LiftTeleport\Jobs\JobRepository;
use LiftTeleport\Jobs\Steps\Factory;
use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;
use LiftTeleport\Support\SchemaOutOfSyncException;
use LiftTeleport\Unzipper\PackageInspector;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

final class UnzipperController
{
    private JobsController $jobsController;

    public function __construct(private ControllerContext $ctx)
    {
        $this->jobsController = new JobsController($ctx);
    }

    public function createJob(WP_REST_Request $request)
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
                    sprintf('Unzipper file exceeds the configured maximum size (%d bytes).', $maxUploadBytes),
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
            $payload = [
                'password' => trim((string) $request->get_param('password')),
                'file_name' => $fileName,
                'total_bytes' => $totalBytes,
                'uploaded_bytes' => 0,
                'requested_by' => get_current_user_id(),
                'job_token' => $jobToken,
                'unzipper' => [
                    'quick_status' => 'pending',
                    'full_status' => 'pending',
                    'cleanup_on_close' => true,
                ],
            ];
            if ($fileSha256 !== '') {
                $payload['total_sha256'] = $fileSha256;
            }
            $payload = $this->ctx->attachRuntimeFingerprint($payload, 'rest_unzipper');

            $job = $this->ctx->jobs()->create('unzipper', $payload, Factory::initialStepForType('unzipper'), JobRepository::STATUS_UPLOADING);
            Paths::ensureJobDirs((int) $job['id']);

            $tempPath = Paths::jobInput((int) $job['id']) . '/unzipper-upload.lift.part';
            $finalPath = Paths::jobInput((int) $job['id']) . '/unzipper-upload.lift';

            $jobPayload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $jobPayload['upload_temp_path'] = $tempPath;
            $jobPayload['upload_path'] = $finalPath;
            $jobPayload['job_identity'] = [
                'id' => (int) $job['id'],
                'type' => (string) ($job['type'] ?? 'unzipper'),
                'created_at' => (string) ($job['created_at'] ?? ''),
            ];

            $this->ctx->jobs()->update((int) $job['id'], ['payload' => $jobPayload]);
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
                'lift_create_unzipper_failed',
                'Unable to create Unzipper job.',
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
        $job = $this->ctx->validateJobType((int) $request['id'], 'unzipper');
        if ($job instanceof \WP_Error) {
            return $job;
        }

        return $this->jobsController->uploadChunk($request);
    }

    public function uploadComplete(WP_REST_Request $request)
    {
        try {
            $job = $this->ctx->validateJobType((int) $request['id'], 'unzipper');
            if ($job instanceof \WP_Error) {
                return $job;
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
                    'hint' => 'Restart the Unzipper upload and try again.',
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
                $payload['upload_sha256'] = $actualSha;
            }

            if (! (new PackageInspector())->looksLikeLiftPackage($tempPath)) {
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
            $payload['unzipper_file'] = $finalPath;
            $payload['unzipper_size'] = $uploaded;

            $this->ctx->jobs()->update((int) $job['id'], [
                'status' => JobRepository::STATUS_PENDING,
                'payload' => $payload,
                'message' => 'Unzipper upload completed.',
                'progress' => 0,
            ]);
            $this->ctx->runner()->scheduleProcessing();

            $freshJob = $this->ctx->jobs()->get((int) $job['id']);
            return new WP_REST_Response(['job' => $this->ctx->sanitizeJob($freshJob ?: [], true)]);
        } catch (Throwable $error) {
            return $this->ctx->error(
                'lift_upload_complete_failed',
                'Unable to finalize Unzipper upload.',
                500,
                [
                    'retryable' => true,
                    'hint' => 'Retry upload complete or restart Unzipper.',
                    'details' => $error->getMessage(),
                ]
            );
        }
    }

    public function startJob(WP_REST_Request $request)
    {
        $job = $this->ctx->validateJobType((int) $request['id'], 'unzipper');
        if ($job instanceof \WP_Error) {
            return $job;
        }

        return $this->jobsController->startJob($request);
    }

    public function getJob(WP_REST_Request $request)
    {
        $job = $this->ctx->validateJobType((int) $request['id'], 'unzipper');
        if ($job instanceof \WP_Error) {
            return $job;
        }

        return $this->jobsController->getJob($request);
    }

    public function getEntries(WP_REST_Request $request)
    {
        try {
            $job = $this->ctx->validateJobType((int) $request['id'], 'unzipper');
            if ($job instanceof \WP_Error) {
                return $job;
            }

            $cursor = max(0, (int) $request->get_param('cursor'));
            $limit = max(1, min(500, (int) ($request->get_param('limit') ?: 200)));
            $prefix = trim((string) $request->get_param('prefix'));
            $search = trim((string) $request->get_param('search'));

            $entries = (new PackageInspector())->entriesPage((int) $job['id'], $cursor, $limit, $prefix, $search);
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $unzipper = is_array($payload['unzipper'] ?? null) ? $payload['unzipper'] : [];

            return new WP_REST_Response([
                'entries' => $entries['entries'],
                'cursor' => $entries['cursor'],
                'next_cursor' => $entries['next_cursor'],
                'limit' => $entries['limit'],
                'has_more' => $entries['has_more'],
                'quick_status' => (string) ($unzipper['quick_status'] ?? 'pending'),
                'full_status' => (string) ($unzipper['full_status'] ?? 'pending'),
            ]);
        } catch (Throwable $error) {
            return $this->ctx->error(
                'lift_unzipper_entries_failed',
                'Unable to load Unzipper entries.',
                500,
                [
                    'retryable' => true,
                    'hint' => 'Retry in a few seconds.',
                    'details' => $error->getMessage(),
                ]
            );
        }
    }

    public function getDiagnostics(WP_REST_Request $request)
    {
        try {
            $job = $this->ctx->validateJobType((int) $request['id'], 'unzipper');
            if ($job instanceof \WP_Error) {
                return $job;
            }

            $diagnostics = (new PackageInspector())->diagnostics((int) $job['id']);

            return new WP_REST_Response([
                'job' => $this->ctx->sanitizeJob($job, $this->ctx->canManage()),
                'summary' => $diagnostics['summary'] ?? [],
                'quick_report' => $diagnostics['quick_report'] ?? [],
                'full_report' => $diagnostics['full_report'] ?? [],
                'artifacts_present' => $diagnostics['artifacts_present'] ?? [],
            ]);
        } catch (Throwable $error) {
            return $this->ctx->error(
                'lift_unzipper_diagnostics_failed',
                'Unable to load Unzipper diagnostics.',
                500,
                [
                    'retryable' => true,
                    'hint' => 'Retry in a few seconds.',
                    'details' => $error->getMessage(),
                ]
            );
        }
    }

    public function cleanupJob(WP_REST_Request $request)
    {
        try {
            $job = $this->ctx->validateJobType((int) $request['id'], 'unzipper');
            if ($job instanceof \WP_Error) {
                return $job;
            }

            $status = (string) ($job['status'] ?? '');
            if (in_array($status, [JobRepository::STATUS_RUNNING, JobRepository::STATUS_PENDING, JobRepository::STATUS_UPLOADING], true)) {
                return $this->ctx->error('lift_unzipper_cleanup_active', 'Cannot cleanup Unzipper artifacts while the job is active.', 409);
            }

            (new PackageInspector())->cleanup((int) $job['id']);

            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $payload['unzipper'] = is_array($payload['unzipper'] ?? null) ? $payload['unzipper'] : [];
            unset($payload['unzipper']['artifacts']);
            $payload['unzipper']['cleaned_at'] = gmdate(DATE_ATOM);
            $payload['unzipper']['cleanup_reason'] = 'panel_closed';

            $this->ctx->jobs()->update((int) $job['id'], [
                'payload' => $payload,
                'message' => 'Unzipper artifacts cleaned.',
            ]);
            $this->ctx->jobs()->addEvent((int) $job['id'], 'info', 'unzipper_cleanup_completed', [
                'reason' => 'panel_closed',
            ]);

            $fresh = $this->ctx->jobs()->get((int) $job['id']);
            return new WP_REST_Response([
                'cleaned' => true,
                'job' => $this->ctx->sanitizeJob($fresh ?: $job, true),
            ]);
        } catch (Throwable $error) {
            return $this->ctx->error(
                'lift_unzipper_cleanup_failed',
                'Unable to cleanup Unzipper artifacts.',
                500,
                [
                    'retryable' => true,
                    'hint' => 'Retry in a few seconds.',
                    'details' => $error->getMessage(),
                ]
            );
        }
    }
}
