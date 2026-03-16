<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Archive;

use LiftTeleport\Archive\LiftPackage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LiftPackageTest extends TestCase
{
    public function testAcceptsV2ManifestFormat(): void
    {
        $package = new LiftPackage();
        $assertManifest = \Closure::bind(function (array $manifest): void {
            $this->assertValidManifest($manifest);
        }, $package, LiftPackage::class);

        $assertManifest([
            'format' => 'lift-v2',
        ]);

        self::assertTrue(true);
    }

    public function testRejectsUnsupportedManifestFormat(): void
    {
        $package = new LiftPackage();
        $assertManifest = \Closure::bind(function (array $manifest): void {
            $this->assertValidManifest($manifest);
        }, $package, LiftPackage::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported .lift format');

        $assertManifest([
            'format' => 'lift-v9',
        ]);
    }

    public function testParsesChecksumSidecar(): void
    {
        $package = new LiftPackage();
        $parse = \Closure::bind(function (string $file): array {
            return $this->parseSidecarChecksums($file);
        }, $package, LiftPackage::class);

        $checksumFile = tempnam(sys_get_temp_dir(), 'lift-sha-');
        self::assertIsString($checksumFile);
        file_put_contents(
            $checksumFile,
            "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  db/dump.sql\n" .
            "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb  manifest.json\n"
        );

        $map = $parse($checksumFile);
        self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $map['db/dump.sql'] ?? '');
        self::assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $map['manifest.json'] ?? '');

        @unlink($checksumFile);
    }

    public function testResolvePayloadRootReturnsNestedRootWhenPresent(): void
    {
        $package = new LiftPackage();
        $resolve = \Closure::bind(function (string $root): string {
            return $this->resolvePayloadRoot($root);
        }, $package, LiftPackage::class);

        $base = sys_get_temp_dir() . '/lift-root-' . uniqid('', true);
        $nested = $base . '/payload';
        mkdir($nested, 0777, true);
        file_put_contents($nested . '/manifest.json', '{}');

        $resolved = $resolve($base);
        self::assertSame($nested, $resolved);

        @unlink($nested . '/manifest.json');
        @rmdir($nested);
        @rmdir($base);
    }

    public function testResolvePayloadRootFallsBackToExtractRootWhenMissing(): void
    {
        $package = new LiftPackage();
        $resolve = \Closure::bind(function (string $root): string {
            return $this->resolvePayloadRoot($root);
        }, $package, LiftPackage::class);

        $base = sys_get_temp_dir() . '/lift-root-' . uniqid('', true);
        mkdir($base, 0777, true);
        $resolved = $resolve($base);
        self::assertSame($base, $resolved);
        @rmdir($base);
    }

    public function testValidateManifestPayloadRejectsUnsafeDbPath(): void
    {
        $package = new LiftPackage();
        $validate = \Closure::bind(function (array $manifest): void {
            $this->validateManifestPayload($manifest);
        }, $package, LiftPackage::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe db_dump path');

        $validate([
            'payload' => [
                'db_dump' => '../dump.sql',
            ],
        ]);
    }
}
