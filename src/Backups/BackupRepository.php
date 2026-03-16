<?php

declare(strict_types=1);

namespace LiftTeleport\Backups;

use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;
use RuntimeException;

final class BackupRepository
{
    public const OPTION_INDEX_KEY = 'lift_teleport_backups_index';

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function createFromExportFile(string $sourceFile, array $context = []): array
    {
        $sourceFile = trim($sourceFile);
        if ($sourceFile === '' || ! file_exists($sourceFile) || ! is_readable($sourceFile)) {
            throw new RuntimeException('Export file for backup was not found.');
        }

        Paths::ensureBaseDirs();
        $backupsRoot = $this->backupsRoot();
        Filesystem::ensureDirectory($backupsRoot);
        Filesystem::hardenDirectory($backupsRoot);

        $id = $this->generateId();
        $sourceBase = basename($sourceFile);
        $requestedName = isset($context['filename']) && is_string($context['filename'])
            ? $context['filename']
            : $sourceBase;

        $backupFileName = (string) apply_filters('lift_teleport_backup_filename', $requestedName, $sourceFile, $context);
        $backupFileName = $this->sanitizeFileName($backupFileName);
        if ($backupFileName === '') {
            $backupFileName = 'backup-' . $id . '.lift';
        }

        if (! str_ends_with(strtolower($backupFileName), '.lift')) {
            $backupFileName .= '.lift';
        }

        $targetPath = $backupsRoot . '/' . $backupFileName;
        if (file_exists($targetPath)) {
            $targetPath = $backupsRoot . '/' . pathinfo($backupFileName, PATHINFO_FILENAME) . '-' . $id . '.lift';
            $backupFileName = basename($targetPath);
        }

        $tmpPath = $backupsRoot . '/.' . $backupFileName . '.tmp-' . $id;
        @unlink($tmpPath);

        Filesystem::copyFile($sourceFile, $tmpPath);
        if (! @rename($tmpPath, $targetPath)) {
            Filesystem::copyFile($tmpPath, $targetPath);
            @unlink($tmpPath);
        }

        if (! file_exists($targetPath)) {
            throw new RuntimeException('Unable to persist export backup file.');
        }

        $record = [
            'id' => $id,
            'filename' => $backupFileName,
            'path' => $this->normalizePath($targetPath),
            'size_bytes' => max(0, (int) filesize($targetPath)),
            'created_at' => gmdate(DATE_ATOM),
            'created_by' => max(0, (int) ($context['created_by'] ?? 0)),
            'source_job_id' => max(0, (int) ($context['source_job_id'] ?? 0)),
            'download_count' => 0,
            'last_downloaded_at' => '',
            'encrypted' => ! empty($context['encrypted']),
        ];

        $items = $this->loadIndex();
        $items = array_values(array_filter($items, static function (array $item): bool {
            return isset($item['id']) && is_string($item['id']) && $item['id'] !== '';
        }));
        $items[] = $record;
        $this->saveIndex($items);

        return $record;
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>}
     */
    public function paginatedList(int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $items = $this->loadIndex();
        $items = $this->normalizeAndPruneMissing($items);
        $items = $this->sortNewestFirst($items);

        $total = count($items);
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }

        $offset = ($page - 1) * $perPage;
        $slice = array_slice($items, $offset, $perPage);

        foreach ($slice as &$item) {
            $item['download_path'] = '/wp-json/lift/v1/backups/' . rawurlencode((string) $item['id']) . '/download';
        }
        unset($item);

        return [
            'items' => array_values($slice),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages,
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        $items = $this->loadIndex();
        $changed = false;

        foreach ($items as $index => $item) {
            $itemId = isset($item['id']) && is_string($item['id']) ? $item['id'] : '';
            if ($itemId !== $id) {
                continue;
            }

            $path = isset($item['path']) && is_string($item['path']) ? $item['path'] : '';
            if (! $this->isSafeBackupPath($path) || ! file_exists($path)) {
                unset($items[$index]);
                $changed = true;
                continue;
            }

            $item['path'] = $this->normalizePath($path);
            $item['size_bytes'] = max(0, (int) filesize($item['path']));
            $item['download_path'] = '/wp-json/lift/v1/backups/' . rawurlencode($id) . '/download';

            if ($changed) {
                $this->saveIndex(array_values($items));
            }

            return $item;
        }

        if ($changed) {
            $this->saveIndex(array_values($items));
        }

        return null;
    }

