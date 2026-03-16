<?php

declare(strict_types=1);

namespace LiftTeleport\Api\Controllers;

use LiftTeleport\Backups\BackupRepository;
use LiftTeleport\Jobs\JobRepository;
use LiftTeleport\Jobs\Steps\Factory;
use LiftTeleport\Settings\SettingsRepository;
use LiftTeleport\Support\Paths;
use LiftTeleport\Support\SchemaOutOfSyncException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

final class BackupsController
{
    public function __construct(private ControllerContext $ctx)
    {
    }

    public function getBackups(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, (int) $request->get_param('page'));
        $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?: 20)));

        $response = (new BackupRepository())->paginatedList($page, $perPage);
        $response['items'] = array_map(static function (array $item): array {
            unset($item['path']);
            return $item;
        }, is_array($response['items'] ?? null) ? $response['items'] : []);

        return new WP_REST_Response($response);
    }

    public function downloadBackup(WP_REST_Request $request)
    {
        $id = (string) $request['id'];
        $backup = (new BackupRepository())->find($id);
        if (! is_array($backup)) {
            return $this->ctx->error('lift_backup_not_found', 'Backup not found.', 404);
        }

        $file = isset($backup['path']) && is_string($backup['path']) ? $backup['path'] : '';
        if ($file === '' || ! file_exists($file) || ! is_readable($file)) {
            return $this->ctx->error('lift_backup_file_missing', 'Backup file not found.', 404);
        }
        if (! $this->ctx->isPathInsideRoot($file, Paths::backupsRoot())) {
            return $this->ctx->error('lift_backup_forbidden_path', 'Backup path is outside the allowed backups directory.', 403);
        }

        (new BackupRepository())->markDownloaded($id);

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
            return $this->ctx->error('lift_backup_stream_failed', 'Unable to stream backup file.', 500);
        }

        fpassthru($stream);
        fclose($stream);
        exit;
    }

    public function deleteBackup(WP_REST_Request $request)
    {
        $id = (string) $request['id'];
        $repo = new BackupRepository();
        $backup = $repo->find($id);

        if (! is_array($backup)) {
            return $this->ctx->error('lift_backup_not_found', 'Backup not found.', 404);
        }

        $path = isset($backup['path']) && is_string($backup['path']) ? $backup['path'] : '';
        if ($path !== '' && $this->ctx->isBackupInUseByActiveImport($path)) {
            return $this->ctx->error(
                'lift_backup_in_use',
                'Backup cannot be deleted while an import is using it.',
                409
            );
        }

        $deleted = $repo->delete($id);
        if (! $deleted) {
            return $this->ctx->error('lift_backup_delete_failed', 'Unable to delete backup.', 500);
        }

        return new WP_REST_Response(['deleted' => true, 'id' => $id]);
    }

    public function importBackup(WP_REST_Request $request)
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

            $id = (string) $request['id'];
            $backup = (new BackupRepository())->find($id);
            if (! is_array($backup)) {
                return $this->ctx->error('lift_backup_not_found', 'Backup not found.', 404);
            }

            $file = isset($backup['path']) && is_string($backup['path']) ? $backup['path'] : '';
            if ($file === '' || ! file_exists($file)) {
                return $this->ctx->error('lift_backup_file_missing', 'Backup file not found.', 404);
            }

            $jobToken = wp_generate_password(64, false, false);
            $requestedBy = get_current_user_id();
            $settings = (new SettingsRepository())->forJobPayload();

            $payload = [
                'password' => trim((string) $request->get_param('password')),
                'source_file' => $file,
                'import_lift_file' => $file,
                'import_lift_size' => max(0, (int) filesize($file)),
                'requested_by' => $requestedBy,
                'job_token' => $jobToken,
                'settings' => $settings,
                'operator_session_continuity' => false,
                'operator_session_restored' => false,
                'operator_session_restore_error' => null,
                'backup_id' => $id,
            ];
            $payload = $this->ctx->attachCapabilityPreflight($payload, 'import');
            $payload = $this->ctx->attachRuntimeFingerprint($payload, 'rest_backup_import');

            $job = $this->ctx->jobs()->create('import', $payload, Factory::initialStepForType('import'), JobRepository::STATUS_PENDING);
            Paths::ensureJobDirs((int) $job['id']);

            $jobPayload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
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
                'lift_backup_import_failed',
                'Unable to start import from backup.',
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
