<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Unzipper;

use LiftTeleport\Jobs\Steps\AbstractStep;

final class FinalizeStep extends AbstractStep
{
    public function key(): string
    {
        return 'unzipper_finalize';
    }

    public function run(array $job): array
    {
        $payload = $this->payload($job);
        $unzipper = is_array($payload['unzipper'] ?? null) ? $payload['unzipper'] : [];
        $summary = is_array($unzipper['summary'] ?? null) ? $unzipper['summary'] : [];

        $quickStatus = (string) ($unzipper['quick_status'] ?? $summary['quick_status'] ?? 'unknown');
        $fullStatus = (string) ($unzipper['full_status'] ?? $summary['full_status'] ?? 'unknown');

        $payload['unzipper'] = array_merge($unzipper, [
            'quick_status' => $quickStatus,
            'full_status' => $fullStatus,
            'summary' => $summary,
        ]);

        return [
            'status' => 'done',
            'payload' => $payload,
            'progress' => 100,
            'message' => 'Unzipper analysis completed.',
            'result' => [
                'quick_status' => $quickStatus,
                'full_status' => $fullStatus,
                'summary' => $summary,
            ],
        ];
    }
}