    public function markDownloaded(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            return;
        }

        $items = $this->loadIndex();
        $changed = false;

        foreach ($items as &$item) {
            $itemId = isset($item['id']) && is_string($item['id']) ? $item['id'] : '';
            if ($itemId !== $id) {
                continue;
            }

            $item['download_count'] = max(0, (int) ($item['download_count'] ?? 0)) + 1;
            $item['last_downloaded_at'] = gmdate(DATE_ATOM);
            $changed = true;
            break;
        }
        unset($item);

        if ($changed) {
            $this->saveIndex($items);
        }
    }

    public function delete(string $id): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }

        $items = $this->loadIndex();
        $changed = false;
        $deleted = false;

        foreach ($items as $index => $item) {
            $itemId = isset($item['id']) && is_string($item['id']) ? $item['id'] : '';
            if ($itemId !== $id) {
                continue;
            }

            $path = isset($item['path']) && is_string($item['path']) ? $item['path'] : '';
            if ($this->isSafeBackupPath($path) && file_exists($path)) {
                Filesystem::deletePath($path);
            }

            unset($items[$index]);
            $changed = true;
            $deleted = true;
            break;
        }

        if ($changed) {
            $this->saveIndex(array_values($items));
        }

        return $deleted;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function normalizeAndPruneMissing(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = isset($item['id']) && is_string($item['id']) ? trim($item['id']) : '';
            $path = isset($item['path']) && is_string($item['path']) ? $this->normalizePath($item['path']) : '';

            if ($id === '' || ! $this->isSafeBackupPath($path) || ! file_exists($path)) {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'filename' => isset($item['filename']) && is_string($item['filename']) ? $item['filename'] : basename($path),
                'path' => $path,
                'size_bytes' => max(0, (int) filesize($path)),
                'created_at' => isset($item['created_at']) && is_string($item['created_at']) ? $item['created_at'] : '',
                'created_by' => max(0, (int) ($item['created_by'] ?? 0)),
                'source_job_id' => max(0, (int) ($item['source_job_id'] ?? 0)),
                'download_count' => max(0, (int) ($item['download_count'] ?? 0)),
                'last_downloaded_at' => isset($item['last_downloaded_at']) && is_string($item['last_downloaded_at']) ? $item['last_downloaded_at'] : '',
                'encrypted' => ! empty($item['encrypted']),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function sortNewestFirst(array $items): array
    {
        usort($items, static function (array $left, array $right): int {
            $leftTs = isset($left['created_at']) ? strtotime((string) $left['created_at']) : false;
            $rightTs = isset($right['created_at']) ? strtotime((string) $right['created_at']) : false;

            $leftTs = $leftTs !== false ? (int) $leftTs : 0;
            $rightTs = $rightTs !== false ? (int) $rightTs : 0;

            if ($leftTs === $rightTs) {
                return strcmp((string) ($right['id'] ?? ''), (string) ($left['id'] ?? ''));
            }

            return $rightTs <=> $leftTs;
        });

        return $items;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadIndex(): array
    {
        $stored = get_option(self::OPTION_INDEX_KEY, []);
        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, 'is_array'));
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function saveIndex(array $items): void
    {
        update_option(self::OPTION_INDEX_KEY, array_values($items), false);
    }

    private function isSafeBackupPath(string $path): bool
    {
        $path = $this->normalizePath($path);
        if ($path === '') {
            return false;
        }

        $root = $this->backupsRoot();
        if ($root === '') {
            return false;
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function sanitizeFileName(string $filename): string
    {
        $filename = trim(str_replace('\\', '/', $filename));
        $filename = basename($filename);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? '';
        $filename = trim($filename, '._-');

        return substr($filename, 0, 180);
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }

    private function generateId(): string
    {
        try {
            return substr(bin2hex(random_bytes(10)), 0, 20);
        } catch (\Throwable) {
            return substr(sha1(uniqid((string) mt_rand(), true)), 0, 20);
        }
    }

    private function backupsRoot(): string
    {
        $filtered = (string) apply_filters('lift_teleport_backup_directory', Paths::backupsRoot());
        $normalized = $this->normalizePath($filtered);

        if ($normalized === '') {
            $normalized = $this->normalizePath(Paths::backupsRoot());
        }

        return $normalized;
    }
}
