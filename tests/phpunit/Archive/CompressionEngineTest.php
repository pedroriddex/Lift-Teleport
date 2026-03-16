<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Archive;

use LiftTeleport\Archive\CompressionEngine;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CompressionEngineTest extends TestCase
{
    public function testDetectCompressionByMagicBytes(): void
    {
        $engine = new CompressionEngine();
        $file = tempnam(sys_get_temp_dir(), 'lift-zstd-');
        self::assertIsString($file);
        file_put_contents($file, "\x28\xB5\x2F\xFDpayload");

        self::assertSame(CompressionEngine::ALGO_ZSTD, $engine->detectCompression($file));

        @unlink($file);
    }

    public function testRejectsTraversalEntry(): void
    {
        $engine = new CompressionEngine();
        $guard = \Closure::bind(function (array $entries): void {
            $this->assertSafeEntries($entries);
        }, $engine, CompressionEngine::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe traversal entry');
        $guard([
            ['path' => '../evil.php', 'type' => 'file'],
        ]);
    }

    public function testRejectsLinkEntries(): void
    {
        $engine = new CompressionEngine();
        $guard = \Closure::bind(function (array $entries): void {
            $this->assertSafeEntries($entries);
        }, $engine, CompressionEngine::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forbidden link entry');
        $guard([
            ['path' => 'content/wp-content/uploads/latest', 'type' => 'symlink'],
        ]);
    }

    public function testCreateTarFromEntriesPreservesTargetPaths(): void
    {
        $engine = new CompressionEngine();

        $tmpRoot = sys_get_temp_dir() . '/lift-compress-' . uniqid('', true);
        mkdir($tmpRoot, 0777, true);
        $sourceFile = $tmpRoot . '/hello.txt';
        file_put_contents($sourceFile, 'hello');

        $tarPath = $tmpRoot . '/payload.tar';
        $engine->createTarFromEntries([
            [
                'source' => $sourceFile,
                'target' => 'content/wp-content/uploads/hello.txt',
            ],
        ], $tarPath);

        $paths = array_map(static fn (array $entry): string => (string) $entry['path'], $engine->listTarEntries($tarPath));
        $normalized = array_map(static function (string $path): string {
            return ltrim((string) preg_replace('#^\./#', '', $path), '/');
        }, $paths);
        self::assertContains('content/wp-content/uploads/hello.txt', $normalized);

        @unlink($tarPath);
        @unlink($sourceFile);
        @rmdir($tmpRoot);
    }

    public function testExtractTarByStreamRestoresFilesWithoutSystemTar(): void
    {
        $engine = new CompressionEngine();

        $tmpRoot = sys_get_temp_dir() . '/lift-stream-extract-' . uniqid('', true);
        mkdir($tmpRoot, 0777, true);
        $sourceFile = $tmpRoot . '/hello.txt';
        file_put_contents($sourceFile, 'hello-stream');

        $tarPath = $tmpRoot . '/payload.tar';
        $engine->createTarFromEntries([
            [
                'source' => $sourceFile,
                'target' => 'content/wp-content/uploads/hello.txt',
            ],
        ], $tarPath);

        $destination = $tmpRoot . '/dest';
        mkdir($destination, 0777, true);

        $extract = \Closure::bind(function (string $tarPath, string $destination): void {
            $this->extractTarByStream($tarPath, $destination);
        }, $engine, CompressionEngine::class);

        $extract($tarPath, $destination);

        self::assertFileExists($destination . '/content/wp-content/uploads/hello.txt');
        self::assertSame('hello-stream', file_get_contents($destination . '/content/wp-content/uploads/hello.txt'));

        @unlink($destination . '/content/wp-content/uploads/hello.txt');
        @rmdir($destination . '/content/wp-content/uploads');
        @rmdir($destination . '/content/wp-content');
        @rmdir($destination . '/content');
        @rmdir($destination);
        @unlink($tarPath);
        @unlink($sourceFile);
        @rmdir($tmpRoot);
    }
}
