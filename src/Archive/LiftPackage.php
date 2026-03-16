<?php

declare(strict_types=1);

namespace LiftTeleport\Archive;

use LiftTeleport\Support\CommandRunner;
use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;
use RuntimeException;

final class LiftPackage
{
    private const FORMAT_V1 = 'lift-v1';
    private const FORMAT_V2 = 'lift-v2';

    private CompressionEngine $compression;

    private Encryption $encryption;

    public function __construct(?CompressionEngine $compression = null, ?Encryption $encryption = null)
    {
        $this->compression = $compression ?: new CompressionEngine();
        $this->encryption = $encryption ?: new Encryption();
    }

    /**
     * @return array{
     *   file:string,
     *   filename:string,
     *   compression:string,
     *   encrypted:bool,
     *   size:int,
     *   checksum_files:int,
     *   format:string,
     *   format_revision:string,
     *   payload_summary:array<string,int>,
     *   exclusions:array<string,mixed>
     * }
     */
    public function buildExportPackage(int $jobId, array $manifest, ?string $password = null, ?callable $progressCallback = null): array
    {
        $emitProgress = static function (string $phase, array $metrics = []) use ($progressCallback): void {
            if ($progressCallback === null) {
                return;
            }

            $progressCallback(array_merge(['phase' => $phase], $metrics));
        };

        $workspace = Paths::jobWorkspace($jobId);
        $output = Paths::jobOutput($jobId);
        Filesystem::ensureDirectory($workspace);
        Filesystem::ensureDirectory($output);

        $dbDump = $workspace . '/db/dump.sql';
        if (! file_exists($dbDump)) {
            throw new RuntimeException('Database dump file is missing.');
        }

        $format = $this->resolveExportFormat($manifest);
        $formatRevision = $format === self::FORMAT_V2 ? '2.0' : '1.1';
        $dbDumpRelative = 'db/dump.sql';

        $entries = [
            [
                'source' => $dbDump,
                'target' => $dbDumpRelative,
            ],
        ];

        $dbHash = hash_file('sha256', $dbDump);
        if (! is_string($dbHash) || $dbHash === '') {
            throw new RuntimeException('Unable to checksum SQL dump file.');
        }

        $payloadChecksums = [
            $dbDumpRelative => $dbHash,
        ];
        $contentFileCount = 0;
        $contentBytes = 0;

        $emitProgress('scan_content');
        $exclusionStats = [
            'count' => 0,
            'bytes' => 0,
            'sample_paths' => [],
        ];
        $contentEntries = $this->collectContentEntries(WP_CONTENT_DIR, $exclusionStats);
        $contentTotal = count($contentEntries);
        $emitProgress('scan_content', [
            'files_done' => $contentTotal,
            'files_total' => $contentTotal,
            'bytes_done' => 0,
            'bytes_total' => 0,
        ]);

        $emitProgress('checksum_payload', [
            'files_done' => 0,
            'files_total' => $contentTotal + 1,
            'bytes_done' => 0,
            'bytes_total' => 0,
        ]);

        $checksummedFiles = 1;
        $checksummedBytes = (int) filesize($dbDump);
        $estimatedTotalBytes = $checksummedBytes;
        foreach ($contentEntries as $entryEstimate) {
            $estimatedTotalBytes += (int) ($entryEstimate['size'] ?? 0);
        }

        $emitProgress('checksum_payload', [
            'files_done' => $checksummedFiles,
            'files_total' => $contentTotal + 1,
            'bytes_done' => $checksummedBytes,
            'bytes_total' => $estimatedTotalBytes,
        ]);

        foreach ($contentEntries as $entry) {
            $hash = hash_file('sha256', $entry['source']);
            if (! is_string($hash) || $hash === '') {
                throw new RuntimeException(sprintf('Unable to checksum file: %s', $entry['source']));
            }

            $entries[] = $entry;
            $payloadChecksums[$entry['target']] = $hash;
            $contentFileCount++;
            $contentBytes += (int) ($entry['size'] ?? 0);
            $checksummedFiles++;
            $checksummedBytes += (int) ($entry['size'] ?? 0);

            if ($checksummedFiles % 50 === 0 || $checksummedFiles === ($contentTotal + 1)) {
                $emitProgress('checksum_payload', [
                    'files_done' => $checksummedFiles,
                    'files_total' => $contentTotal + 1,
                    'bytes_done' => $checksummedBytes,
                    'bytes_total' => $estimatedTotalBytes,
                ]);
            }
        }

        ksort($payloadChecksums);

        $metaRoot = $workspace . '/package-meta';
        Filesystem::deletePath($metaRoot);
        Filesystem::ensureDirectory($metaRoot . '/checksums');

        $manifestPath = $metaRoot . '/manifest.json';
        $manifest = $this->buildManifestForPackage($manifest, $format, $formatRevision, $dbDumpRelative, $payloadChecksums);
        $manifestJson = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($manifestJson) || $manifestJson === '') {
            throw new RuntimeException('Unable to encode package manifest.');
        }

