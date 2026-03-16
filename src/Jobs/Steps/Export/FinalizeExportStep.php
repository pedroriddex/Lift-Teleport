<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Export;

use LiftTeleport\Backups\BackupRepository;
use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Support\DownloadToken;
use Throwable;

final class FinalizeExportStep extends AbstractStep
{
    public function key(): string
    {
        return 'export_finalize';
    }

    public function run(array $job): array
    {
        $jobId = $this->jobId($job);
        $payload = $this->payload($job);
        $package = is_array($payload['package'] ?? null) ? $payload['package'] : [];

        $expires = time() + DAY_IN_SECONDS;
        $token = DownloadToken::generate($jobId, $expires);

        $absoluteUrl = add_query_arg(
            [
                'token' => $token,
                'expires' => $expires,
            ],
            rest_url('lift/v1/jobs/' . $jobId . '/download')
        );

        $parsed = wp_parse_url($absoluteUrl);
        $relativePath = isset($parsed['path']) ? (string) $parsed['path'] : '/wp-json/lift/v1/jobs/' . $jobId . '/download';
        if (! empty($parsed['query'])) {
            $relativePath .= '?' . (string) $parsed['query'];
        }

        $result = [
            'download_url' => esc_url_raw($absoluteUrl),
            'download_path' => $relativePath,
            'download_expires' => gmdate(DATE_ATOM, $expires),
            'package_size_bytes' => (int) ($package['size'] ?? 0),
            'compression' => (string) ($package['compression'] ?? 'unknown'),
            'encrypted' => (bool) ($package['encrypted'] ?? false),
            'format' => (string) ($package['format'] ?? 'lift-v1'),
            'format_revision' => (string) ($package['format_revision'] ?? ''),
            'checksum_verified' => true,
            'file' => (string) ($package['file'] ?? ''),
            'filename' => (string) ($package['filename'] ?? ''),
        ];

        $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
        $saveForBackup = ! empty($settings['save_for_backup']);
        $result['backup_saved'] = false;
        $result['backup_id'] = '';

        if ($saveForBackup) {
            $exportFile = (string) ($result['file'] ?? '');
            if ($exportFile !== '' && file_exists($exportFile) && is_readable($exportFile)) {
                try {
                    $backup = (new BackupRepository())->createFromExportFile($exportFile, [
                        'filename' => (string) ($result['filename'] ?? basename($exportFile)),
                        'created_by' => (int) ($payload['requested_by'] ?? 0),
                        'source_job_id' => $jobId,
                        'encrypted' => (bool) ($result['encrypted'] ?? false),
                    ]);

                    $result['backup_saved'] = true;
                    $result['backup_id'] = (string) ($backup['id'] ?? '');
                    $this->jobs->addEvent($jobId, 'info', 'backup_saved', [
                        'backup_id' => $result['backup_id'],
                        'backup_filename' => (string) ($backup['filename'] ?? ''),
                        'backup_size_bytes' => (int) ($backup['size_bytes'] ?? 0),
                    ]);
                } catch (Throwable $error) {
                    $result['backup_saved'] = false;
                    $result['backup_error'] = $error->getMessage();
                    $this->jobs->addEvent($jobId, 'warning', 'backup_save_failed', [
                        'error_code' => 'backup_save_failed',
                        'error_message' => $error->getMessage(),
                    ]);
                }
            } else {
                $this->jobs->addEvent($jobId, 'warning', 'backup_save_skipped', [
                    'reason' => 'export_file_missing',
                ]);
            }
        }

        $payload['download'] = [
            'token' => $token,
            'expires' => $expires,
            'url' => $absoluteUrl,
            'path' => $relativePath,
        ];

        return [
            'status' => 'done',
            'payload' => $payload,
            'result' => $result,
            'progress' => 100,
            'message' => 'Export completed.',
        ];
    }
}
