<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps;

interface StepInterface
{
    public function key(): string;

    /**
     * @param array<string,mixed> $job
     * @return array{
     *   status:string,
     *   next_step?:string,
     *   payload?:array<string,mixed>,
     *   progress?:float,
     *   message?:string,
     *   result?:array<string,mixed>,
     *   metrics?:array<string,mixed>
     * }
     */
    public function run(array $job): array;
}
