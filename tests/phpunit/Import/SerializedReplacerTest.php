<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Import;

use LiftTeleport\Import\SerializedReplacer;
use PHPUnit\Framework\TestCase;

final class SerializedReplacerTest extends TestCase
{
    public function testReplaceMaybeSerializedUpdatesNestedValues(): void
    {
        $replacer = $this->makeReplacerWithoutConstructor();
        $replace = \Closure::bind(function (string $value, string $search, string $replace): string {
            return $this->replaceMaybeSerialized($value, $search, $replace);
        }, $replacer, SerializedReplacer::class);

        $serialized = serialize([
            'url' => 'https://old.example.com/shop',
            'nested' => [
                'home' => 'https://old.example.com',
            ],
        ]);

        $updated = $replace($serialized, 'https://old.example.com', 'https://new.example.com');
        $decoded = unserialize($updated);

        self::assertIsArray($decoded);
        self::assertSame('https://new.example.com/shop', $decoded['url'] ?? '');
        self::assertSame('https://new.example.com', $decoded['nested']['home'] ?? '');
    }

    public function testReplaceMaybeSerializedFallsBackForPlainText(): void
    {
        $replacer = $this->makeReplacerWithoutConstructor();
        $replace = \Closure::bind(function (string $value, string $search, string $replace): string {
            return $this->replaceMaybeSerialized($value, $search, $replace);
        }, $replacer, SerializedReplacer::class);

        $result = $replace(
            'Visit https://old.example.com now',
            'https://old.example.com',
            'https://new.example.com'
        );

        self::assertSame('Visit https://new.example.com now', $result);
    }

    public function testReplaceMaybeSerializedManySupportsBatchReplacements(): void
    {
        $replacer = $this->makeReplacerWithoutConstructor();
        $replaceMany = \Closure::bind(function (string $value, array $replacements): string {
            return $this->replaceMaybeSerializedMany($value, $replacements);
        }, $replacer, SerializedReplacer::class);

        $value = serialize([
            'site' => 'https://old.example.com',
            'path' => '/Users/source/site/public',
        ]);

        $updated = $replaceMany($value, [
            ['search' => 'https://old.example.com', 'replace' => 'https://new.example.com'],
            ['search' => '/Users/source/site/public', 'replace' => '/Users/dest/site/public'],
        ]);

        $decoded = unserialize($updated);
        self::assertIsArray($decoded);
        self::assertSame('https://new.example.com', $decoded['site'] ?? '');
        self::assertSame('/Users/dest/site/public', $decoded['path'] ?? '');
    }

    public function testNormalizeReplacementsSkipsInvalidPairs(): void
    {
        $replacer = $this->makeReplacerWithoutConstructor();
        $normalize = \Closure::bind(function (array $replacements): array {
            return $this->normalizeReplacements($replacements);
        }, $replacer, SerializedReplacer::class);

        $normalized = $normalize([
            ['', ''],
            ['https://a.example', 'https://a.example'],
            ['https://old.example', 'https://new.example'],
            ['search' => 'https://old.example', 'replace' => 'https://new.example'],
            ['search' => '/old/path', 'replace' => '/new/path'],
        ]);

        self::assertCount(2, $normalized);
        self::assertSame('https://old.example', $normalized[0]['search'] ?? '');
        self::assertSame('/old/path', $normalized[1]['search'] ?? '');
    }

    public function testIdentifiesInternalRuntimeTables(): void
    {
        $replacer = $this->makeReplacerWithoutConstructor();
        $isInternal = \Closure::bind(function (string $table, string $prefix): bool {
            return $this->isInternalRuntimeTable($table, $prefix);
        }, $replacer, SerializedReplacer::class);

        self::assertTrue($isInternal('wp_lift_jobs', 'wp_'));
        self::assertTrue($isInternal('wp_lift_job_events', 'wp_'));
        self::assertFalse($isInternal('wp_posts', 'wp_'));
    }

    private function makeReplacerWithoutConstructor(): SerializedReplacer
    {
        $reflection = new \ReflectionClass(SerializedReplacer::class);
        /** @var SerializedReplacer $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        return $instance;
    }
}
