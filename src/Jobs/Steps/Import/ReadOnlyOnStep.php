<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Import;

use LiftTeleport\Import\ReadOnlyMode;
use LiftTeleport\Jobs\Steps\AbstractStep;

final class ReadOnlyOnStep extends AbstractStep
{
    public function key(): string
    {
        return 'import_readonly_on';
    }

    public function run(array $job): array
    {
        $payload = $this->payload($job);
        $jobId = $this->jobId($job);

        $legacyMaintenance = ABSPATH . '.maintenance';
        if (file_exists($legacyMaintenance)) {
            @unlink($legacyMaintenance);
        }

        (new ReadOnlyMode())->enable($jobId);
        $payload['readonly_enabled'] = true;
        $payload['maintenance_enabled'] = false;
        $payload['readonly_lifecycle'][] = [
            'event' => 'enabled',
            'at' => gmdate(DATE_ATOM),
            'source' => 'import_readonly_on',
        ];

        return [
            'status' => 'next',
            'next_step' => 'import_sync_content',
            'payload' => $payload,
            'progress' => 60,
            'message' => 'Read-only mode enabled.',
        ];
    }
}
