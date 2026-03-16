<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Api;

use LiftTeleport\Api\Routes;
use PHPUnit\Framework\TestCase;

final class UnzipperRoutesTest extends TestCase
{
    public function testRoutesClassExposesUnzipperEndpointsMethods(): void
    {
        self::assertTrue(method_exists(Routes::class, 'createUnzipperJob'));
        self::assertTrue(method_exists(Routes::class, 'uploadUnzipperChunk'));
        self::assertTrue(method_exists(Routes::class, 'uploadUnzipperComplete'));
        self::assertTrue(method_exists(Routes::class, 'startUnzipperJob'));
        self::assertTrue(method_exists(Routes::class, 'getUnzipperJob'));
        self::assertTrue(method_exists(Routes::class, 'getUnzipperEntries'));
        self::assertTrue(method_exists(Routes::class, 'getUnzipperDiagnostics'));
        self::assertTrue(method_exists(Routes::class, 'cleanupUnzipperJob'));
    }
}
