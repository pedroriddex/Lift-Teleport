<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Support;

use LiftTeleport\Support\CliWorkerLauncher;
use LiftTeleport\Support\CommandRunner;
use PHPUnit\Framework\TestCase;

final class CliWorkerLauncherSecurityTest extends TestCase
{
    public function testLaunchIgnoresUnsafeCliCandidates(): void
    {
        if (! CommandRunner::available() && ! CommandRunner::canUseProcOpen()) {
            self::markTestSkipped('Shell execution is disabled in this runtime.');
        }

        $commands = [];
        $launcher = new CliWorkerLauncher(
            static function (string $command, string $logPath) use (&$commands): array {
                $commands[] = $command;
                return [
                    'started' => false,
                    'message' => 'forced failure',
                    'stderr' => '',
                ];
            },
            [
                'wp',
                'wp;rm -rf /',
                'wp && whoami',
                'php /tmp/wp-cli.phar',
                '../wp',
                "wp\ncat /etc/passwd",
                '$(id)',
            ]
        );

        $result = $launcher->launch(44, '/tmp/lift-wordpress', 300);

        self::assertFalse((bool) ($result['started'] ?? true));
        self::assertSame('cli_worker_launch_failed', (string) ($result['reason'] ?? ''));
        self::assertCount(1, $commands);
        self::assertStringContainsString("'wp' --path='/tmp/lift-wordpress' lift jobs run 44", (string) $commands[0]);
    }
}
