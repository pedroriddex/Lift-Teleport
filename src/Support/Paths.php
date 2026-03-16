<?php

declare(strict_types=1);

namespace LiftTeleport\Support;

final class Paths
{
    public static function dataRoot(): string
    {
        return WP_CONTENT_DIR . '/lift-teleport-data';
    }

    public static function jobsRoot(): string
    {
        return self::dataRoot() . '/jobs';
    }

    public static function backupsRoot(): string
    {
        return self::dataRoot() . '/backups';
    }

    public static function jobRoot(int $jobId): string
    {
        return self::jobsRoot() . '/' . $jobId;
    }

    public static function jobInput(int $jobId): string
    {
        return self::jobRoot($jobId) . '/input';
    }

    public static function jobWorkspace(int $jobId): string
    {
        return self::jobRoot($jobId) . '/workspace';
    }

    public static function jobOutput(int $jobId): string
    {
        return self::jobRoot($jobId) . '/output';
    }

    public static function jobRollback(int $jobId): string
    {
        return self::jobRoot($jobId) . '/rollback';
    }

    public static function ensureBaseDirs(): void
    {
        Filesystem::ensureDirectory(self::dataRoot());
        Filesystem::ensureDirectory(self::jobsRoot());
        Filesystem::ensureDirectory(self::backupsRoot());

        Filesystem::hardenDirectory(self::dataRoot());
        Filesystem::hardenDirectory(self::jobsRoot());
        Filesystem::hardenDirectory(self::backupsRoot());
    }

    public static function ensureJobDirs(int $jobId): void
    {
        $dirs = [
            self::jobRoot($jobId),
            self::jobInput($jobId),
            self::jobWorkspace($jobId),
            self::jobOutput($jobId),
            self::jobRollback($jobId),
        ];

        foreach ($dirs as $dir) {
            Filesystem::ensureDirectory($dir);
            Filesystem::hardenDirectory($dir);
        }
    }
}