        file_put_contents($manifestPath, $manifestJson . "\n");

        $checksumPath = $metaRoot . '/checksums/sha256.txt';
        $sidecarChecksums = $payloadChecksums;
        $manifestHash = hash_file('sha256', $manifestPath);
        if (! is_string($manifestHash) || $manifestHash === '') {
            throw new RuntimeException('Unable to calculate manifest checksum.');
        }

        $sidecarChecksums['manifest.json'] = $manifestHash;
        ksort($sidecarChecksums);

        $lines = [];
        foreach ($sidecarChecksums as $path => $hash) {
            $lines[] = sprintf('%s  %s', $hash, $path);
        }

        file_put_contents($checksumPath, implode("\n", $lines) . "\n");

        $tarPath = $output . '/package.tar';
        $exportBaseName = $this->generateExportBaseName($manifest, $jobId);
        $finalPath = $output . '/' . $exportBaseName . '.lift';
        $compressedPath = ($password !== null && $password !== '')
            ? $output . '/' . $exportBaseName . '.plain.tmp.lift'
            : $finalPath;

        @unlink($finalPath);
        if ($compressedPath !== $finalPath) {
            @unlink($compressedPath);
        }

        $entries[] = ['source' => $manifestPath, 'target' => 'manifest.json'];
        $entries[] = ['source' => $checksumPath, 'target' => 'checksums/sha256.txt'];
        $this->validateExportPayloadEntries($entries, $dbDumpRelative);

        $emitProgress('tar_build');
        $this->compression->createTarFromEntries($entries, $tarPath, function (array $metrics) use ($emitProgress): void {
            $emitProgress('tar_build', $metrics);
        });

        $emitProgress('compress_package', [
            'status' => 'attempt_started',
            'attempt' => 1,
            'fallback_stage' => 'primary',
            'bytes_done' => 0,
            'bytes_total' => file_exists($tarPath) ? (int) filesize($tarPath) : 0,
            'output_bytes' => 0,
        ]);
        $compression = $this->compression->compressTar($tarPath, $compressedPath, function (array $metrics) use ($emitProgress): void {
            $emitProgress('compress_package', $metrics);
        });

        $this->assertExportArtifactIntegrity($compressedPath, $compression);
        do_action('lift_teleport_artifact_verified', [
            'stage' => 'export_artifact',
            'job_id' => $jobId,
            'path' => $compressedPath,
            'compression_algo' => $compression,
            'size_bytes' => file_exists($compressedPath) ? (int) filesize($compressedPath) : 0,
            'verified_at' => gmdate(DATE_ATOM),
        ]);

        $encrypted = false;

