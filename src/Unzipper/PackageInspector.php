<?php

declare(strict_types=1);

namespace LiftTeleport\Unzipper;

use LiftTeleport\Archive\CompressionEngine;
use LiftTeleport\Archive\Encryption;
use LiftTeleport\Archive\LiftPackage;
use LiftTeleport\Support\DiskSpaceCheck;
use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;
use RuntimeException;
use Throwable;

final class PackageInspector
{
    private CompressionEngine $compression;

    private LiftPackage $liftPackage;

    private Encryption $encryption;

    public function __construct(
        ?CompressionEngine $compression = null,
        ?LiftPackage $liftPackage = null,
        ?Encryption $encryption = null
    ) {
        $this->compression = $compression ?: new CompressionEngine();
        $this->liftPackage = $liftPackage ?: new LiftPackage($this->compression, $encryption);
        $this->encryption = $encryption ?: new Encryption();
    }

    public function looksLikeLiftPackage(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $head = fread($handle, 4);
        fseek($handle, 0);
        $firstSix = fread($handle, 6);
        fseek($handle, 257);
        $tarMagic = fread($handle, 5);
        fclose($handle);

        if (! is_string($head) || $head === '') {
            return false;
        }

        if ($firstSix === Encryption::MAGIC) {
            return true;
        }

        if ($head === "\x28\xB5\x2F\xFD" || substr($head, 0, 2) === "\x1F\x8B") {
            return true;
        }

        return $tarMagic === 'ustar';
    }

    /**
     * @return array{
     *   quick_status:string,
     *   summary:array<string,mixed>,
     *   quick_report:array<string,mixed>,
     *   artifacts:array<string,string>
     * }
     */
    public function quickScan(int $jobId, string $liftFile, ?string $password = null): array
    {
        $this->assertInputFile($liftFile);

        $artifactPaths = $this->artifactPaths($jobId);
        Filesystem::ensureDirectory($artifactPaths['root']);

        $prepared = $this->prepareWorkingFile($liftFile, $artifactPaths['root'], $password, 'quick-decrypted.lift');
        $compressionAlgo = $this->compression->decompressToTar($prepared['working_file'], $artifactPaths['quick_tar']);

        $entries = $this->compression->listTarEntries($artifactPaths['quick_tar']);
        $this->assertSafeEntries($entries);

        $entryCounts = [
            'total' => 0,
            'file' => 0,
            'dir' => 0,
            'symlink' => 0,
            'hardlink' => 0,
        ];
        $manifestPresent = false;
        $checksumSidecarPresent = false;
        $topPrefixes = [];

        $entriesHandle = @fopen($artifactPaths['entries'], 'wb');
        if ($entriesHandle === false) {
            throw new RuntimeException('Unable to create entries index for Unzipper.');
        }

        try {
            foreach ($entries as $entry) {
                $path = str_replace('\\', '/', (string) ($entry['path'] ?? ''));
                $path = (string) preg_replace('#^\./+#', '', $path);
                $path = trim($path, '/');
                $type = strtolower((string) ($entry['type'] ?? 'file'));
                if ($path === '') {
                    continue;
                }

                $entryCounts['total']++;
                if (! isset($entryCounts[$type])) {
                    $entryCounts[$type] = 0;
                }
                $entryCounts[$type]++;

                if ($path === 'manifest.json') {
                    $manifestPresent = true;
                }

                if ($path === 'checksums/sha256.txt') {
                    $checksumSidecarPresent = true;
                }

                $segments = explode('/', $path);
                $top = (string) ($segments[0] ?? '');
                if ($top !== '') {
                    $topPrefixes[$top] = (int) ($topPrefixes[$top] ?? 0) + 1;
                }

                $line = wp_json_encode([
                    'path' => $path,
                    'type' => $type,
                    'name' => basename($path),
                ], JSON_UNESCAPED_SLASHES);

                if (! is_string($line) || fwrite($entriesHandle, $line . "\n") === false) {
                    throw new RuntimeException('Unable to persist entries index for Unzipper.');
                }
            }
        } finally {
            fclose($entriesHandle);
        }

        arsort($topPrefixes);
        $topPrefixes = array_slice($topPrefixes, 0, 12, true);

        $summary = [
            'quick_status' => 'passed',
            'full_status' => 'pending',
            'entry_counts' => $entryCounts,
            'encrypted' => (bool) $prepared['encrypted'],
            'compression' => $compressionAlgo,
            'manifest_present' => $manifestPresent,
            'checksum_sidecar_present' => $checksumSidecarPresent,
            'top_prefixes' => $topPrefixes,
            'generated_at' => gmdate(DATE_ATOM),
        ];

        $quickReport = [
            'status' => 'passed',
            'generated_at' => gmdate(DATE_ATOM),
            'encrypted' => (bool) $prepared['encrypted'],
            'compression' => $compressionAlgo,
            'entry_counts' => $entryCounts,
            'manifest_present' => $manifestPresent,
            'checksum_sidecar_present' => $checksumSidecarPresent,
            'top_prefixes' => $topPrefixes,
        ];

        $this->writeJson($artifactPaths['quick_report'], $quickReport);
        $this->writeSummary($artifactPaths, $summary);

        return [
            'quick_status' => 'passed',
            'summary' => $summary,
            'quick_report' => $quickReport,
            'artifacts' => $artifactPaths,
        ];
    }

