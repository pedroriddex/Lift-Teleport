<?php

declare(strict_types=1);

namespace LiftTeleport\Import;

use RuntimeException;
use wpdb;

final class OperatorSessionContinuity
{
    private wpdb $db;

    public function __construct(?wpdb $db = null)
    {
        global $wpdb;
        $this->db = $db ?: $wpdb;
    }

    public function isEnabled(): bool
    {
        return (bool) apply_filters('lift_teleport_operator_session_continuity_enabled', true);
    }

    /**
     * @return array<string,mixed>
     */
    public function captureForJob(int $userId, string $tablePrefix): array
    {
        if (! $this->isEnabled() || $userId <= 0) {
            return [];
        }

        $prefix = $this->normalizePrefix($tablePrefix);
        $usersTable = $prefix . 'users';
        $usermetaTable = $prefix . 'usermeta';

        $userRow = $this->db->get_row(
            $this->db->prepare("SELECT * FROM `{$usersTable}` WHERE ID = %d LIMIT 1", $userId),
            ARRAY_A
        );

        if (! is_array($userRow) || $userRow === []) {
            throw new RuntimeException(sprintf('Operator user %d was not found in %s.', $userId, $usersTable));
        }

        $metaRows = $this->db->get_results(
            $this->db->prepare(
                "SELECT meta_key, meta_value FROM `{$usermetaTable}` WHERE user_id = %d ORDER BY umeta_id ASC",
                $userId
            ),
            ARRAY_A
        );

        $metaRows = is_array($metaRows) ? array_values(array_filter($metaRows, static function (mixed $row): bool {
            return is_array($row) && isset($row['meta_key']);
        })) : [];

        $criticalKeys = [
            'session_tokens',
            $prefix . 'capabilities',
            $prefix . 'user_level',
        ];

        return [
            'captured_at' => gmdate(DATE_ATOM),
            'table_prefix' => $prefix,
            'user_id' => $userId,
            'critical_meta_keys' => $criticalKeys,
            'user_row' => $userRow,
            'meta_rows' => $metaRows,
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    public function restoreAfterDatabaseImport(array $snapshot, string $tablePrefix): void
    {
        if (! $this->isEnabled() || $snapshot === []) {
            return;
        }

        $prefix = $this->normalizePrefix($tablePrefix);
        $usersTable = $prefix . 'users';
        $usermetaTable = $prefix . 'usermeta';

        $userRow = isset($snapshot['user_row']) && is_array($snapshot['user_row']) ? $snapshot['user_row'] : [];
        $userId = (int) ($snapshot['user_id'] ?? ($userRow['ID'] ?? 0));

        if ($userId <= 0 || ! isset($userRow['ID'])) {
            throw new RuntimeException('Invalid operator session snapshot: missing user row.');
        }

        $userRow['ID'] = $userId;
        $replaced = $this->db->replace($usersTable, $userRow);
        if ($replaced === false) {
            throw new RuntimeException(sprintf('Unable to restore operator row in %s.', $usersTable));
        }

        $deleted = $this->db->delete($usermetaTable, ['user_id' => $userId], ['%d']);
        if ($deleted === false) {
            throw new RuntimeException(sprintf('Unable to clear operator meta in %s.', $usermetaTable));
        }

        $metaRows = isset($snapshot['meta_rows']) && is_array($snapshot['meta_rows']) ? $snapshot['meta_rows'] : [];
        foreach ($metaRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $metaKey = isset($row['meta_key']) ? (string) $row['meta_key'] : '';
            if ($metaKey === '') {
                continue;
            }

            $metaValue = isset($row['meta_value']) ? (string) $row['meta_value'] : '';
            $inserted = $this->db->insert($usermetaTable, [
                'user_id' => $userId,
                'meta_key' => $metaKey,
                'meta_value' => $metaValue,
            ], ['%d', '%s', '%s']);

            if ($inserted === false) {
                throw new RuntimeException(sprintf('Unable to restore user meta key %s.', $metaKey));
            }
        }

        if (function_exists('clean_user_cache')) {
            clean_user_cache($userId);
        }
    }

    private function normalizePrefix(string $tablePrefix): string
    {
        $prefix = trim($tablePrefix);
        if ($prefix === '') {
            throw new RuntimeException('Table prefix is required for session continuity.');
        }

        return $prefix;
    }
}
