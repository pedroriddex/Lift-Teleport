<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps;

use LiftTeleport\Jobs\JobRepository;

abstract class AbstractStep implements StepInterface
{
    protected JobRepository $jobs;

    public function __construct(JobRepository $jobs)
    {
        $this->jobs = $jobs;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    protected function payload(array $job): array
    {
        $payload = $job['payload'] ?? [];
        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string,mixed> $job
     */
    protected function jobId(array $job): int
    {
        return (int) ($job['id'] ?? 0);
    }

    /**
     * @param array<string,mixed> $context
     */
    protected function fail(
        string $errorCode,
        string $message,
        bool $retryable = false,
        string $hint = '',
        array $context = [],
        ?\Throwable $previous = null
    ): StepFailure {
        return new StepFailure($errorCode, $message, $retryable, $hint, $context, $previous);
    }
}