    /**
     * @return array{
     *   full_status:string,
     *   summary:array<string,mixed>,
     *   full_report:array<string,mixed>,
     *   artifacts:array<string,string>
     * }
     */
    public function fullIntegrity(int $jobId, string $liftFile, ?string $password = null): array
    {
        $this->assertInputFile($liftFile);

        $artifactPaths = $this->artifactPaths($jobId);
        Filesystem::ensureDirectory($artifactPaths['root']);

        $size = @filesize($liftFile);
        $liftSize = is_int($size) && $size > 0 ? $size : 1;

        $required = (int) apply_filters(
            'lift_teleport_unzipper_full_integrity_required_free_bytes',
            max($liftSize * 3, 1024 * 1024 * 1024),
            $liftSize,
            $jobId
        );
        $required = max(1, $required);

        $diskCheck = DiskSpaceCheck::evaluate([
            Paths::dataRoot(),
            WP_CONTENT_DIR,
            ABSPATH,
        ], $required);

        $baseSummary = $this->readSummary($artifactPaths);
        if ($diskCheck['insufficient']) {
            $fullReport = [
                'status' => 'skipped_low_disk',
                'generated_at' => gmdate(DATE_ATOM),
                'error_code' => 'unzipper_low_disk',
                'message' => 'Full integrity verification skipped due to low disk space.',
                'disk' => [
                    'required_free_bytes' => $required,
                    'available_free_bytes' => $diskCheck['available'],
                    'path_used' => $diskCheck['path_used'],
                    'confidence' => $diskCheck['confidence'],
                    'paths' => $diskCheck['paths'],
                ],
            ];

            $summary = array_merge($baseSummary, [
                'full_status' => 'skipped_low_disk',
                'full_generated_at' => gmdate(DATE_ATOM),
                'disk' => $fullReport['disk'],
            ]);

            $this->writeJson($artifactPaths['full_report'], $fullReport);
            $this->writeSummary($artifactPaths, $summary);

            return [
                'full_status' => 'skipped_low_disk',
                'summary' => $summary,
                'full_report' => $fullReport,
                'artifacts' => $artifactPaths,
            ];
        }

        try {
            $result = $this->liftPackage->extractImportPackage($jobId, $liftFile, $password);

            $manifest = is_array($result['manifest'] ?? null) ? $result['manifest'] : [];
            $checksums = isset($manifest['checksums']['files']) && is_array($manifest['checksums']['files'])
                ? $manifest['checksums']['files']
                : [];

            $fullReport = [
                'status' => 'passed',
                'generated_at' => gmdate(DATE_ATOM),
                'checksum_verified' => (bool) ($result['checksum_verified'] ?? false),
                'encrypted' => (bool) ($result['encrypted'] ?? false),
                'compression' => (string) ($result['compression'] ?? 'unknown'),
                'format' => (string) ($manifest['format'] ?? ''),
                'format_revision' => (string) ($manifest['format_revision'] ?? ''),
                'db_dump_relative' => (string) ($result['db_dump_relative'] ?? ''),
                'payload_checksum_files' => count($checksums),
                'disk' => [
                    'required_free_bytes' => $required,
                    'available_free_bytes' => $diskCheck['available'],
                    'path_used' => $diskCheck['path_used'],
                    'confidence' => $diskCheck['confidence'],
                    'paths' => $diskCheck['paths'],
                ],
            ];

            $summary = array_merge($baseSummary, [
                'full_status' => 'passed',
                'checksum_verified' => (bool) ($result['checksum_verified'] ?? false),
                'full_generated_at' => gmdate(DATE_ATOM),
            ]);

            $this->writeJson($artifactPaths['full_report'], $fullReport);
            $this->writeSummary($artifactPaths, $summary);

            return [
                'full_status' => 'passed',
                'summary' => $summary,
                'full_report' => $fullReport,
                'artifacts' => $artifactPaths,
            ];
        } catch (Throwable $error) {
            $fullReport = [
                'status' => 'failed',
                'generated_at' => gmdate(DATE_ATOM),
                'error_code' => 'unzipper_integrity_failed',
                'message' => $error->getMessage(),
                'disk' => [
                    'required_free_bytes' => $required,
                    'available_free_bytes' => $diskCheck['available'],
                    'path_used' => $diskCheck['path_used'],
                    'confidence' => $diskCheck['confidence'],
                    'paths' => $diskCheck['paths'],
                ],
            ];

            $summary = array_merge($baseSummary, [
                'full_status' => 'failed',
                'full_generated_at' => gmdate(DATE_ATOM),
                'error_message' => $error->getMessage(),
            ]);

            $this->writeJson($artifactPaths['full_report'], $fullReport);
            $this->writeSummary($artifactPaths, $summary);

            return [
                'full_status' => 'failed',
                'summary' => $summary,
                'full_report' => $fullReport,
                'artifacts' => $artifactPaths,
            ];
        }
    }

