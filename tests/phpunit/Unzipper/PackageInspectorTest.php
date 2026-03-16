<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Unzipper;

use LiftTeleport\Archive\CompressionEngine;
use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;
use LiftTeleport\Unzipper\PackageInspector;
use PHPUnit\Framework\TestCase;

final class PackageInspectorTest extends TestCase
{
    public function testQuickScanBuildsEntriesAndSummary(): void
    {
        $jobId = 9101;
        Paths::ensureJobDirs($jobId);

        $liftFile = $this->createLiftLikeTar($jobId, [
            [
                'target' => 'manifest.json',
                'contents' => '{"format":"lift-v2"}',
            ],
            [
                'target' => 'checksums/sha256.txt',
                'contents' => '',
            ],
            [
                'target' => 'content/wp-content/plugins/demo/plugin.php',
                'contents' => '<?php echo "ok";',
            ],
        ]);

        $inspector = new PackageInspector();
        $result = $inspector->quickScan($jobId, $liftFile);

        self::assertSame('passed', $result['quick_status']);
        self::assertSame('passed', (string) ($result['summary']['quick_status'] ?? ''));
        self::assertSame('pending', (string) ($result['summary']['full_status'] ?? ''));
        self::assertGreaterThan(0, (int) ($result['summary']['entry_counts']['total'] ?? 0));

        $entriesPage = $inspector->entriesPage($jobId, 0, 100, 'content/wp-content/plugins', 'plugin.php');
        self::assertNotEmpty($entriesPage['entries']);

        $inspector->cleanup($jobId);
    }

    public function testFullIntegrityReturnsFailedForInvalidPayloadWithoutThrowing(): void
    {
        $jobId = 9102;
        Paths::ensureJobDirs($jobId);

        $liftFile = $this->createLiftLikeTar($jobId, [
            [
                'target' => 'content/wp-content/uploads/file.txt',
                'contents' => 'payload',
            ],
        ]);

        $inspector = new PackageInspector();
        $inspector->quickScan($jobId, $liftFile);
        $result = $inspector->fullIntegrity($jobId, $liftFile);

        self::assertSame('failed', $result['full_status']);
        self::assertSame('failed', (string) ($result['full_report']['status'] ?? ''));
        self::assertNotSame('', (string) ($result['full_report']['message'] ?? ''));

        $inspector->cleanup($jobId);
    }

    /**
     * @param array<int,array{target:string,contents:string}> $entries
     */
    private function createLiftLikeTar(int $jobId, array $entries): string
    {
        $root = Paths::jobWorkspace($jobId) . '/test-build';
        Filesystem::deletePath($root);
        Filesystem::ensureDirectory($root);

        $tarEntries = [];
        foreach ($entries as $entry) {
            $source = $root . '/' . str_replace('/', '_', (string) $entry['target']);
            Filesystem::ensureDirectory(dirname($source));
            file_put_contents($source, (string) $entry['contents']);
            $tarEntries[] = [
                'source' => $source,
                'target' => (string) $entry['target'],
            ];
        }

        $tarPath = Paths::jobWorkspace($jobId) . '/test-package.tar';
        (new CompressionEngine())->createTarFromEntries($tarEntries, $tarPath);

        $liftPath = Paths::jobInput($jobId) . '/test-package.lift';
        Filesystem::copyFile($tarPath, $liftPath);

        return $liftPath;
    }
}
