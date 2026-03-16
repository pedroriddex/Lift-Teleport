<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Unzipper;

use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;
use LiftTeleport\Unzipper\PackageInspector;
use RuntimeException;

final class ValidatePackageStep extends AbstractStep
{
    public function key(): string
    {
        return 'unzipper_validate_package';
    }

    public function run(array $job): array
    {
        $jobId = $this->jobId($job);
        $payload = $this->payload($job);

        Paths::ensureJobDirs($jobId);

        $inputPath = '';

        if (! empty($payload['source_file'])) {
            $sourceFile = (string) $payload['source_file'];
            if (! file_exists($sourceFile)) {
                throw new RuntimeException('Source Unzipper file was not found.');
            }

            $inputPath = Paths::jobInput($jobId) . '/unzipper-upload.lift';
            Filesystem::copyFile($sourceFile, $inputPath);
        } elseif (! empty($payload['upload_path'])) {
            $inputPath = (string) $payload['upload_path'];
        }

        if ($inputPath === '' || ! file_exists($inputPath)) {
            throw new RuntimeException('Unzipper file is missing. Upload a .lift file first.');
        }

        $size = @filesize($inputPath);
        if (! is_int($size) || $size <= 0) {
            throw new RuntimeException('Unzipper file is empty or unreadable.');
        }

        $inspector = new PackageInspector();
        if (! $inspector->looksLikeLiftPackage($inputPath)) {
            throw new RuntimeException('Uploaded file is not a valid .lift package.');
        }

        $payload['unzipper_file'] = $inputPath;
        $payload['unzipper_size'] = $size;
        $payload['unzipper'] = [
            'quick_status' => 'pending',
            'full_status' => 'pending',
            'cleanup_on_close' => true,
        ];

        return [
            'status' => 'next',
            'next_step' => 'unzipper_quick_scan',
            'payload' => $payload,
            'progress' => 10,
            'message' => 'Unzipper package validated.',
        ];
    }
}
