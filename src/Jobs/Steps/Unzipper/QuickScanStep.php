<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Unzipper;

use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Unzipper\PackageInspector;
use RuntimeException;

final class QuickScanStep extends AbstractStep
{
    public function key(): string
    {
        return 'unzipper_quick_scan';
    }

    public function run(array $job): array
    {
        $jobId = $this->jobId($job);
        $payload = $this->payload($job);

        $liftFile = (string) ($payload['unzipper_file'] ?? '');
        if ($liftFile === '' || ! file_exists($liftFile)) {
            throw new RuntimeException('Unzipper file is missing at quick scan step.');
        }

        $password = isset($payload['password']) ? (string) $payload['password'] : null;

        $inspector = new PackageInspector();
        $result = $inspector->quickScan($jobId, $liftFile, $password);

        $payload['unzipper'] = array_merge(
            is_array($payload['unzipper'] ?? null) ? $payload['unzipper'] : [],
            [
                'quick_status' => (string) ($result['quick_status'] ?? 'passed'),
                'full_status' => 'pending',
                'summary' => is_array($result['summary'] ?? null) ? $result['summary'] : [],
                'artifacts' => is_array($result['artifacts'] ?? null) ? $result['artifacts'] : [],
                'cleanup_on_close' => true,
            ]
        );

        $this->jobs->addEvent($jobId, 'info', 'unzipper_quick_scan_completed', [
            'quick_status' => (string) ($result['quick_status'] ?? 'passed'),
            'entry_counts' => $payload['unzipper']['summary']['entry_counts'] ?? [],
        ]);

        return [
            'status' => 'next',
            'next_step' => 'unzipper_full_integrity',
            'payload' => $payload,
            'progress' => 45,
            'message' => 'Quick package scan completed.',
            'metrics' => [
                'quick_status' => (string) ($result['quick_status'] ?? 'passed'),
            ],
        ];
    }
}
