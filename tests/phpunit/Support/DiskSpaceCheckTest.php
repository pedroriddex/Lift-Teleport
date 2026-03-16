<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Support;

use LiftTeleport\Support\DiskSpaceCheck;
use PHPUnit\Framework\TestCase;

final class DiskSpaceCheckTest extends TestCase
{
    public function testEvaluateReturnsMeasuredAvailabilityForValidPath(): void
    {
        $path = sys_get_temp_dir();
        $check = DiskSpaceCheck::evaluate([$path], 1024);

        self::assertArrayHasKey('required', $check);
        self::assertArrayHasKey('available', $check);
        self::assertArrayHasKey('paths', $check);
        self::assertSame($path, $check['path_used']);
        self::assertFalse($check['insufficient']);
        self::assertSame('measured', $check['confidence']);
    }

    public function testEvaluateUsesUnknownConfidenceWhenSpaceCannotBeMeasured(): void
    {
        $check = DiskSpaceCheck::evaluate([''], 1024);

        self::assertNull($check['available']);
        self::assertSame('', $check['path_used']);
        self::assertSame('unknown', $check['confidence']);
        self::assertFalse($check['insufficient']);
    }
}
