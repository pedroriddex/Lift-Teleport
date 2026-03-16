<?php

declare(strict_types=1);

namespace LiftTeleport\Archive;

use LiftTeleport\Support\Filesystem;
use RuntimeException;

final class TarStreamWriter
{
    private const TAR_BLOCK_SIZE = 512;
    private const DEFAULT_CHUNK_BYTES = 1048576;
    private const MAX_OCTAL_SIZE = 8589934591; // 077777777777 (11 octal digits)

    /**
     * @param array<int,array{source:string,target:string,size:int}> $files
     */
    public function createTar(array $files, string $tarPath, ?callable $progressCallback = null): void
    {
        Filesystem::ensureDirectory(dirname($tarPath));
        @unlink($tarPath);

        $handle = fopen($tarPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to create tar archive: %s', $tarPath));
        }

        $filesTotal = count($files);
        $bytesTotal = 0;
        foreach ($files as $file) {
            $bytesTotal += max(0, (int) ($file['size'] ?? 0));
        }

        $filesDone = 0;
        $bytesDone = 0;
        $lastTickAt = microtime(true);
        $tickSeconds = max(0.2, (float) apply_filters('lift_teleport_tar_stream_tick_seconds', 0.5));

        try {
            $this->emit($progressCallback, [
                'phase' => 'tar_build',
                'engine' => 'php_stream',
                'status' => 'running',
                'files_done' => 0,
                'files_total' => $filesTotal,
                'bytes_done' => 0,
                'bytes_total' => $bytesTotal,
            ]);

            foreach ($files as $file) {
                $source = (string) ($file['source'] ?? '');
                $target = ltrim(str_replace('\\', '/', (string) ($file['target'] ?? '')), '/');

                if ($source === '' || $target === '' || ! is_file($source) || ! is_readable($source)) {
                    throw new RuntimeException(sprintf('Invalid file while writing tar stream: %s', $source));
                }

                if (! $this->isSafeEntryPath($target)) {
                    throw new RuntimeException(sprintf('Unsafe tar entry target: %s', $target));
                }

                $size = max(0, (int) filesize($source));
                $mtime = max(0, (int) @filemtime($source));

                $requiresPax = strlen($target) > 100 || $size > self::MAX_OCTAL_SIZE;
                if ($requiresPax) {
                    $this->writePaxHeader($handle, $target, $size, $mtime);
                }

                [$name, $prefix] = $this->splitTarPath($target);
                $header = $this->buildHeader($name, $prefix, $size, $mtime, '0');
                $this->writeBlock($handle, $header);

                $sourceHandle = fopen($source, 'rb');
                if ($sourceHandle === false) {
                    throw new RuntimeException(sprintf('Unable to open source file for tar stream: %s', $source));
                }

                while (! feof($sourceHandle)) {
                    $chunk = fread($sourceHandle, self::DEFAULT_CHUNK_BYTES);
                    if ($chunk === false) {
                        fclose($sourceHandle);
                        throw new RuntimeException(sprintf('Unable to read source file while streaming tar: %s', $source));
                    }

                    if ($chunk === '') {
                        continue;
                    }

                    $written = fwrite($handle, $chunk);
                    if ($written !== strlen($chunk)) {
                        fclose($sourceHandle);
                        throw new RuntimeException(sprintf('Unable to write tar stream for file: %s', $source));
                    }

                    $bytesDone += $written;
                    $now = microtime(true);
                    if (($now - $lastTickAt) >= $tickSeconds) {
                        $lastTickAt = $now;
                        $this->emit($progressCallback, [
                            'phase' => 'tar_build',
                            'engine' => 'php_stream',
                            'status' => 'running',
                            'files_done' => $filesDone,
                            'files_total' => $filesTotal,
                            'bytes_done' => $bytesDone,
                            'bytes_total' => $bytesTotal,
                            'output_bytes' => (int) ftell($handle),
                        ]);
                    }
                }

                fclose($sourceHandle);
                $this->padToBlock($handle, $size);

                $filesDone++;
                $this->emit($progressCallback, [
                    'phase' => 'tar_build',
                    'engine' => 'php_stream',
                    'status' => 'running',
                    'files_done' => $filesDone,
                    'files_total' => $filesTotal,
                    'bytes_done' => $bytesDone,
                    'bytes_total' => $bytesTotal,
                    'output_bytes' => (int) ftell($handle),
                ]);
            }

            $this->writeBlock($handle, str_repeat("\0", self::TAR_BLOCK_SIZE));
            $this->writeBlock($handle, str_repeat("\0", self::TAR_BLOCK_SIZE));
            fflush($handle);
            fclose($handle);

            if (! file_exists($tarPath) || (int) filesize($tarPath) <= 0) {
                throw new RuntimeException('PHP stream tar writer produced an empty archive.');
            }

            $this->emit($progressCallback, [
                'phase' => 'tar_build',
                'engine' => 'php_stream',
                'status' => 'completed',
                'files_done' => $filesTotal,
                'files_total' => $filesTotal,
                'bytes_done' => $bytesDone,
                'bytes_total' => $bytesTotal,
                'output_bytes' => (int) filesize($tarPath),
            ]);
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($tarPath);
            throw $error;
        }
    }

