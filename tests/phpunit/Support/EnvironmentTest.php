<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Support;

use LiftTeleport\Support\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testRuntimeFingerprintContainsRequiredFields(): void
    {
        $fingerprint = Environment::runtimeFingerprint();

        self::assertIsArray($fingerprint);
        self::assertArrayHasKey('plugin_version', $fingerprint);
        self::assertArrayHasKey('build_hash', $fingerprint);
        self::assertArrayHasKey('plugin_realpath', $fingerprint);
        self::assertArrayHasKey('php_version', $fingerprint);
        self::assertArrayHasKey('wp_version', $fingerprint);
        self::assertArrayHasKey('runtime_matches_canonical', $fingerprint);
        self::assertArrayHasKey('captured_at', $fingerprint);
    }

    public function testDiagnosticsExposeCapabilitiesAndRecommendedExecution(): void
    {
        $diagnostics = Environment::diagnostics();

        self::assertIsArray($diagnostics);
        self::assertArrayHasKey('capabilities', $diagnostics);
        self::assertArrayHasKey('recommended_execution', $diagnostics);
        self::assertArrayHasKey('preflight_checked_at', $diagnostics);
        self::assertIsArray($diagnostics['capabilities']);
        self::assertIsArray($diagnostics['recommended_execution']);
    }
}
