<?php

declare(strict_types=1);

namespace LiftTeleport\Archive;

use LiftTeleport\Support\CommandRunner;
use LiftTeleport\Support\Filesystem;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class CompressionEngine
{
    public const ALGO_NONE = 'none';
    public const ALGO_GZIP = 'gzip';
    public const ALGO_ZSTD = 'zstd';

    public function createTarFromDirectory(string $sourceDir, string $tarPath): void
    {
        Filesystem::ensureDirectory(dirname($tarPath));
        @unlink($tarPath);

        if (CommandRunner::commandExists('tar')) {
            $command = sprintf(
                'tar -chf %s -C %s .',
                escapeshellarg($tarPath),
                escapeshellarg($sourceDir)
            );

            CommandRunner::run($command);
            return;
        }

        $files = $this->collectDirectoryFiles($sourceDir, '');
        (new TarStreamWriter())->createTar($files, $tarPath, null);
    }

    /**
     * @param array<int,array{source:string,target:string}> $entries
     */
    public function createTarFromEntries(array $entries, string $tarPath, ?callable $progressCallback = null): void
    {
        Filesystem::ensureDirectory(dirname($tarPath));
        @unlink($tarPath);

        $files = $this->expandTarEntries($entries);
        $filesTotal = count($files);
        $bytesTotal = 0;
        foreach ($files as $file) {
            $bytesTotal += (int) ($file['size'] ?? 0);
        }

        $filesDone = 0;
        $bytesDone = 0;
        if ($progressCallback !== null) {
            $progressCallback([
                'phase' => 'tar_build',
                'engine' => '',
                'status' => 'running',
                'files_done' => $filesDone,
                'files_total' => $filesTotal,
                'bytes_done' => $bytesDone,
                'bytes_total' => $bytesTotal,
            ]);
        }

        if ($filesTotal === 0) {
            $writer = new TarStreamWriter();
            $writer->createTar([], $tarPath, $progressCallback);
            return;
        }

        $lastError = null;
        if (CommandRunner::commandExists('tar')) {
            try {
                $shellBuilder = new TarShellBuilder();
                $shellBuilder->createTar($files, $tarPath, $progressCallback);
                return;
            } catch (Throwable $error) {
                $lastError = $error;
                if ($progressCallback !== null) {
                    $progressCallback([
                        'phase' => 'tar_build',
                        'engine' => 'shell',
                        'status' => 'fallback',
                        'files_done' => $filesDone,
                        'files_total' => $filesTotal,
                        'bytes_done' => $bytesDone,
                        'bytes_total' => $bytesTotal,
                        'error_message' => $error->getMessage(),
                    ]);
                }
            }
        }

        try {
            $writer = new TarStreamWriter();
            $writer->createTar($files, $tarPath, $progressCallback);
            return;
        } catch (Throwable $streamError) {
            if ($lastError !== null) {
                throw new RuntimeException(
                    sprintf(
                        'Unable to create TAR archive with shell and PHP stream builders. Shell: %s | Stream: %s',
                        $lastError->getMessage(),
                        $streamError->getMessage()
                    )
                );
            }

            throw $streamError;
        }
    }

    public function compressTar(string $tarPath, string $liftPath, ?callable $progressCallback = null): string
    {
        Filesystem::ensureDirectory(dirname($liftPath));
        @unlink($liftPath);
        $chain = apply_filters(
            'lift_teleport_compression_fallback_chain',
            [self::ALGO_ZSTD, self::ALGO_GZIP, self::ALGO_NONE],
            $tarPath,
            $liftPath
        );
        if (! is_array($chain) || $chain === []) {
            $chain = [self::ALGO_ZSTD, self::ALGO_GZIP, self::ALGO_NONE];
        }

        $normalizedChain = [];
        foreach ($chain as $algo) {
            $candidate = strtolower(trim((string) $algo));
            if (! in_array($candidate, [self::ALGO_ZSTD, self::ALGO_GZIP, self::ALGO_NONE], true)) {
                continue;
            }

            if (! in_array($candidate, $normalizedChain, true)) {
                $normalizedChain[] = $candidate;
            }
        }
        if ($normalizedChain === []) {
            $normalizedChain = [self::ALGO_ZSTD, self::ALGO_GZIP, self::ALGO_NONE];
        }

        $stallTimeout = (int) apply_filters('lift_teleport_compression_stall_timeout_seconds', 90, $tarPath, $liftPath);
        $hardTimeout = (int) apply_filters('lift_teleport_compression_hard_timeout_seconds', 3600, $tarPath, $liftPath);
        $tickSeconds = (float) apply_filters('lift_teleport_compression_progress_tick_seconds', 1.0, $tarPath, $liftPath);

        $attempt = 0;
        $errors = [];
        $inputBytes = file_exists($tarPath) ? (int) filesize($tarPath) : 0;

        foreach ($normalizedChain as $algo) {
            $attempt++;
            $stage = $attempt === 1 ? 'primary' : 'fallback';
            $outPart = $liftPath . '.part';
            @unlink($outPart);

            if ($progressCallback !== null) {
                $progressCallback([
                    'phase' => 'compress_package',
                    'status' => 'attempt_started',
                    'algorithm' => $algo,
                    'attempt' => $attempt,
                    'fallback_stage' => $stage,
                    'elapsed_seconds' => 0,
                    'bytes_done' => 0,
                    'bytes_total' => $inputBytes,
                    'output_bytes' => 0,
                ]);
            }

            try {
                $resolved = $this->compressWithAlgorithm(
                    $algo,
                    $tarPath,
                    $outPart,
                    $attempt,
                    $stage,
                    $inputBytes,
                    max(15, $stallTimeout),
                    max($hardTimeout, max(15, $stallTimeout)),
                    max(0.2, $tickSeconds),
                    $progressCallback
                );

                if (! file_exists($outPart) || (int) filesize($outPart) <= 0) {
                    throw new RuntimeException(sprintf('Compression output is missing/empty for algorithm %s.', $algo));
                }

                @unlink($liftPath);
                if (! @rename($outPart, $liftPath)) {
                    if (! @copy($outPart, $liftPath)) {
                        throw new RuntimeException(sprintf('Unable to move compressed output for algorithm %s.', $algo));
                    }
                    @unlink($outPart);
                }

                if ($progressCallback !== null) {
                    $progressCallback([
                        'phase' => 'compress_package',
                        'status' => 'completed',
                        'algorithm' => $resolved,
                        'attempt' => $attempt,
                        'fallback_stage' => $stage,
                        'bytes_done' => file_exists($liftPath) ? (int) filesize($liftPath) : 0,
                        'bytes_total' => $inputBytes,
                        'output_bytes' => file_exists($liftPath) ? (int) filesize($liftPath) : 0,
                    ]);
                }

                return $resolved;
            } catch (Throwable $error) {
                $errors[] = sprintf('%s: %s', $algo, $error->getMessage());
                @unlink($outPart);

                if ($progressCallback !== null) {
                    $progressCallback([
                        'phase' => 'compress_package',
                        'status' => 'stalled',
                        'algorithm' => $algo,
                        'attempt' => $attempt,
                        'fallback_stage' => $stage,
                        'error_message' => $error->getMessage(),
                        'bytes_done' => 0,
                        'bytes_total' => $inputBytes,
                        'output_bytes' => 0,
                    ]);
                }

                continue;
            }
        }

        throw new RuntimeException('Compression failed after fallbacks: ' . implode(' | ', $errors));
    }

    private function compressWithAlgorithm(
        string $algo,
        string $tarPath,
        string $outputPath,
        int $attempt,
        string $stage,
        int $inputBytes,
        int $stallTimeout,
        int $hardTimeout,
        float $tickSeconds,
        ?callable $progressCallback = null
    ): string {
        if ($algo === self::ALGO_ZSTD) {
            if (! CommandRunner::commandExists('zstd')) {
                throw new RuntimeException('zstd binary is not available.');
            }

            $command = sprintf(
                'zstd -q -f %s -o %s',
                escapeshellarg($tarPath),
                escapeshellarg($outputPath)
            );

            $this->runCompressionCommand(
                $command,
                $outputPath,
                self::ALGO_ZSTD,
                $attempt,
                $stage,
                $inputBytes,
                $stallTimeout,
                $hardTimeout,
                $tickSeconds,
                $progressCallback
            );

            return self::ALGO_ZSTD;
        }

        if ($algo === self::ALGO_GZIP) {
            if (! CommandRunner::commandExists('gzip')) {
                throw new RuntimeException('gzip binary is not available.');
            }

            $command = sprintf(
                'gzip -c -9 %s > %s',
                escapeshellarg($tarPath),
                escapeshellarg($outputPath)
            );

            $this->runCompressionCommand(
                $command,
                $outputPath,
                self::ALGO_GZIP,
                $attempt,
                $stage,
                $inputBytes,
                $stallTimeout,
                $hardTimeout,
                $tickSeconds,
                $progressCallback
            );

            return self::ALGO_GZIP;
        }

        if ($algo === self::ALGO_NONE) {
            Filesystem::copyFile($tarPath, $outputPath);
            if ($progressCallback !== null) {
                $progressCallback([
                    'phase' => 'compress_package',
                    'status' => 'running',
                    'algorithm' => self::ALGO_NONE,
                    'attempt' => $attempt,
                    'fallback_stage' => $stage,
                    'elapsed_seconds' => 0,
                    'bytes_done' => file_exists($outputPath) ? (int) filesize($outputPath) : $inputBytes,
                    'bytes_total' => max(1, $inputBytes),
                    'output_bytes' => file_exists($outputPath) ? (int) filesize($outputPath) : $inputBytes,
                ]);
            }

            return self::ALGO_NONE;
        }

        throw new RuntimeException(sprintf('Unsupported compression algorithm: %s', $algo));
    }

    private function runCompressionCommand(
        string $command,
        string $outputPath,
        string $algorithm,
        int $attempt,
        string $stage,
        int $inputBytes,
        int $stallTimeout,
        int $hardTimeout,
        float $tickSeconds,
        ?callable $progressCallback = null
    ): void {
        CommandRunner::runMonitored($command, [
            'output_path' => $outputPath,
            'tick_seconds' => $tickSeconds,
            'stall_timeout_seconds' => $stallTimeout,
            'hard_timeout_seconds' => $hardTimeout,
            'progress_callback' => function (array $state) use (
                $progressCallback,
                $algorithm,
                $attempt,
                $stage,
                $inputBytes
            ): void {
                if ($progressCallback === null) {
                    return;
                }

                $outputBytes = isset($state['output_bytes']) ? (int) $state['output_bytes'] : 0;
                $progressCallback([
                    'phase' => 'compress_package',
                    'status' => 'running',
                    'algorithm' => $algorithm,
                    'attempt' => $attempt,
                    'fallback_stage' => $stage,
                    'elapsed_seconds' => isset($state['elapsed_seconds']) ? (float) $state['elapsed_seconds'] : 0.0,
                    'bytes_done' => max(0, $outputBytes),
                    'bytes_total' => max(1, $inputBytes),
                    'output_bytes' => max(0, $outputBytes),
                ]);
            },
        ]);
    }

    public function decompressToTar(string $liftPath, string $tarPath): string
    {
        Filesystem::ensureDirectory(dirname($tarPath));
        @unlink($tarPath);

        $algo = $this->detectCompression($liftPath);

        if ($algo === self::ALGO_ZSTD) {
            if (! CommandRunner::commandExists('zstd')) {
                throw new RuntimeException('zstd is required to decompress this archive but is not available.');
            }

            $command = sprintf(
                'zstd -q -d -f %s -o %s',
                escapeshellarg($liftPath),
                escapeshellarg($tarPath)
            );
            CommandRunner::run($command);
            return $algo;
        }

        if ($algo === self::ALGO_GZIP) {
            if (CommandRunner::commandExists('gzip')) {
                $command = sprintf(
                    'gzip -dc %s > %s',
                    escapeshellarg($liftPath),
                    escapeshellarg($tarPath)
                );
                CommandRunner::run($command);
                return $algo;
            }

            $gz = gzopen($liftPath, 'rb');
            if ($gz === false) {
                throw new RuntimeException('Unable to open gzip archive.');
            }

            $target = fopen($tarPath, 'wb');
            if ($target === false) {
                gzclose($gz);
                throw new RuntimeException('Unable to create temporary tar file.');
            }

            while (! gzeof($gz)) {
                $chunk = gzread($gz, 1024 * 1024);
                if ($chunk === false) {
                    break;
                }
                fwrite($target, $chunk);
            }

            fclose($target);
            gzclose($gz);

            return $algo;
        }

        Filesystem::copyFile($liftPath, $tarPath);
        return self::ALGO_NONE;
    }

    public function extractTar(string $tarPath, string $destination): void
    {
        $this->extractTarSecure($tarPath, $destination);
    }

    public function extractTarSecure(string $tarPath, string $destination): void
    {
        Filesystem::ensureDirectory($destination);
        $entries = $this->listTarEntries($tarPath);
        $this->assertSafeEntries($entries);

        if (CommandRunner::commandExists('tar')) {
            $command = sprintf(
                'tar -xf %s -C %s --no-same-owner --no-same-permissions',
                escapeshellarg($tarPath),
                escapeshellarg($destination)
            );
            CommandRunner::run($command);
            return;
        }

        $tarSize = @filesize($tarPath);
        if (is_int($tarSize) && $tarSize > 2147483647) {
            // PharData extraction over 2GB is unreliable on some hosts; stream TAR manually.
            $this->extractTarByStream($tarPath, $destination);
            return;
        }

        try {
            $phar = new PharData($tarPath);
            $phar->extractTo($destination, null, true);
        } catch (Throwable) {
            // Fallback to stream extraction when PharData is unavailable or constrained.
            $this->extractTarByStream($tarPath, $destination);
        }
    }

    /**
     * @return array<int,array{path:string,type:string}>
     */
    public function listTarEntries(string $tarPath): array
    {
        return (new TarInspector())->listEntries($tarPath);
    }

    /**
     * @param array<int,array{path:string,type:string}> $entries
     */
    private function assertSafeEntries(array $entries): void
    {
        foreach ($entries as $entry) {
            $rawPath = str_replace('\\', '/', (string) ($entry['path'] ?? ''));
            $path = (string) preg_replace('#^\./+#', '', $rawPath);
            $path = trim($path, '/');
            $type = (string) ($entry['type'] ?? 'file');

            if ($rawPath === '' || str_contains($rawPath, "\0")) {
                throw new RuntimeException('Archive contains invalid empty path entry.');
            }

            if (str_starts_with($rawPath, '/') || preg_match('/^[a-zA-Z]:\//', $rawPath) === 1) {
                throw new RuntimeException(sprintf('Archive contains absolute path entry: %s', $path));
            }

            if (preg_match('/[\x00-\x1F]/', $rawPath) === 1) {
                throw new RuntimeException(sprintf('Archive contains invalid control characters: %s', $path));
            }

            // Common TAR builders emit root directory placeholders such as "./" or "./.".
            if ($path === '' || $path === '.') {
                continue;
            }

            $segments = explode('/', $path);
            foreach ($segments as $segment) {
                if ($segment === '..') {
                    throw new RuntimeException(sprintf('Archive contains unsafe traversal entry: %s', $path));
                }
            }

            if (in_array($type, ['symlink', 'hardlink'], true)) {
                throw new RuntimeException(sprintf('Archive contains forbidden link entry: %s', $path));
            }

            $allowed = (bool) apply_filters('lift_teleport_archive_allowed_entry', true, $path, $type);
            if (! $allowed) {
                throw new RuntimeException(sprintf('Archive entry is blocked by policy: %s', $path));
            }
        }
    }

    public function detectCompression(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to read package file.');
        }

        $bytes = fread($handle, 4);
        fclose($handle);

        if ($bytes === "\x28\xB5\x2F\xFD") {
            return self::ALGO_ZSTD;
        }

        if (substr($bytes, 0, 2) === "\x1F\x8B") {
            return self::ALGO_GZIP;
        }

        return self::ALGO_NONE;
    }

    /**
     * @param array<int,array{source:string,target:string}> $entries
     * @return array<int,array{source:string,target:string,size:int}>
     */
    private function expandTarEntries(array $entries): array
    {
        $files = [];
        $added = [];

        foreach ($entries as $entry) {
            $source = isset($entry['source']) ? (string) $entry['source'] : '';
            $target = isset($entry['target']) ? (string) $entry['target'] : '';
            if ($source === '' || $target === '') {
                continue;
            }

            $target = ltrim(str_replace('\\', '/', $target), '/');
            if ($target === '' || ! $this->isSafeEntryPath($target)) {
                throw new RuntimeException(sprintf('Unsafe archive target path: %s', $target));
            }

            if (is_dir($source)) {
                foreach ($this->collectDirectoryFiles($source, $target) as $file) {
                    $fileTarget = (string) ($file['target'] ?? '');
                    if ($fileTarget === '' || isset($added[$fileTarget])) {
                        continue;
                    }

                    $files[] = $file;
                    $added[$fileTarget] = true;
                }

                continue;
            }

            $realSource = realpath($source);
            if (! is_string($realSource) || $realSource === '' || ! is_file($realSource) || ! is_readable($realSource)) {
                throw new RuntimeException(sprintf('Missing or unreadable archive source: %s', $source));
            }

            if (isset($added[$target])) {
                continue;
            }

            $files[] = [
                'source' => str_replace('\\', '/', $realSource),
                'target' => $target,
                'size' => (int) filesize($realSource),
            ];
            $added[$target] = true;
        }

        return $files;
    }

    /**
     * @return array<int,array{source:string,target:string,size:int}>
     */
    private function collectDirectoryFiles(string $sourceDir, string $targetPrefix): array
    {
        $sourceRoot = realpath($sourceDir);
        if (! is_string($sourceRoot) || $sourceRoot === '' || ! is_dir($sourceRoot)) {
            throw new RuntimeException(sprintf('Directory source not found: %s', $sourceDir));
        }

        $sourceRoot = rtrim(str_replace('\\', '/', $sourceRoot), '/');
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $item) {
            if (! $item instanceof \SplFileInfo || ! $item->isFile()) {
                continue;
            }

            $sourcePath = (string) $item->getPathname();
            $realSource = realpath($sourcePath);
            if (! is_string($realSource) || $realSource === '' || ! is_readable($realSource)) {
                continue;
            }

            $realSource = str_replace('\\', '/', $realSource);
            if (! str_starts_with($realSource, $sourceRoot . '/')) {
                continue;
            }

            $relative = ltrim(substr($realSource, strlen($sourceRoot)), '/');
            if ($relative === '') {
                continue;
            }

            $target = trim($targetPrefix, '/') . '/' . str_replace('\\', '/', $relative);
            $target = ltrim($target, '/');
            if ($target === '' || ! $this->isSafeEntryPath($target)) {
                continue;
            }

            $files[] = [
                'source' => $realSource,
                'target' => $target,
                'size' => (int) filesize($realSource),
            ];
        }

        return $files;
    }

    private function isSafeEntryPath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\//', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function extractTarByStream(string $tarPath, string $destination): void
    {
        $handle = @fopen($tarPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open TAR archive: %s', $tarPath));
        }

        $globalPax = [];
        $nextPax = [];
        $nextLongName = '';

        try {
            while (true) {
                $header = $this->readExact($handle, 512);
                if ($header === '') {
                    break;
                }

                if (strlen($header) !== 512) {
                    throw new RuntimeException('Invalid TAR header: archive appears truncated.');
                }

                if ($this->isZeroBlock($header)) {
                    break;
                }

                $name = $this->readHeaderString($header, 0, 100);
                $prefix = $this->readHeaderString($header, 345, 155);
                $typeFlag = $header[156] ?? "\0";
                $size = $this->parseTarNumericField((string) substr($header, 124, 12));

                $path = $name;
                if ($prefix !== '') {
                    $path = $prefix . '/' . $name;
                }
                $path = ltrim(str_replace('\\', '/', $path), '/');

                if ($typeFlag === 'x' || $typeFlag === 'g') {
                    $data = $this->readData($handle, $size);
                    $pax = $this->parsePaxRecords($data);
                    if ($typeFlag === 'g') {
                        $globalPax = array_merge($globalPax, $pax);
                    } else {
                        $nextPax = array_merge($nextPax, $pax);
                    }
                    $this->skipPadding($handle, $size);
                    continue;
                }

                if ($typeFlag === 'L') {
                    $data = $this->readData($handle, $size);
                    $nextLongName = trim(str_replace('\\', '/', rtrim($data, "\0\r\n")));
                    $this->skipPadding($handle, $size);
                    continue;
                }

                $pax = array_merge($globalPax, $nextPax);
                if ($nextLongName !== '') {
                    $path = ltrim($nextLongName, '/');
                } elseif (isset($pax['path']) && is_string($pax['path'])) {
                    $path = ltrim(str_replace('\\', '/', $pax['path']), '/');
                }

                $normalized = str_replace('\\', '/', $path);
                $normalized = (string) preg_replace('#^\./+#', '', $normalized);
                $normalized = trim($normalized, '/');
                if ($normalized === '' || $normalized === '.') {
                    $this->skipData($handle, $size);
                    $this->skipPadding($handle, $size);
                    $nextPax = [];
                    $nextLongName = '';
                    continue;
                }

                $baseName = basename($normalized);
                if ($baseName === '.DS_Store' || $baseName === 'Thumbs.db' || str_starts_with($baseName, '._')) {
                    $this->skipData($handle, $size);
                    $this->skipPadding($handle, $size);
                    $nextPax = [];
                    $nextLongName = '';
                    continue;
                }

                $targetPath = rtrim($destination, '/\\') . '/' . $normalized;
                if (! $this->isSafeEntryPath($normalized)) {
                    throw new RuntimeException(sprintf('Archive contains unsafe path: %s', $normalized));
                }

                if ($typeFlag === '5') {
                    Filesystem::ensureDirectory($targetPath);
                    $this->skipData($handle, $size);
                    $this->skipPadding($handle, $size);
                    $nextPax = [];
                    $nextLongName = '';
                    continue;
                }

                if (in_array($typeFlag, ['1', '2'], true)) {
                    throw new RuntimeException(sprintf('Archive contains forbidden link entry: %s', $normalized));
                }

                Filesystem::ensureDirectory(dirname($targetPath));
                $out = @fopen($targetPath, 'wb');
                if ($out === false) {
                    throw new RuntimeException(sprintf('Unable to write extracted file: %s', $targetPath));
                }

                try {
                    $remaining = $size;
                    while ($remaining > 0) {
                        $chunk = fread($handle, min(1048576, $remaining));
                        if (! is_string($chunk) || $chunk === '') {
                            throw new RuntimeException('Invalid TAR archive: archive appears truncated.');
                        }

                        $written = fwrite($out, $chunk);
                        if (! is_int($written) || $written !== strlen($chunk)) {
                            throw new RuntimeException(sprintf('Unable to write extracted file: %s', $targetPath));
                        }

                        $remaining -= strlen($chunk);
                    }
                } finally {
                    fclose($out);
                }

                $this->skipPadding($handle, $size);
                $nextPax = [];
                $nextLongName = '';
            }
        } finally {
            fclose($handle);
        }
    }

    private function readHeaderString(string $buffer, int $offset, int $length): string
    {
        $value = substr($buffer, $offset, $length);
        if (! is_string($value)) {
            return '';
        }

        return trim($value, "\0 ");
    }

    private function parseTarNumericField(string $field): int
    {
        if ($field === '') {
            return 0;
        }

        $first = ord($field[0]);
        if (($first & 0x80) === 0x80) {
            $bytes = unpack('C*', $field);
            if (! is_array($bytes) || $bytes === []) {
                return 0;
            }

            $value = (int) ($bytes[1] & 0x7F);
            $count = count($bytes);
            for ($i = 2; $i <= $count; $i++) {
                $value = ($value << 8) | (int) $bytes[$i];
            }

            return max(0, $value);
        }

        $value = trim($field, "\0 ");
        if ($value === '') {
            return 0;
        }

        if (preg_match('/^[0-7]+$/', $value) === 1) {
            return (int) octdec($value);
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        throw new RuntimeException('Invalid TAR numeric field encountered.');
    }

    private function readData($handle, int $size): string
    {
        if ($size <= 0) {
            return '';
        }

        $remaining = $size;
        $chunks = [];
        while ($remaining > 0) {
            $chunk = fread($handle, min(1048576, $remaining));
            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('Invalid TAR archive: archive appears truncated.');
            }

            $chunks[] = $chunk;
            $remaining -= strlen($chunk);
        }

        return implode('', $chunks);
    }

    private function skipData($handle, int $size): void
    {
        if ($size <= 0) {
            return;
        }

        if (@fseek($handle, $size, SEEK_CUR) === 0) {
            return;
        }

        $remaining = $size;
        while ($remaining > 0) {
            $chunk = fread($handle, min(1048576, $remaining));
            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('Invalid TAR archive: archive appears truncated.');
            }

            $remaining -= strlen($chunk);
        }
    }

    private function skipPadding($handle, int $size): void
    {
        if ($size <= 0) {
            return;
        }

        $padding = (512 - ($size % 512)) % 512;
        if ($padding <= 0) {
            return;
        }

        $this->skipData($handle, $padding);
    }

    private function isZeroBlock(string $block): bool
    {
        return trim($block, "\0") === '';
    }

    /**
     * @return array<string,string>
     */
    private function parsePaxRecords(string $data): array
    {
        $records = [];
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $spacePos = strpos($data, ' ', $offset);
            if ($spacePos === false) {
                break;
            }

            $lenRaw = substr($data, $offset, $spacePos - $offset);
            if ($lenRaw === '' || preg_match('/^\d+$/', $lenRaw) !== 1) {
                break;
            }

            $recordLen = (int) $lenRaw;
            if ($recordLen <= 0 || ($offset + $recordLen) > $length) {
                break;
            }

            $record = substr($data, $offset, $recordLen);
            if (! is_string($record) || $record === '') {
                break;
            }

            $payload = substr($record, strlen($lenRaw) + 1);
            if (! is_string($payload) || $payload === '') {
                $offset += $recordLen;
                continue;
            }

            $payload = rtrim($payload, "\n");
            $eqPos = strpos($payload, '=');
            if ($eqPos !== false) {
                $key = substr($payload, 0, $eqPos);
                $value = substr($payload, $eqPos + 1);
                if (is_string($key) && $key !== '' && is_string($value)) {
                    $records[$key] = $value;
                }
            }

            $offset += $recordLen;
        }

        return $records;
    }

    private function readExact($handle, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($handle, $length - strlen($buffer));
            if (! is_string($chunk) || $chunk === '') {
                return $buffer;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }
}
