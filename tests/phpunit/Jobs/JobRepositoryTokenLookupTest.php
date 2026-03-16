<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Jobs;

use LiftTeleport\Jobs\JobRepository;
use PHPUnit\Framework\TestCase;

final class JobRepositoryTokenLookupTest extends TestCase
{
    public function testFindByTokenUsesIndexedColumnWhenAvailable(): void
    {
        $db = new FakeWpdbTokenLookup();
        $repo = new JobRepository($db);

        $job = $repo->findByToken('token_abc1234567890');

        self::assertIsArray($job);
        self::assertSame(7, (int) ($job['id'] ?? 0));
        self::assertSame('export', (string) ($job['type'] ?? ''));
        self::assertSame('token_abc1234567890', (string) ($job['job_token'] ?? ''));
        self::assertSame(1, $db->getVarCallsForTokenLookup);
    }
}

final class FakeWpdbTokenLookup extends \wpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    public int $getVarCallsForTokenLookup = 0;

    public function suppress_errors(bool $suppress = true): bool
    {
        return false;
    }

    public function get_results(string $query, string|int $output = ARRAY_A): array
    {
        if (stripos($query, 'SHOW COLUMNS FROM `wp_lift_jobs`') !== false || stripos($query, 'SHOW COLUMNS FROM wp_lift_jobs') !== false) {
            return [
                ['Field' => 'id'],
                ['Field' => 'type'],
                ['Field' => 'status'],
                ['Field' => 'payload'],
                ['Field' => 'result'],
                ['Field' => 'attempts'],
                ['Field' => 'progress'],
                ['Field' => 'job_token'],
                ['Field' => 'created_at'],
                ['Field' => 'updated_at'],
            ];
        }

        if (stripos($query, 'SHOW INDEX FROM `wp_lift_jobs`') !== false || stripos($query, 'SHOW INDEX FROM wp_lift_jobs') !== false) {
            return [
                ['Key_name' => 'job_token'],
                ['Key_name' => 'status'],
            ];
        }

        return [];
    }

    public function get_var(string $query, int $x = 0, int $y = 0): mixed
    {
        if (stripos($query, 'FROM wp_lift_jobs WHERE job_token =') !== false || stripos($query, 'FROM `wp_lift_jobs` WHERE job_token =') !== false) {
            $this->getVarCallsForTokenLookup++;
            return '7';
        }

        return null;
    }

    public function get_col(string $query, int $x = 0): array
    {
        return [];
    }

    public function get_row(string $query, string|int $output = ARRAY_A, int $y = 0): array|null
    {
        if (stripos($query, 'FROM wp_lift_jobs WHERE id =') !== false || stripos($query, 'FROM `wp_lift_jobs` WHERE id =') !== false) {
            return [
                'id' => '7',
                'type' => 'export',
                'status' => 'running',
                'current_step' => 'export_package',
                'attempts' => '0',
                'progress' => '50.00',
                'message' => 'Running',
                'payload' => '{"job_token":"token_abc1234567890"}',
                'result' => '{}',
                'job_token' => 'token_abc1234567890',
                'created_at' => '2026-02-22 00:00:00',
                'updated_at' => '2026-02-22 00:00:01',
            ];
        }

        return null;
    }
}
