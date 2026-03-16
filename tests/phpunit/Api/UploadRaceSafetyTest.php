<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Api;

use LiftTeleport\Api\Http\RequestValidator;
use PHPUnit\Framework\TestCase;

final class UploadRaceSafetyTest extends TestCase
{
    public function testRejectsOutOfBoundsChunkWindow(): void
    {
        $result = RequestValidator::validateChunkBounds(1024, 2048, 2048);

        self::assertFalse((bool) $result['valid']);
        self::assertSame('lift_chunk_bounds_exceeded', $result['error_code']);
        self::assertSame(400, $result['status']);
    }

    public function testRejectsNegativeOffset(): void
    {
        $result = RequestValidator::validateChunkBounds(-1, 512, 4096);

        self::assertFalse((bool) $result['valid']);
        self::assertSame('lift_invalid_offset', $result['error_code']);
    }

    public function testAcceptsValidChunkWindow(): void
    {
        $result = RequestValidator::validateChunkBounds(2048, 512, 4096);

        self::assertTrue((bool) $result['valid']);
        self::assertSame('', $result['error_code']);
    }

    public function testPathGuardRequiresJobRootContainment(): void
    {
        self::assertTrue(RequestValidator::isPathInsideRoot(
            '/tmp/lift/jobs/7/input/upload.lift.part',
            '/tmp/lift/jobs/7/input'
        ));

        self::assertFalse(RequestValidator::isPathInsideRoot(
            '/tmp/lift/jobs/8/input/upload.lift.part',
            '/tmp/lift/jobs/7/input'
        ));
    }
}