    /**
     * @return array{entries:array<int,array<string,mixed>>,cursor:int,next_cursor:?int,limit:int,has_more:bool}
     */
    public function entriesPage(
        int $jobId,
        int $cursor = 0,
        int $limit = 200,
        string $prefix = '',
        string $search = ''
    ): array {
        $artifactPaths = $this->artifactPaths($jobId);
        if (! file_exists($artifactPaths['entries'])) {
            return [
                'entries' => [],
                'cursor' => 0,
                'next_cursor' => null,
                'limit' => max(1, min(500, $limit)),
                'has_more' => false,
            ];
        }

        $cursor = max(0, $cursor);
        $limit = max(1, min(500, $limit));
        $prefix = ltrim(str_replace('\\', '/', trim($prefix)), '/');
        $search = trim($search);

        $entries = [];
        $matchedIndex = 0;
        $hasMore = false;

        $handle = @fopen($artifactPaths['entries'], 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to read Unzipper entries index.');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode(trim($line), true);
                if (! is_array($decoded)) {
                    continue;
                }

                $path = (string) ($decoded['path'] ?? '');
                if ($path === '') {
                    continue;
                }

                if ($prefix !== '' && ! str_starts_with($path, $prefix)) {
                    continue;
                }

                if ($search !== '' && stripos($path, $search) === false) {
                    continue;
                }

                if ($matchedIndex < $cursor) {
                    $matchedIndex++;
                    continue;
                }

                if (count($entries) >= $limit) {
                    $hasMore = true;
                    break;
                }

                $entries[] = $decoded;
                $matchedIndex++;
            }
        } finally {
            fclose($handle);
        }

