<?php

declare(strict_types=1);

namespace LiftTeleport\Support;

use RuntimeException;
use Throwable;

final class SchemaOutOfSyncException extends RuntimeException
{
    private string $errorCodeName;

    /**
     * @var array<string,mixed>
     */
    private array $context;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        string $message = 'Schema mismatch detected in lift_jobs. Auto-repair attempted.',
        array $context = [],
        string $errorCodeName = 'lift_schema_out_of_sync',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCodeName = $errorCodeName;
        $this->context = $context;
    }

    public function errorCodeName(): string
    {
        return $this->errorCodeName;
    }

    /**
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}

