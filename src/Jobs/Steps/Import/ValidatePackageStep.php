<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Import;

use LiftTeleport\Archive\CompressionEngine;
use LiftTeleport\Jobs\Steps\StepFailure;
use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;
use LiftTeleport\Support\CommandRunner;
use Throwable;

final class ValidatePackageStep extends AbstractStep
{
    private CompressionEngine $compression;

    public function __construct($jobs)
    {
        parent::__construct($jobs);
        $this->compression = new CompressionEngine();
    }

    public function key(): string
    {
        return 'import_validate_package';
    }

    public function run(array $job): array
    {
        $jobId = $this->jobId($job);
        try {
            $payload = $this->payload($job);

            Paths::ensureJobDirs($jobId);

            $inputPath = '';

            if (! empty($payload['source_file'])) {
                $sourceFile = (string) $payload['source_file'];
                if (! file_exists($sourceFile)) {
                    throw StepFailure::fatal(
                        'lift_import_source_missing',
                        'Source import file was not found.',
                        'Upload the .lift package again and retry.'
                    );
                }

                $inputPath = Paths::jobInput($jobId) . '/upload.lift';
                Filesystem::copyFile($sourceFile, $inputPath);
            } elseif (! empty($payload['upload_path'])) {
                $inputPath = (string) $payload['upload_path'];
            }

            if ($inputPath === '' || ! file_exists($inputPath)) {
                throw StepFailure::fatal(
                    'lift_import_file_missing',
                    'Import file is missing. Upload a .lift file first.',
                    'Upload the .lift package again and retry.'
                );
            }

            $extension = strtolower((string) pathinfo($inputPath, PATHINFO_EXTENSION));
            if ($extension !== 'lift') {
                throw StepFailure::fatal(
                    'lift_import_invalid_extension',
                    'Import file must use the .lift extension.',
                    'Select a .lift file and retry.'
                );
            }

            $size = filesize($inputPath);
            if (! is_int($size) || $size <= 0) {
                throw StepFailure::fatal(
                    'lift_import_file_empty',
                    'Import file is empty or unreadable.',
                    'Re-upload the package and retry.'
                );
            }

            $compression = $this->compression->detectCompression($inputPath);
            if ($compression === CompressionEngine::ALGO_NONE && ! $this->looksLikeCompleteTarContainer($inputPath, $size)) {
                throw StepFailure::fatal(
                    'lift_import_archive_truncated',
                    'Import package appears incomplete or corrupted.',
                    'Re-upload or re-download the .lift file and retry.'
                );
            }

            if ($compression === CompressionEngine::ALGO_ZSTD) {
                $capabilities = is_array($payload['capability_preflight'] ?? null)
                    ? $payload['capability_preflight']
                    : [];
                $tarDetails = is_array($capabilities['tests']['tar_gzip']['details'] ?? null)
                    ? $capabilities['tests']['tar_gzip']['details']
                    : [];
                $zstdFromPreflight = array_key_exists('zstd_binary', $tarDetails)
                    ? (bool) $tarDetails['zstd_binary']
                    : null;
                $zstdAvailable = $zstdFromPreflight ?? CommandRunner::commandExists('zstd');

                if (! $zstdAvailable) {
                    throw StepFailure::fatal(
                        'lift_import_zstd_unsupported',
                        'This package uses zstd compression, but zstd is not available on this host.',
                        'Re-export the source using gzip/none compression, or enable zstd on the destination host.'
                    );
                }
            }

            $payload['import_lift_file'] = $inputPath;
            $payload['import_lift_size'] = $size;
            $payload['import_compression'] = $compression;
            $payload['integrity_stage'] = 'validated_container';

            return [
                'status' => 'next',
                'next_step' => 'import_precheck_space',
                'payload' => $payload,
                'progress' => 10,
                'message' => 'Import package validated.',
            ];
        } catch (StepFailure $failure) {
            do_action('lift_teleport_import_preflight_failed', $job, [
                'step' => $this->key(),
                'error_code' => $failure->errorCodeName(),
                'hint' => $failure->hint(),
                'retryable' => $failure->isRetryable(),
            ]);
            throw $failure;
        } catch (Throwable $error) {
            $failure = StepFailure::fatal(
                'lift_import_validate_failed',
                $error->getMessage() !== '' ? $error->getMessage() : 'Import validation failed.',
                'Retry the upload and verify that the package is complete.',
                [],
                $error
            );

            do_action('lift_teleport_import_preflight_failed', $job, [
                'step' => $this->key(),
                'error_code' => $failure->errorCodeName(),
                'hint' => $failure->hint(),
                'retryable' => $failure->isRetryable(),
            ]);

            throw $failure;
        }
    }

    private function looksLikeCompleteTarContainer(string $path, int $size): bool
    {
        if ($size < 1024 || ($size % 512) !== 0) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            if (@fseek($handle, -1024, SEEK_END) !== 0) {
                return false;
            }

            $tail = fread($handle, 1024);
            if (! is_string($tail) || strlen($tail) !== 1024) {
                return false;
            }

            return trim($tail, "\0") === '';
        } finally {
            fclose($handle);
        }
    }
}
