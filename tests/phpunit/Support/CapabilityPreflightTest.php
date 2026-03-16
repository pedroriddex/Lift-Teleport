<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Support;

use LiftTeleport\Support\CapabilityPreflight;
use PHPUnit\Framework\TestCase;

final class CapabilityPreflightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        lift_test_reset_options();
    }

    public function testSelectsCliWorkerWhenCliAndProcAreAvailable(): void
    {
        $service = new CapabilityPreflight([
            'tar_gzip' => static fn (): array => [
                'status' => 'pass',
                'details' => [
                    'gzip_binary' => true,
                    'zstd_binary' => true,
                ],
                'duration_ms' => 10,
            ],
            'proc_open' => static fn (): array => [
                'status' => 'pass',
                'details' => [],
                'duration_ms' => 8,
                'exit_code' => 0,
            ],
            'cli' => static fn (): array => [
                'status' => 'pass',
                'details' => [],
                'duration_ms' => 18,
                'command_used' => 'wp',
                'exit_code' => 0,
            ],
        ]);

        $snapshot = $service->snapshot(true);

        self::assertSame('fresh', $snapshot['source']);
        self::assertSame('cli_worker', $snapshot['decisions']['runner']);
        self::assertSame('shell', $snapshot['decisions']['archive_engine']);
        self::assertSame('full', $snapshot['host_profile']);
        self::assertContains('zstd', $snapshot['decisions']['compression_chain']);
        self::assertContains('gzip', $snapshot['decisions']['compression_chain']);
        self::assertContains('none', $snapshot['decisions']['compression_chain']);
    }

    public function testUsesCacheWhenSnapshotIsStillFresh(): void
    {
        $first = new CapabilityPreflight([
            'tar_gzip' => static fn (): array => [
                'status' => 'pass',
                'details' => ['gzip_binary' => true],
                'duration_ms' => 10,
            ],
            'proc_open' => static fn (): array => [
                'status' => 'pass',
                'details' => [],
                'duration_ms' => 8,
                'exit_code' => 0,
            ],
            'cli' => static fn (): array => [
                'status' => 'pass',
                'details' => [],
                'duration_ms' => 18,
                'command_used' => 'wp',
                'exit_code' => 0,
            ],
        ]);

        $firstSnapshot = $first->snapshot(true);
        self::assertSame('fresh', $firstSnapshot['source']);

        $second = new CapabilityPreflight([
            'tar_gzip' => static fn (): array => [
                'status' => 'fail',
                'details' => ['message' => 'should not be executed'],
                'duration_ms' => 1,
            ],
            'proc_open' => static fn (): array => [
                'status' => 'fail',
                'details' => ['message' => 'should not be executed'],
                'duration_ms' => 1,
            ],
            'cli' => static fn (): array => [
                'status' => 'fail',
                'details' => ['message' => 'should not be executed'],
                'duration_ms' => 1,
            ],
        ]);

        $cachedSnapshot = $second->snapshot(false);
        self::assertSame('cache', $cachedSnapshot['source']);
        self::assertSame('cli_worker', $cachedSnapshot['decisions']['runner']);
    }

    public function testFallsBackToWebRunnerWhenProcOpenFails(): void
    {
        $service = new CapabilityPreflight([
            'tar_gzip' => static fn (): array => [
                'status' => 'degraded',
                'details' => ['gzip_binary' => false],
                'duration_ms' => 12,
            ],
            'proc_open' => static fn (): array => [
                'status' => 'fail',
                'details' => ['message' => 'proc_open disabled'],
                'duration_ms' => 6,
                'exit_code' => 1,
            ],
            'cli' => static fn (): array => [
                'status' => 'pass',
                'details' => [],
                'duration_ms' => 8,
                'command_used' => 'wp',
                'exit_code' => 0,
            ],
        ]);

        $snapshot = $service->snapshot(true);

        self::assertSame('web_runner', $snapshot['decisions']['runner']);
        self::assertSame('php_stream', $snapshot['decisions']['archive_engine']);
        self::assertSame('restricted', $snapshot['host_profile']);
        self::assertSame('degraded', $snapshot['decisions']['risk_level']);
        self::assertContains('none', $snapshot['decisions']['compression_chain']);
    }

    public function testSanitizeCliCandidatesRemovesUnsafeEntries(): void
    {
        $candidates = CapabilityPreflight::sanitizeCliCandidates([
            'wp',
            '/usr/bin/wp',
            'wp; rm -rf /',
            'php /tmp/wp-cli.phar',
            '../wp',
            '$(id)',
            "wp\ncat /etc/passwd",
        ]);

        self::assertSame(['wp', '/usr/bin/wp'], $candidates);
    }
}
