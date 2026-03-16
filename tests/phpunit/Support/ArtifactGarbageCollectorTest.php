<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Support;

use LiftTeleport\Support\ArtifactGarbageCollector;
use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;
use PHPUnit\Framework\TestCase;

final class ArtifactGarbageCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Paths::ensureBaseDirs();
    }

    protected function tearDown(): void
    {
        Filesystem::deletePath(Paths::jobsRoot() . '/301');
        Filesystem::deletePath(Paths::jobsRoot() . '/302');
        parent::tearDown();
    }

    public function testStorageSummaryReportsBytesForJobsRoot(): void
    {
        $jobRoot = Paths::jobsRoot() . '/301/workspace';
        Filesystem::ensureDirectory($jobRoot);
        file_put_contents($jobRoot . '/blob.bin', str_repeat('A', 4096));

        $summary = (new ArtifactGarbageCollector())->storageSummary();

        self::assertArrayHasKey('lift_data_bytes', $summary);
        self::assertArrayHasKey('lift_jobs_bytes', $summary);
        self::assertArrayHasKey('by_type', $summary);
        self::assertGreaterThan(0, (int) $summary['lift_data_bytes']);
        self::assertGreaterThan(0, (int) $summary['lift_jobs_bytes']);
        self::assertGreaterThan(0, (int) ($summary['by_type']['orphan'] ?? 0));
    }

    public function testMergeCleanupPayloadAccumulatesTotals(): void
    {
        $payload = [];
        $cleanupA = [
            'bytes_reclaimed' => 200,
            'deleted_paths' => ['/tmp/a', '/tmp/b'],
        ];
        $cleanupB = [
            'bytes_reclaimed' => 300,
            'deleted_paths' => ['/tmp/c'],
        ];

        $payload = ArtifactGarbageCollector::mergeCleanupPayload($payload, $cleanupA, 'first');
        $payload = ArtifactGarbageCollector::mergeCleanupPayload($payload, $cleanupB, 'second');

        self::assertSame(500, (int) ($payload['storage_cleanup']['bytes_reclaimed_total'] ?? 0));
        self::assertSame(300, (int) ($payload['storage_cleanup']['last_bytes_reclaimed'] ?? 0));
        self::assertSame('second', (string) ($payload['storage_cleanup']['reason'] ?? ''));
    }
}
