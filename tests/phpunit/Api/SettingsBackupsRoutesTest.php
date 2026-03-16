<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Api;

use LiftTeleport\Api\Routes;
use PHPUnit\Framework\TestCase;

final class SettingsBackupsRoutesTest extends TestCase
{
    public function testRoutesExposeSettingsAndBackupsMethods(): void
    {
        self::assertTrue(method_exists(Routes::class, 'getSettings'));
        self::assertTrue(method_exists(Routes::class, 'updateSettings'));
        self::assertTrue(method_exists(Routes::class, 'getBackups'));
        self::assertTrue(method_exists(Routes::class, 'downloadBackup'));
        self::assertTrue(method_exists(Routes::class, 'deleteBackup'));
        self::assertTrue(method_exists(Routes::class, 'importBackup'));
    }
}