        if ($password !== null && $password !== '') {
            if (! $this->encryption->isSupported()) {
                throw new RuntimeException('Sodium extension is required for encrypted package exports.');
            }

            $compressedBytes = file_exists($compressedPath) ? (int) filesize($compressedPath) : 0;
            $emitProgress('encrypt_package', [
                'status' => 'running',
                'bytes_done' => 0,
                'bytes_total' => max(1, $compressedBytes),
                'elapsed_seconds' => 0,
            ]);

            $this->encryption->encryptFile($compressedPath, $finalPath, $password, function (array $metrics) use ($emitProgress): void {
                $emitProgress('encrypt_package', array_merge(['status' => 'running'], $metrics));
            });
            $emitProgress('encrypt_package', [
                'status' => 'completed',
                'bytes_done' => max(1, $compressedBytes),
                'bytes_total' => max(1, $compressedBytes),
                'elapsed_seconds' => 0,
            ]);
            @unlink($compressedPath);
            $encrypted = true;
        }

        $emitProgress('finalize_package');
        if (! file_exists($finalPath)) {
            throw new RuntimeException('Export package final file was not created.');
        }

        $finalSize = (int) filesize($finalPath);
        if ($finalSize <= 0) {
            throw new RuntimeException('Export package final file is empty.');
        }

        $dbBytes = file_exists($dbDump) ? (int) filesize($dbDump) : 0;

