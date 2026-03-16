<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Backups;

use LiftTeleport\Backups\BackupRepository;
use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;
use PHPUnit\Framework\TestCase;

final class BackupRepositoryTest extends TestCase
{
    private string $sourceFile = '';

    protected function setUp(): void
    {
        if (function_exists('lift_test_reset_options')) {
            lift_test_reset_options();
        }

        Filesystem::deletePath(Paths::dataRoot());

        $this->sourceFile = sys_get_temp_dir() . '/lift-test-' . uniqid('', true) . '.lift';
        file_put_contents($this->sourceFile, 'lift-test-content');
    }

    protected function tearDown(): void
    {
        if ($this->sourceFile !== '' && file_exists($this->sourceFile)) {
            @unlink($this->sourceFile);
        }

        Filesystem::deletePath(Paths::dataRoot());

        if (function_exists('lift_test_reset_options')) {
            lift_test_reset_options();
        }
    }

    public function testCreateListFindMarkAndDeleteBackup(): void
    {
        $repository = new BackupRepository();

        $created = $repository->createFromExportFile($this->sourceFile, [
            'filename' => 'example-export.lift',
            'created_by' => 7,
            'source_job_id' => 12,
            'encrypted' => true,
        ]);

        self::assertNotSame('', (string) ($created['id'] ?? ''));
        self::assertSame('example-export.lift', (string) ($created['filename'] ?? ''));
        self::assertFileExists((string) ($created['path'] ?? ''));
        self::assertGreaterThan(0, (int) ($created['size_bytes'] ?? 0));

        $listed = $repository->paginatedList(1, 20);
        self::assertSame(1, (int) ($listed['pagination']['total'] ?? 0));
        self::assertCount(1, $listed['items']);

        $found = $repository->find((string) $created['id']);
        self::assertIsArray($found);
        self::assertSame((string) $created['id'], (string) ($found['id'] ?? ''));

        $repository->markDownloaded((string) $created['id']);
        $downloaded = $repository->find((string) $created['id']);
        self::assertSame(1, (int) ($downloaded['download_count'] ?? 0));
        self::assertNotSame('', (string) ($downloaded['last_downloaded_at'] ?? ''));

        $deleted = $repository->delete((string) $created['id']);
        self::assertTrue($deleted);
        self::assertNull($repository->find((string) $created['id']));

        $afterDelete = $repository->paginatedList(1, 20);
        self::assertSame(0, (int) ($afterDelete['pagination']['total'] ?? 0));
        self::assertCount(0, $afterDelete['items']);
    }
}
