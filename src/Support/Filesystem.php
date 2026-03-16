<?php

declare(strict_types=1);

namespace LiftTeleport\Support;

use RuntimeException;

final class Filesystem
{
    public static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! wp_mkdir_p($path)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $path));
        }
    }

    public static function hardenDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $indexFile = rtrim($path, '/') . '/index.php';
        if (! file_exists($indexFile)) {
            file_put_contents($indexFile, "<?php\n");
        }

        $htaccess = rtrim($path, '/') . '/.htaccess';
        if (! file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        $webConfig = rtrim($path, '/') . '/web.config';
        if (! file_exists($webConfig)) {
            file_put_contents($webConfig, "<configuration><system.webServer><authorization><deny users=\"*\"/></authorization></system.webServer></configuration>\n");
        }
    }

    public static function deletePath(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    public static function copyFile(string $source, string $target): void
    {
        self::ensureDirectory(dirname($target));
        if (! copy($source, $target)) {
            throw new RuntimeException(sprintf('Failed to copy %s to %s', $source, $target));
        }
    }

    public static function syncDirectory(string $source, string $target, ?callable $exclude = null): void
    {
        if (! is_dir($source)) {
            return;
        }

        self::ensureDirectory($target);

        $sourceEntries = scandir($source);
        if ($sourceEntries === false) {
            throw new RuntimeException(sprintf('Cannot scan directory: %s', $source));
        }

        $allowed = [];
        foreach ($sourceEntries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $source . '/' . $entry;
            $targetPath = $target . '/' . $entry;
            $relative = ltrim(str_replace($source . '/', '', $sourcePath), '/');

            if ($exclude && $exclude($relative, $sourcePath, $targetPath) === true) {
                $allowed[$entry] = true;
                continue;
            }

            $allowed[$entry] = true;

            if (is_link($sourcePath)) {
                $resolved = realpath($sourcePath);
                if ($resolved === false) {
                    continue;
                }

                if (is_dir($resolved)) {
                    self::syncDirectory($resolved, $targetPath, $exclude ? function (string $childRelative, string $childSource, string $childTarget) use ($exclude, $entry): bool {
                        return $exclude($entry . '/' . $childRelative, $childSource, $childTarget) === true;
                    } : null);
                } else {
                    self::copyFile($resolved, $targetPath);
                }

                continue;
            }

            if (is_dir($sourcePath)) {
                self::syncDirectory($sourcePath, $targetPath, $exclude ? function (string $childRelative, string $childSource, string $childTarget) use ($exclude, $entry): bool {
                    return $exclude($entry . '/' . $childRelative, $childSource, $childTarget) === true;
                } : null);
            } else {
                self::copyFile($sourcePath, $targetPath);
            }
        }

        $targetEntries = scandir($target);
        if ($targetEntries === false) {
            return;
        }

        foreach ($targetEntries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $source . '/' . $entry;
            $targetPath = $target . '/' . $entry;
            $relative = trim(str_replace('\\', '/', $entry), '/');

            if ($exclude && $exclude($relative, $sourcePath, $targetPath) === true) {
                continue;
            }

            if (! isset($allowed[$entry])) {
                self::deletePath($targetPath);
            }
        }
    }

    public static function directorySize(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $size += $item->getSize();
            }
        }

        return $size;
    }

    public static function checkDiskSpace(string $path, int $required): bool
    {
        $available = self::freeDiskSpace($path);
        if ($available === null) {
            return true;
        }

        return $available >= $required;
    }

    public static function freeDiskSpace(string $path): ?int
    {
        $target = trim($path);
        if ($target === '') {
            return null;
        }

        // Walk up until an existing path is found so disk_free_space can resolve mounts reliably.
        while (! file_exists($target)) {
            $parent = dirname($target);
            if ($parent === $target) {
                return null;
            }
            $target = $parent;
        }

        $available = @disk_free_space($target);
        if ($available === false || ! is_numeric($available)) {
            return null;
        }

        $bytes = (float) $available;
        if ($bytes < 0) {
            return null;
        }

        if ($bytes > (float) PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return (int) floor($bytes);
    }

    /**
     * @param array<int,string> $paths
     */
    public static function maxFreeDiskSpace(array $paths): ?int
    {
        $known = [];

        foreach ($paths as $path) {
            $bytes = self::freeDiskSpace($path);
            if ($bytes !== null) {
                $known[] = $bytes;
            }
        }

        if ($known === []) {
            return null;
        }

        return max($known);
    }
}
