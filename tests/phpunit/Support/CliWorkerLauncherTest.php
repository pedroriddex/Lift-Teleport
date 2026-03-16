<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Support;

use LiftTeleport\Support\CliWorkerLauncher;
use PHPUnit\Framework\TestCase;

final class CliWorkerLauncherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        lift_test_reset_options();
    }

    public function testLaunchFallsBackToSecondCandidate(): void
    {
        $commands = [];
        $launcher = new CliWorkerLauncher(
            static function (string $command, string $logPath) use (&$commands): array {
                $commands[] = [
                    'command' => $command,
                    'log_path' => $logPath,
                ];

                if (str_starts_with($command, "'bad-wp' ")) {
                    return [
                        'started' => false,
                        'message' => 'bad candidate',
                        'stderr' => 'not found',
                    ];
                }

                return [
                    'started' => true,
                    'pid' => 99123,
                    'message' => 'started',
                    'stderr' => '',
                ];
            },
            ['bad-wp', 'good-wp']
        );

        $result = $launcher->launch(55, '/tmp/wp with space', 1200);

        self::assertTrue((bool) ($result['started'] ?? false));
        self::assertSame('cli_worker', $result['mode']);
        self::assertSame('good-wp', $result['command_used']);
        self::assertCount(2, $commands);
        self::assertStringContainsString(
            "'bad-wp' --path='/tmp/wp with space' lift jobs run 55 --until-terminal --timeout=1200 --format=json",
            (string) ($commands[0]['command'] ?? '')
        );
    }

    public function testLaunchReturnsWebRunnerFailureWhenNoCandidateStarts(): void
    {
        $launcher = new CliWorkerLauncher(
            static fn (): array => [
                'started' => false,
                'message' => 'failed',
                'stderr' => 'x',
            ],
            ['wp-a', 'wp-b']
        );

        $result = $launcher->launch(88, '/tmp/wp-root', 300);

        self::assertFalse((bool) ($result['started'] ?? true));
        self::assertSame('web_runner', $result['mode']);
        self::assertSame('cli_worker_launch_failed', $result['reason']);
        self::assertIsArray($result['errors'] ?? null);
        self::assertCount(2, $result['errors']);
    }
}
