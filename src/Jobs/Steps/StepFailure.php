<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps;

use RuntimeException;

final class StepFailure extends RuntimeException
{
    private string $errorCode;

    private bool $retryable;

    private string $hint;

    /**
     * @var array<string,mixed>
     */
    private array $context;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        string $errorCode,
        string $message,
        bool $retryable,
        string $hint = '',
        array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode !== '' ? $errorCode : 'lift_step_failed';
        $this->retryable = $retryable;
        $this->hint = trim($hint);
        $this->context = $context;
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function fatal(
        string $errorCode,
        string $message,
        string $hint = '',
        array $context = [],
        ?\Throwable $previous = null
    ): self {
        return new self($errorCode, $message, false, $hint, $context, $previous);
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function retryable(
        string $errorCode,
        string $message,
        string $hint = '',
        array $context = [],
        ?\Throwable $previous = null
    ): self {
        return new self($errorCode, $message, true, $hint, $context, $previous);
    }

    public function errorCodeName(): string
    {
        return $this->errorCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function hint(): string
    {
        return $this->hint;
    }

    /**
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}

