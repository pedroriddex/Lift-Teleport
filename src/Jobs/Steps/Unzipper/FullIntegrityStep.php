<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Unzipper;

use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Unzipper\PackageInspector;
use RuntimeException;

final class FullIntegrityStep extends AbstractStep
{
    public function key(): string
    {
        return 'unzipper_full_integrity';
    }

    public function run(array $job): array
    {
        $jobId = $this->jobId($job);
        $payload = $this->payload($job);

        $liftFile = (string) ($payload['unzipper_file'] ?? '');
        if ($liftFile === '' || ! file_exists($liftFile)) {
            throw new RuntimeException('Unzipper file is missing at integrity step.');
        }

        $password = isset($payload['password']) ? (string) $payload['password'] : null;

        $this->jobs->addEvent($jobId, 'info', 'unzipper_full_integrity_started');

        $inspector = new PackageInspector();
        $result = $inspector->fullIntegrity($jobId, $liftFile, $password);
        $fullStatus = (string) ($result['full_status'] ?? 'failed');

        $payload['unzipper'] = array_merge(
            is_array($payload['unzipper'] ?? null) ? $payload['unzipper'] : [],
            [
                'full_status' => $fullStatus,
                'summary' => is_array($result['summary'] ?? null) ? $result['summary'] : [],
                'artifacts' => is_array($result['artifacts'] ?? null) ? $result['artifacts'] : [],
            ]
        );

        if ($fullStatus === 'failed') {
            $message = (string) ($result['full_report']['message'] ?? 'Full integrity verification failed.');
            $this->jobs->addEvent($jobId, 'error', 'unzipper_full_integrity_completed', [
                'status' => $fullStatus,
                'message' => $message,
            ]);
        } else {
            $this->jobs->addEvent($jobId, 'info', 'unzipper_full_integrity_completed', [
                'status' => $fullStatus,
            ]);
        }

        $message = match ($fullStatus) {
            'passed' => 'Full integrity verification completed.',
            'skipped_low_disk' => 'Full integrity verification skipped due to low disk space.',
            default => 'Full integrity verification failed. See diagnostics for details.',
        };

        return [
            'status' => 'next',
            'next_step' => 'unzipper_finalize',
            'payload' => $payload,
            'progress' => 90,
            'message' => $message,
            'metrics' => [
                'full_status' => $fullStatus,
            ],
        ];
    }
}
