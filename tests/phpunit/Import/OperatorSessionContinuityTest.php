<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Import;

use LiftTeleport\Import\OperatorSessionContinuity;
use PHPUnit\Framework\TestCase;

final class OperatorSessionContinuityTest extends TestCase
{
    public function testCaptureReturnsOperatorSnapshot(): void
    {
        $db = new OperatorSessionContinuityFakeWpdb();
        $service = new OperatorSessionContinuity($db);

        $snapshot = $service->captureForJob(7, 'wp_');

        self::assertSame(7, (int) ($snapshot['user_id'] ?? 0));
        self::assertIsArray($snapshot['user_row'] ?? null);
        self::assertSame('admin@example.com', $snapshot['user_row']['user_email'] ?? '');
        self::assertGreaterThanOrEqual(2, count($snapshot['meta_rows'] ?? []));
        self::assertContains('session_tokens', $snapshot['critical_meta_keys'] ?? []);
        self::assertContains('wp_capabilities', $snapshot['critical_meta_keys'] ?? []);
    }

    public function testRestoreWritesUsersAndMetaRows(): void
    {
        $db = new OperatorSessionContinuityFakeWpdb();
        $service = new OperatorSessionContinuity($db);

        $snapshot = $service->captureForJob(7, 'wp_');
        $service->restoreAfterDatabaseImport($snapshot, 'wp_');

        self::assertSame('wp_users', $db->lastReplaceTable);
        self::assertSame(7, (int) ($db->lastReplaceData['ID'] ?? 0));
        self::assertSame('wp_usermeta', $db->lastDeleteTable);
        self::assertSame(7, (int) ($db->lastDeleteWhere['user_id'] ?? 0));
        self::assertSame(2, count($db->insertedMetaRows));
        self::assertSame('session_tokens', $db->insertedMetaRows[0]['meta_key'] ?? '');
    }
}

final class OperatorSessionContinuityFakeWpdb extends \wpdb
{
    public string $lastReplaceTable = '';

    /** @var array<string,mixed> */
    public array $lastReplaceData = [];

    public string $lastDeleteTable = '';

    /** @var array<string,mixed> */
    public array $lastDeleteWhere = [];

    /** @var array<int,array<string,mixed>> */
    public array $insertedMetaRows = [];

    public function prepare(string $query, mixed ...$args): string
    {
        if ($args === []) {
            return $query;
        }

        return (string) @vsprintf(str_replace(['%d', '%s'], ['%s', '%s'], $query), array_map(static function (mixed $arg): string {
            if (is_int($arg) || is_float($arg)) {
                return (string) $arg;
            }

            return "'" . str_replace("'", "\\'", (string) $arg) . "'";
        }, $args));
    }

    public function get_row(string $query, string|int $output = ARRAY_A): ?array
    {
        if (str_contains($query, 'wp_users')) {
            return [
                'ID' => 7,
                'user_login' => 'admin',
                'user_pass' => '$P$hash',
                'user_email' => 'admin@example.com',
            ];
        }

        return null;
    }

    public function get_results(string $query, string|int $output = ARRAY_A): array
    {
        if (str_contains($query, 'wp_usermeta')) {
            return [
                [
                    'meta_key' => 'session_tokens',
                    'meta_value' => 'a:1:{s:4:"token";a:1:{s:10:"expiration";i:123;}}',
                ],
                [
                    'meta_key' => 'wp_capabilities',
                    'meta_value' => 'a:1:{s:13:"administrator";b:1;}',
                ],
            ];
        }

        return [];
    }

    public function replace(string $table, array $data, ?array $format = null): int|false
    {
        $this->lastReplaceTable = $table;
        $this->lastReplaceData = $data;
        return 1;
    }

    public function delete(string $table, array $where, ?array $whereFormat = null): int|false
    {
        $this->lastDeleteTable = $table;
        $this->lastDeleteWhere = $where;
        return 1;
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        $this->insertedMetaRows[] = $data;
        return 1;
    }
}