        return [
            'entries' => $entries,
            'cursor' => $cursor,
            'next_cursor' => $hasMore ? $cursor + count($entries) : null,
            'limit' => $limit,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function diagnostics(int $jobId): array
    {
        $artifactPaths = $this->artifactPaths($jobId);

        $summary = $this->readSummary($artifactPaths);
        $quick = $this->readJson($artifactPaths['quick_report']);
        $full = $this->readJson($artifactPaths['full_report']);

        return [
            'summary' => $summary,
            'quick_report' => $quick,
            'full_report' => $full,
            'artifacts_present' => [
                'entries' => file_exists($artifactPaths['entries']),
                'quick_report' => file_exists($artifactPaths['quick_report']),
                'full_report' => file_exists($artifactPaths['full_report']),
                'summary' => file_exists($artifactPaths['summary']),
            ],
        ];
    }

    public function cleanup(int $jobId): void
    {
        Filesystem::deletePath(Paths::jobRoot($jobId));
    }

    /**
     * @return array<string,string>
     */
    public function artifactPaths(int $jobId): array
    {
        $root = rtrim(Paths::jobWorkspace($jobId), '/\\') . '/unzipper';

        return [
            'root' => $root,
            'entries' => $root . '/entries.ndjson',
            'quick_report' => $root . '/quick_report.json',
            'full_report' => $root . '/full_report.json',
            'summary' => $root . '/summary.json',
            'quick_tar' => $root . '/quick_scan.tar',
        ];
    }

    private function assertInputFile(string $liftFile): void
    {
        if ($liftFile === '' || ! file_exists($liftFile)) {
            throw new RuntimeException('Unzipper source file is missing.');
        }

        $extension = strtolower((string) pathinfo($liftFile, PATHINFO_EXTENSION));
        if ($extension !== 'lift') {
            throw new RuntimeException('Unzipper source file must use the .lift extension.');
        }

        $size = @filesize($liftFile);
        if (! is_int($size) || $size <= 0) {
            throw new RuntimeException('Unzipper source file is empty or unreadable.');
        }

        if (! $this->looksLikeLiftPackage($liftFile)) {
            throw new RuntimeException('Source file is not a valid .lift package.');
        }
    }

    /**
     * @return array{working_file:string,encrypted:bool}
     */
    private function prepareWorkingFile(
        string $liftFile,
        string $workspace,
        ?string $password,
        string $decryptedName
    ): array {
        Filesystem::ensureDirectory($workspace);

        $encrypted = $this->encryption->isEncryptedFile($liftFile);
        if (! $encrypted) {
            return [
                'working_file' => $liftFile,
                'encrypted' => false,
            ];
        }

        if (! $this->encryption->isSupported()) {
            throw new RuntimeException('Encrypted .lift analysis requires the sodium extension.');
        }

        $password = (string) $password;
        if ($password === '') {
            throw new RuntimeException('This .lift package is encrypted and requires a password.');
        }

        $decrypted = rtrim($workspace, '/\\') . '/' . $decryptedName;
        @unlink($decrypted);
        $this->encryption->decryptFile($liftFile, $decrypted, $password);

        return [
            'working_file' => $decrypted,
            'encrypted' => true,
        ];
    }

    /**
     * @param array<int,array{path:string,type:string}> $entries
     */
    private function assertSafeEntries(array $entries): void
    {
        foreach ($entries as $entry) {
            $path = str_replace('\\', '/', (string) ($entry['path'] ?? ''));
            $type = (string) ($entry['type'] ?? 'file');

            $path = (string) preg_replace('#^\./+#', '', $path);
            $path = trim($path, '/');

            if ($path === '' || $path === '.') {
                continue;
            }

            if (str_contains($path, "\0")) {
                throw new RuntimeException('Archive contains an invalid empty path entry.');
            }

            if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\//', $path) === 1) {
                throw new RuntimeException(sprintf('Archive contains absolute path entry: %s', $path));
            }

            if (preg_match('/[\x00-\x1F]/', $path) === 1) {
                throw new RuntimeException(sprintf('Archive contains invalid control characters: %s', $path));
            }

            $segments = explode('/', $path);
            foreach ($segments as $segment) {
                if ($segment === '.' || $segment === '..' || $segment === '') {
                    throw new RuntimeException(sprintf('Archive contains unsafe traversal entry: %s', $path));
                }
            }

            if (in_array($type, ['symlink', 'hardlink'], true)) {
                throw new RuntimeException(sprintf('Archive contains forbidden link entry: %s', $path));
            }
        }
    }

    /**
     * @param array<string,string> $artifactPaths
     * @param array<string,mixed> $summary
     */
    private function writeSummary(array $artifactPaths, array $summary): void
    {
        $summary['generated_at'] = (string) ($summary['generated_at'] ?? gmdate(DATE_ATOM));
        $this->writeJson($artifactPaths['summary'], $summary);
    }

    /**
     * @param array<string,string> $artifactPaths
     * @return array<string,mixed>
     */
    private function readSummary(array $artifactPaths): array
    {
        $summary = $this->readJson($artifactPaths['summary']);
        return is_array($summary) ? $summary : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if (! is_string($content) || $content === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        Filesystem::ensureDirectory(dirname($path));
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || $json === '') {
            throw new RuntimeException('Unable to encode Unzipper diagnostics payload.');
        }

        if (@file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException(sprintf('Unable to write Unzipper artifact: %s', $path));
        }
    }
}