    private function writePaxHeader($handle, string $targetPath, int $size, int $mtime): void
    {
        $records = [];
        $records[] = $this->buildPaxRecord('path', $targetPath);
        if ($size > self::MAX_OCTAL_SIZE) {
            $records[] = $this->buildPaxRecord('size', (string) $size);
        }
        $records[] = $this->buildPaxRecord('mtime', (string) $mtime);

        $payload = implode('', $records);
        $paxName = 'PaxHeaders/' . substr(sha1($targetPath), 0, 32);
        [$name, $prefix] = $this->splitTarPath($paxName);
        $header = $this->buildHeader($name, $prefix, strlen($payload), $mtime, 'x');
        $this->writeBlock($handle, $header);
        $this->writeData($handle, $payload);
        $this->padToBlock($handle, strlen($payload));
    }

    private function buildPaxRecord(string $key, string $value): string
    {
        $recordBody = $key . '=' . $value . "\n";
        $length = strlen($recordBody) + 3;

        while (true) {
            $record = $length . ' ' . $recordBody;
            $newLength = strlen($record);
            if ($newLength === $length) {
                return $record;
            }
            $length = $newLength;
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitTarPath(string $path): array
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (strlen($path) <= 100) {
            return [$path, ''];
        }

        $slash = strrpos($path, '/');
        if ($slash !== false) {
            $prefix = substr($path, 0, $slash);
            $name = substr($path, $slash + 1);
            if (strlen($name) <= 100 && strlen($prefix) <= 155) {
                return [$name, $prefix];
            }
        }

        $basename = basename($path);
        if ($basename === '') {
            $basename = 'file';
        }
        return [substr($basename, 0, 100), ''];
    }

    private function buildHeader(string $name, string $prefix, int $size, int $mtime, string $typeFlag): string
    {
        $header = '';
        $header .= str_pad($name, 100, "\0");
        $header .= $this->formatOctal(0644, 8);
        $header .= $this->formatOctal(0, 8);
        $header .= $this->formatOctal(0, 8);
        $header .= $this->formatOctal($size, 12);
        $header .= $this->formatOctal($mtime, 12);
        $header .= str_repeat(' ', 8);
        $header .= substr($typeFlag, 0, 1);
        $header .= str_repeat("\0", 100);
        $header .= "ustar\0";
        $header .= '00';
        $header .= str_repeat("\0", 32);
        $header .= str_repeat("\0", 32);
        $header .= str_repeat("\0", 8);
        $header .= str_repeat("\0", 8);
        $header .= str_pad($prefix, 155, "\0");
        $header .= str_repeat("\0", 12);

        if (strlen($header) !== self::TAR_BLOCK_SIZE) {
            throw new RuntimeException('Invalid tar header size generated.');
        }

        $checksum = 0;
        for ($i = 0; $i < strlen($header); $i++) {
            $checksum += ord($header[$i]);
        }

        $checksumField = str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT) . "\0 ";
        $header = substr_replace($header, $checksumField, 148, 8);

        return $header;
    }

    private function formatOctal(int $value, int $length): string
    {
        $value = max(0, $value);
        $digits = $length - 1;
        $octal = decoct($value);

        if (strlen($octal) > $digits) {
            $octal = str_repeat('7', $digits);
        }

        return str_pad($octal, $digits, '0', STR_PAD_LEFT) . "\0";
    }

    private function writeData($handle, string $data): void
    {
        if ($data === '') {
            return;
        }

        $written = fwrite($handle, $data);
        if ($written !== strlen($data)) {
            throw new RuntimeException('Unable to write tar payload data.');
        }
    }

    private function writeBlock($handle, string $block): void
    {
        if (strlen($block) !== self::TAR_BLOCK_SIZE) {
            throw new RuntimeException('Tar block size must be 512 bytes.');
        }

        $written = fwrite($handle, $block);
        if ($written !== self::TAR_BLOCK_SIZE) {
            throw new RuntimeException('Unable to write tar block.');
        }
    }

    private function padToBlock($handle, int $size): void
    {
        $remainder = $size % self::TAR_BLOCK_SIZE;
        if ($remainder === 0) {
            return;
        }

        $padding = self::TAR_BLOCK_SIZE - $remainder;
        $this->writeData($handle, str_repeat("\0", $padding));
    }

    private function isSafeEntryPath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\//', $path) === 1) {
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

    private function emit(?callable $progressCallback, array $metrics): void
    {
        if ($progressCallback !== null) {
            $progressCallback($metrics);
        }
    }
}

