<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Export;

use LiftTeleport\Archive\Encryption;
use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Jobs\Steps\StepFailure;
use LiftTeleport\Support\ArtifactGarbageCollector;
use LiftTeleport\Support\DiskSpaceCheck;
use LiftTeleport\Support\Paths;

final class ValidateExportStep extends AbstractStep
{
    public function key(): string
    {
        return 'export_validate';
    }

    public function run(array $job): array
    {
        $payload = $this->payload($job);
        $jobId = $this->jobId($job);

        Paths::ensureJobDirs($jobId);

        $requiredDefault = 64 * 1024 * 1024;
        $requiredFreeBytes = max(1, (int) apply_filters('lift_teleport_export_precheck_required_free_bytes', $requiredDefault));

        $diskPaths = [
            Paths::dataRoot(),
            WP_CONTENT_DIR,
            ABSPATH,
        ];
        $check = DiskSpaceCheck::evaluate($diskPaths, $requiredFreeBytes);
        $availableFreeBytes = $check['available'];
        $payload['export_precheck'] = [
            'required_free_bytes' => $requiredFreeBytes,
            'available_free_bytes' => $availableFreeBytes,
            'confidence' => $check['confidence'],
            'path_used' => $check['path_used'],
        ];

        $this->jobs->addEvent($jobId, 'debug', 'Export disk pre-check', [
            'required_free_bytes' => $requiredFreeBytes,
            'available_free_bytes' => $availableFreeBytes,
            'path_used' => $check['path_used'],
            'confidence' => $check['confidence'],
            'paths' => $check['paths'],
        ]);

        if ($check['confidence'] !== 'measured') {
            $warning = sprintf(
                'Disk free-space check is degraded (%s). Export will continue in best-effort mode.',
                (string) $check['confidence']
            );
            $payload['export_precheck']['warning'] = $warning;
            $this->jobs->addEvent($jobId, 'warning', $warning, [
                'required_free_bytes' => $requiredFreeBytes,
                'available_free_bytes' => $availableFreeBytes,
            ]);
        }

        if ($check['insufficient']) {
            $collector = new ArtifactGarbageCollector($this->jobs);
            $cleanup = $collector->cleanupForDiskPressure($requiredFreeBytes, $jobId);
            $checkAfterCleanup = DiskSpaceCheck::evaluate($diskPaths, $requiredFreeBytes);

            $payload['export_precheck']['cleanup'] = [
                'available_before' => $cleanup['available_before'],
                'available_after' => $checkAfterCleanup['available'],
                'reclaimed_bytes' => (int) ($cleanup['bytes_reclaimed'] ?? 0),
                'deleted_paths' => $cleanup['deleted_paths'] ?? [],
            ];

            if ((int) ($cleanup['bytes_reclaimed'] ?? 0) > 0) {
                $payload = ArtifactGarbageCollector::mergeCleanupPayload($payload, $cleanup, 'export_precheck_space');
            }

            $this->jobs->addEvent($jobId, 'info', 'precheck_cleanup_attempted', [
                'required_free_bytes' => $requiredFreeBytes,
                'available_before' => $cleanup['available_before'],
                'available_after' => $checkAfterCleanup['available'],
                'reclaimed_bytes' => (int) ($cleanup['bytes_reclaimed'] ?? 0),
                'cleaned_jobs' => $cleanup['cleaned_jobs'] ?? [],
            ]);

            $check = $checkAfterCleanup;
            $availableFreeBytes = $check['available'];
            $payload['export_precheck']['available_free_bytes'] = $availableFreeBytes;
            $payload['export_precheck']['confidence'] = $check['confidence'];
            $payload['export_precheck']['path_used'] = $check['path_used'];
        }

        if ($check['insufficient']) {
            $strict = (bool) apply_filters('lift_teleport_export_precheck_strict', false, $check, $job);
            $message = sprintf(
                'Measured free disk space appears insufficient for export pre-check. Required: %d bytes, available: %d bytes.',
                $requiredFreeBytes,
                (int) $availableFreeBytes
            );

            if ($strict) {
                throw StepFailure::fatal(
                    'lift_export_insufficient_disk',
                    $message,
                    'Free disk space on the source host and retry export.',
                    [
                        'required_free_bytes' => $requiredFreeBytes,
                        'available_free_bytes' => (int) $availableFreeBytes,
                    ]
                );
            }

            $payload['export_precheck']['warning'] = $message;
            $this->jobs->addEvent($jobId, 'warning', $message . ' Continuing in best-effort mode.');
        }

        $password = isset($payload['password']) ? (string) $payload['password'] : '';
        if ($password !== '' && ! (new Encryption())->isSupported()) {
            throw StepFailure::fatal(
                'lift_export_sodium_required',
                'Sodium extension is required for encrypted exports.',
                'Disable encryption or enable the sodium extension on this host.'
            );
        }

        $payload['validated_at'] = gmdate(DATE_ATOM);

        return [
            'status' => 'next',
            'next_step' => 'export_dump_database',
            'payload' => $payload,
            'progress' => 5,
            'message' => 'Export validation passed.',
        ];
    }
}
