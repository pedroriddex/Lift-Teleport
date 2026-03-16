<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Import;

use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Jobs\Steps\StepFailure;
use LiftTeleport\Support\ArtifactGarbageCollector;
use LiftTeleport\Support\DiskSpaceCheck;
use LiftTeleport\Support\Paths;

final class PrecheckSpaceStep extends AbstractStep
{
    public function key(): string
    {
        return 'import_precheck_space';
    }

    public function run(array $job): array
    {
        $payload = $this->payload($job);
        $jobId = $this->jobId($job);
        $size = (int) ($payload['import_lift_size'] ?? 0);

        if ($size <= 0) {
            throw StepFailure::fatal(
                'lift_import_size_unknown',
                'Unable to determine package size for import pre-check.',
                'Upload the package again before retrying the import.'
            );
        }

        $required = max(1, (int) apply_filters('lift_teleport_import_precheck_required_free_bytes', max($size * 3, 2 * 1024 * 1024 * 1024)));

        $diskPaths = [
            Paths::dataRoot(),
            WP_CONTENT_DIR,
            ABSPATH,
        ];

        $check = DiskSpaceCheck::evaluate($diskPaths, $required);
        $available = $check['available'];
        $this->jobs->addEvent($jobId, 'debug', 'Import disk pre-check', [
            'required_free_bytes' => $required,
            'available_free_bytes' => $available,
            'path_used' => $check['path_used'],
            'confidence' => $check['confidence'],
            'paths' => $check['paths'],
        ]);

        if ($check['insufficient']) {
            $collector = new ArtifactGarbageCollector($this->jobs);
            $cleanup = $collector->cleanupForDiskPressure($required, $jobId);
            $checkAfterCleanup = DiskSpaceCheck::evaluate($diskPaths, $required);

            $payload['precheck_cleanup'] = [
                'required_free_bytes' => $required,
                'available_before' => $cleanup['available_before'],
                'available_after' => $checkAfterCleanup['available'],
                'reclaimed_bytes' => (int) ($cleanup['bytes_reclaimed'] ?? 0),
                'deleted_paths' => $cleanup['deleted_paths'] ?? [],
            ];

            if ((int) ($cleanup['bytes_reclaimed'] ?? 0) > 0) {
                $payload = ArtifactGarbageCollector::mergeCleanupPayload($payload, $cleanup, 'import_precheck_space');
            }

            $this->jobs->addEvent($jobId, 'info', 'precheck_cleanup_attempted', [
                'required_free_bytes' => $required,
                'available_before' => $cleanup['available_before'],
                'available_after' => $checkAfterCleanup['available'],
                'reclaimed_bytes' => (int) ($cleanup['bytes_reclaimed'] ?? 0),
                'cleaned_jobs' => $cleanup['cleaned_jobs'] ?? [],
            ]);

            $check = $checkAfterCleanup;
            $available = $check['available'];
        }

        if ($check['insufficient']) {
            throw StepFailure::fatal(
                'lift_import_insufficient_disk',
                sprintf(
                    'Not enough free disk space for import. Required: %d bytes, available: %d bytes.',
                    $required,
                    (int) $available
                ),
                'Free disk space on the destination host and retry the import.',
                [
                    'required_free_bytes' => $required,
                    'available_free_bytes' => (int) $available,
                ]
            );
        }

        $payload['precheck_required_free_bytes'] = $required;
        $payload['precheck_available_free_bytes'] = $available;

        return [
            'status' => 'next',
            'next_step' => 'import_extract_package',
            'payload' => $payload,
            'progress' => 15,
            'message' => 'Disk space pre-check completed.',
        ];
    }
}