        return [
            'file' => $finalPath,
            'filename' => basename($finalPath),
            'compression' => $compression,
            'encrypted' => $encrypted,
            'size' => $finalSize,
            'checksum_files' => count($payloadChecksums),
            'format' => $format,
            'format_revision' => $formatRevision,
            'payload_summary' => [
                'db_files' => 1,
                'db_bytes' => $dbBytes,
                'content_files' => $contentFileCount,
                'content_bytes' => $contentBytes,
                'total_files' => 1 + $contentFileCount,
                'total_bytes' => $dbBytes + $contentBytes,
            ],
            'exclusions' => $exclusionStats,
        ];
    }

    private function assertExportArtifactIntegrity(string $path, string $compression): void
    {
        if (! file_exists($path)) {
            throw new RuntimeException('Export package file is missing after compression.');
        }

        $size = (int) filesize($path);
        if ($size <= 0) {
            throw new RuntimeException('Export package file is empty after compression.');
        }

        if ($compression === CompressionEngine::ALGO_NONE) {
            if (! $this->looksLikeCompleteTarContainer($path, $size)) {
                throw new RuntimeException('Export package failed integrity check (tar container appears incomplete).');
            }

            return;
        }

        if ($compression === CompressionEngine::ALGO_GZIP) {
            if (CommandRunner::commandExists('gzip')) {
                CommandRunner::run(sprintf('gzip -t %s', escapeshellarg($path)));
                return;
            }

            $stream = @gzopen($path, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Export package failed integrity check (unable to open gzip stream).');
            }

            try {
                while (! gzeof($stream)) {
                    $chunk = gzread($stream, 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('Export package failed integrity check (invalid gzip stream).');
                    }
                }
            } finally {
                gzclose($stream);
            }

            return;
        }

        if ($compression === CompressionEngine::ALGO_ZSTD) {
            if (! CommandRunner::commandExists('zstd')) {
                throw new RuntimeException('Unable to verify zstd export integrity because zstd binary is not available.');
            }

            CommandRunner::run(sprintf('zstd -q -t %s', escapeshellarg($path)));
        }
    }

    private function looksLikeCompleteTarContainer(string $path, int $size): bool
    {
        if ($size < 1024 || ($size % 512) !== 0) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $head = fread($handle, 262);
            if (! is_string($head) || strlen($head) < 262) {
                return false;
            }

            if (substr($head, 257, 5) !== 'ustar') {
                return false;
            }

            if (@fseek($handle, -1024, SEEK_END) !== 0) {
                return false;
            }

            $tail = fread($handle, 1024);
            if (! is_string($tail) || strlen($tail) !== 1024) {
                return false;
            }

            return trim($tail, "\0") === '';
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{manifest:array<string,mixed>,extracted_root:string,package_path:string,compression:string,encrypted:bool,checksum_verified:bool,db_dump_relative:string}
     */
    public function extractImportPackage(int $jobId, string $liftFile, ?string $password = null): array
    {
        $workspace = Paths::jobWorkspace($jobId);
        Filesystem::ensureDirectory($workspace);

        $workingFile = $liftFile;
        $encrypted = $this->encryption->isEncryptedFile($liftFile);

        if ($encrypted) {
            if ($password === null || $password === '') {
                throw new RuntimeException('This .lift file is encrypted and requires a password.');
            }

            $decrypted = $workspace . '/decrypted-package.lift';
            $this->encryption->decryptFile($liftFile, $decrypted, $password);
            $workingFile = $decrypted;
        }

        $tarPath = $workspace . '/import-package.tar';
        $compression = $this->compression->decompressToTar($workingFile, $tarPath);

        $extractDir = $workspace . '/extracted';
        Filesystem::deletePath($extractDir);
        Filesystem::ensureDirectory($extractDir);
        $this->compression->extractTarSecure($tarPath, $extractDir);

        $payloadRoot = $this->resolvePayloadRoot($extractDir);
        $manifestPath = $payloadRoot . '/manifest.json';
        if (! file_exists($manifestPath)) {
            throw new RuntimeException('Invalid .lift file: manifest.json is missing.');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            throw new RuntimeException('Invalid .lift file: manifest is not valid JSON.');
        }

        $this->assertValidManifest($manifest);
        $this->validateManifestPayload($manifest);

        $dbDumpRelative = $this->resolveDbDumpRelative($manifest);
        $dbDumpAbsolute = $payloadRoot . '/' . $dbDumpRelative;
        if (! file_exists($dbDumpAbsolute)) {
            foreach ($this->legacyDbDumpCandidates() as $candidate) {
                $candidatePath = $payloadRoot . '/' . $candidate;
                if (! file_exists($candidatePath)) {
                    continue;
                }

                $dbDumpRelative = $candidate;
                $dbDumpAbsolute = $candidatePath;
                break;
            }
        }

        if (! file_exists($dbDumpAbsolute)) {
            throw new RuntimeException(sprintf('Invalid .lift file: %s is missing.', $dbDumpRelative));
        }

        $checksumVerified = $this->verifyImportChecksums($payloadRoot, $manifest);
        do_action('lift_teleport_artifact_verified', [
            'stage' => 'import_payload',
            'job_id' => $jobId,
            'path' => $liftFile,
            'compression_algo' => $compression,
            'checksum_verified' => $checksumVerified,
            'verified_at' => gmdate(DATE_ATOM),
        ]);

        return [
            'manifest' => $manifest,
            'extracted_root' => $payloadRoot,
            'package_path' => $workingFile,
            'compression' => $compression,
            'encrypted' => $encrypted,
            'checksum_verified' => $checksumVerified,
            'db_dump_relative' => $dbDumpRelative,
        ];
    }

    /**
     * @return array<int,array{source:string,target:string,size:int}>
     */
    private function collectContentEntries(string $sourceRoot, ?array &$exclusionStats = null): array
    {
        $entries = [];
        if (! is_dir($sourceRoot)) {
            return $entries;
        }

        $sourceRootReal = realpath($sourceRoot);
        if (! is_string($sourceRootReal) || $sourceRootReal === '') {
            return $entries;
        }

        $sourceRootReal = rtrim(str_replace('\\', '/', $sourceRootReal), '/');

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceRootReal, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (\Throwable) {
            return $entries;
        }

        foreach ($iterator as $item) {
            if (! $item instanceof \SplFileInfo || ! $item->isFile()) {
                continue;
            }

            $sourcePath = (string) $item->getPathname();
            $realPath = realpath($sourcePath);
            if (! is_string($realPath) || $realPath === '') {
                continue;
            }

            $realPath = str_replace('\\', '/', $realPath);
            if (! str_starts_with($realPath, $sourceRootReal . '/')) {
                continue;
            }

            if (! is_readable($realPath)) {
                continue;
            }

            $relative = ltrim(substr($realPath, strlen($sourceRootReal)), '/');
            if ($relative === '' || $this->shouldExcludeFromPackage($relative, $realPath)) {
                if (is_array($exclusionStats)) {
                    $exclusionStats['count'] = (int) ($exclusionStats['count'] ?? 0) + 1;
                    $exclusionStats['bytes'] = (int) ($exclusionStats['bytes'] ?? 0) + (int) $item->getSize();
                    $samples = is_array($exclusionStats['sample_paths'] ?? null) ? $exclusionStats['sample_paths'] : [];
                    if (count($samples) < 20) {
                        $samples[] = $relative;
                    }
                    $exclusionStats['sample_paths'] = $samples;
                }
                continue;
            }

            $entries[] = [
                'source' => $realPath,
                'target' => 'content/wp-content/' . str_replace('\\', '/', $relative),
                'size' => (int) $item->getSize(),
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            return strcmp((string) $left['target'], (string) $right['target']);
        });

        return $entries;
    }

    private function resolveExportFormat(array $manifest): string
    {
        $requested = isset($manifest['format']) ? strtolower((string) $manifest['format']) : '';
        if ($requested !== self::FORMAT_V1 && $requested !== self::FORMAT_V2) {
            $requested = self::FORMAT_V2;
        }

        $resolved = (string) apply_filters('lift_teleport_export_format_version', $requested, $manifest);
        if ($resolved !== self::FORMAT_V1 && $resolved !== self::FORMAT_V2) {
            $resolved = self::FORMAT_V2;
        }

        return $resolved;
    }

    /**
     * @param array<string,mixed> $baseManifest
     * @param array<string,string> $payloadChecksums
     * @return array<string,mixed>
     */
    private function buildManifestForPackage(
        array $baseManifest,
        string $format,
        string $formatRevision,
        string $dbDumpRelative,
        array $payloadChecksums
    ): array {
        $manifest = $baseManifest;
        $manifest['generated_at'] = gmdate(DATE_ATOM);
        $manifest['format'] = $format;
        $manifest['format_revision'] = $formatRevision;

        if (! isset($manifest['payload']) || ! is_array($manifest['payload'])) {
            $manifest['payload'] = [];
        }

        $manifest['payload']['db_dump'] = $dbDumpRelative;

        if (! isset($manifest['payload']['wp_content'])) {
            $manifest['payload']['wp_content'] = [
                'root' => 'content/wp-content',
            ];
        }

        $manifest['checksums'] = [
            'algorithm' => 'sha256',
            'files' => $payloadChecksums,
        ];

        return $manifest;
    }

    private function resolveDbDumpRelative(array $manifest): string
    {
        $dbDump = (string) ($manifest['payload']['db_dump'] ?? 'db/dump.sql');
        $dbDump = ltrim(str_replace('\\', '/', $dbDump), '/');

        if ($dbDump === '') {
            return 'db/dump.sql';
        }

        return $dbDump;
    }

    /**
     * @return array<int,string>
     */
    private function legacyDbDumpCandidates(): array
    {
        return [
            'db/dump.sql',
            'dump.sql',
            'database.sql',
            'db/database.sql',
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function validateManifestPayload(array $manifest): void
    {
        $dbDump = $this->resolveDbDumpRelative($manifest);
        if (! $this->isSafeRelativePath($dbDump)) {
            throw new RuntimeException('Invalid .lift file: payload contains unsafe db_dump path.');
        }
    }

    private function isSafeRelativePath(string $path): bool
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || str_starts_with($path, '/')) {
            return false;
        }

        if (preg_match('/^[a-zA-Z]:\//', $path) === 1) {
            return false;
        }

        if (str_contains($path, "\0")) {
            return false;
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function shouldExcludeFromPackage(string $relative, string $sourcePath): bool
    {
        $basename = basename($sourcePath);
        $normalized = trim(str_replace('\\', '/', $relative), '/');

        if ($basename === '.DS_Store' || $basename === 'Thumbs.db' || str_starts_with($basename, '._')) {
            return true;
        }

        if (str_contains($normalized, '/.DS_Store') || str_contains($normalized, '/Thumbs.db')) {
            return true;
        }

        if (
            preg_match('/\.(tmp|temp|swp|swo|bak)$/i', $basename) === 1 ||
            str_starts_with($basename, '.~')
        ) {
            return true;
        }

        if ($normalized === 'lift-teleport' || str_starts_with($normalized, 'lift-teleport/')) {
            return true;
        }

        $excluded = apply_filters('lift_teleport_export_excluded_paths', [
            'lift-teleport',
            'plugins/lift-teleport',
            'plugins/lift-teleport/node_modules',
            'plugins/lift-teleport/tests',
            'plugins/lift-teleport/assets/src',
            'plugins/lift-teleport/.git',
            'plugins/lift-teleport/.github',
            'lift-teleport-data',
            'upgrade-temp-backup',
            'ai1wm-backups',
            'updraft',
            'backupbuddy_backups',
            'backups-dup-pro',
            'cache',
            'wflogs',
            'litespeed',
            '.git',
            '.svn',
            '.hg',
        ]);

        if (is_array($excluded)) {
            foreach ($excluded as $excludePath) {
                if (! is_string($excludePath)) {
                    continue;
                }

                $excludePath = trim(str_replace('\\', '/', $excludePath), '/');
                if ($excludePath === '') {
                    continue;
                }

                if ($normalized === $excludePath || str_starts_with($normalized, $excludePath . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int,array{source:string,target:string}> $entries
     */
    private function validateExportPayloadEntries(array $entries, string $dbDumpRelative): void
    {
        $targets = [];

        foreach ($entries as $entry) {
            $source = isset($entry['source']) ? (string) $entry['source'] : '';
            $target = isset($entry['target']) ? ltrim(str_replace('\\', '/', (string) $entry['target']), '/') : '';
            if ($source === '' || $target === '') {
                continue;
            }

            if (! file_exists($source) || ! is_readable($source)) {
                throw new RuntimeException(sprintf('Missing export payload source: %s', $source));
            }

            $targets[$target] = true;
        }

        $requiredTargets = [
            ltrim(str_replace('\\', '/', $dbDumpRelative), '/'),
            'manifest.json',
            'checksums/sha256.txt',
        ];

        foreach ($requiredTargets as $required) {
            if (! isset($targets[$required])) {
                throw new RuntimeException(sprintf('Invalid export payload: required entry is missing (%s).', $required));
            }
        }
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function assertValidManifest(array $manifest): void
    {
        $format = (string) ($manifest['format'] ?? '');
        if ($format === '') {
            return;
        }

        if ($format !== self::FORMAT_V1 && $format !== self::FORMAT_V2) {
            throw new RuntimeException(sprintf('Unsupported .lift format: %s', $format));
        }
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function verifyImportChecksums(string $extractRoot, array $manifest): bool
    {
        $expected = [];
        if (isset($manifest['checksums']['files']) && is_array($manifest['checksums']['files'])) {
            $expected = array_filter(
                $manifest['checksums']['files'],
                static fn ($hash, $path): bool => is_string($hash) && is_string($path),
                ARRAY_FILTER_USE_BOTH
            );
        }

        $sidecarPath = $extractRoot . '/checksums/sha256.txt';
        $sidecar = file_exists($sidecarPath) ? $this->parseSidecarChecksums($sidecarPath) : [];

        if ($expected === [] && $sidecar !== []) {
            // Backward compatibility with legacy archives where integrity only lived in sidecar.
            $expected = $sidecar;
            unset($expected['manifest.json']);
        }

        if ($expected === []) {
            throw new RuntimeException('Invalid .lift file: checksum metadata is missing.');
        }

        foreach ($expected as $relative => $hash) {
            $normalized = ltrim(str_replace('\\', '/', (string) $relative), '/');
            if (! $this->isSafeRelativePath($normalized)) {
                throw new RuntimeException(sprintf('Invalid .lift file: checksum path is unsafe (%s).', $relative));
            }

            $path = $extractRoot . '/' . $normalized;
            if (! file_exists($path)) {
                throw new RuntimeException(sprintf('Invalid .lift file: expected checksum file is missing (%s).', $relative));
            }

            $actual = hash_file('sha256', $path);
            if (! is_string($actual) || strtolower($actual) !== strtolower((string) $hash)) {
                throw new RuntimeException(sprintf('Checksum mismatch for %s.', $relative));
            }
        }

        if (isset($sidecar['manifest.json'])) {
            $manifestHash = hash_file('sha256', $extractRoot . '/manifest.json');
            if (! is_string($manifestHash) || strtolower($manifestHash) !== strtolower((string) $sidecar['manifest.json'])) {
                throw new RuntimeException('Checksum mismatch for manifest.json.');
            }
        }

        return true;
    }

    /**
     * @return array<string,string>
     */
    private function parseSidecarChecksums(string $file): array
    {
        $map = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($lines)) {
            return $map;
        }

        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }

            if (preg_match('/^([a-fA-F0-9]{64})\s{2}(.+)$/', trim($line), $matches) !== 1) {
                continue;
            }

            $path = ltrim(str_replace('\\', '/', $matches[2]), '/');
            $map[$path] = strtolower($matches[1]);
        }

        return $map;
    }

    private function resolvePayloadRoot(string $extractRoot): string
    {
        if ($this->looksLikePayloadRoot($extractRoot)) {
            return $extractRoot;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($extractRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (\Throwable) {
            return $extractRoot;
        }

        $baseLength = strlen(rtrim($extractRoot, '/\\'));
        foreach ($iterator as $item) {
            if (! $item->isDir()) {
                continue;
            }

            $candidate = $item->getPathname();
            $relative = ltrim(substr($candidate, $baseLength), '/\\');
            if ($relative === '') {
                continue;
            }

            // Limit discovery to reasonably shallow roots for safety and performance.
            if (substr_count(str_replace('\\', '/', $relative), '/') > 3) {
                continue;
            }

            if ($this->looksLikePayloadRoot($candidate)) {
                return $candidate;
            }
        }

        return $extractRoot;
    }

    private function looksLikePayloadRoot(string $path): bool
    {
        return file_exists(rtrim($path, '/\\') . '/manifest.json');
    }

    private function generateExportBaseName(array $manifest, int $jobId): string
    {
        $siteUrl = (string) ($manifest['site']['home_url'] ?? $manifest['site']['site_url'] ?? home_url());
        $host = '';

        $parsed = wp_parse_url($siteUrl);
        if (is_array($parsed) && isset($parsed['host'])) {
            $host = strtolower((string) $parsed['host']);
        }

        $host = preg_replace('/^www\./i', '', $host) ?? $host;
        $hostFirstLabel = $host;
        if ($host !== '') {
            $parts = explode('.', $host);
            $hostFirstLabel = (string) ($parts[0] ?? $host);
        }

        $domainSegment = $this->sanitizeFileSegment($hostFirstLabel);
        if ($domainSegment === '') {
            $domainSegment = $this->sanitizeFileSegment((string) get_option('blogname', 'site'));
        }
        if ($domainSegment === '') {
            $domainSegment = 'site';
        }

        $timestamp = (int) current_time('timestamp');
        if ($timestamp <= 0) {
            $timestamp = time();
        }
        $dateSegment = function_exists('wp_date')
            ? wp_date('dmY_Hi', $timestamp)
            : date('dmY_Hi', $timestamp);

        $base = $domainSegment . '_' . $dateSegment;
        $base = (string) apply_filters('lift_teleport_export_filename_base', $base, $manifest, $jobId);
        $base = $this->sanitizeFileSegment($base);

        if ($base === '') {
            $base = 'site_' . $dateSegment;
        }

        return substr($base, 0, 120);
    }

    private function sanitizeFileSegment(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? '';
        return trim($value, '_-');
    }
}
