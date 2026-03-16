<?php

declare(strict_types=1);

namespace LiftTeleport\Support;

use RuntimeException;

final class SchemaManager
{
    public const OPTION_LAST_REPAIR = 'lift_teleport_schema_last_repair';

    /**
     * @var object
     */
    private object $db;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $inspectCache = null;

    /**
     * @param object|null $db
     */
    public function __construct(?object $db = null)
    {
        global $wpdb;
        $this->db = $db ?: $wpdb;
    }

    public function isHealthy(bool $refresh = false): bool
    {
        $inspect = $this->inspect($refresh);
        return ! empty($inspect['health']);
    }

    /**
     * @return array<string,mixed>
     */
    public function inspect(bool $refresh = false): array
    {
        if (! $refresh && is_array($this->inspectCache)) {
            return $this->inspectCache;
        }

        $engine = $this->detectEngine();
        $tables = $this->canonicalTables();
        $tablesStatus = [];
        $missingColumns = [];
        $missingIndexes = [];

        foreach ($tables as $key => $spec) {
            $tableName = (string) ($spec['name'] ?? '');
            $columns = $this->listTableColumns($tableName, true);
            $indexes = $this->listTableIndexes($tableName);

            $expectedColumns = array_keys((array) ($spec['columns'] ?? []));
            $expectedIndexes = array_keys((array) ($spec['indexes'] ?? []));

            $missingColsForTable = array_values(array_diff($expectedColumns, array_keys($columns)));
            $missingIdxForTable = array_values(array_diff($expectedIndexes, array_keys($indexes)));

            if ($missingColsForTable !== []) {
                $missingColumns[$key] = $missingColsForTable;
            }

            if ($missingIdxForTable !== []) {
                $missingIndexes[$key] = $missingIdxForTable;
            }

            $tablesStatus[$key] = [
                'name' => $tableName,
                'exists' => $columns !== [],
                'columns' => array_keys($columns),
                'missing_columns' => $missingColsForTable,
                'indexes' => array_keys($indexes),
                'missing_indexes' => $missingIdxForTable,
            ];
        }

        $lastRepair = get_option(self::OPTION_LAST_REPAIR, []);
        if (! is_array($lastRepair)) {
            $lastRepair = [];
        }

        $inspect = [
            'engine' => $engine,
            'health' => $missingColumns === [] && $missingIndexes === [],
            'tables' => $tablesStatus,
            'missing_columns' => $missingColumns,
            'missing_indexes' => $missingIndexes,
            'last_repair_at' => (string) ($lastRepair['at'] ?? ''),
            'last_repair_result' => (string) ($lastRepair['status'] ?? ''),
            'last_repair' => $lastRepair,
        ];

        $this->inspectCache = $inspect;

        return $inspect;
    }

    /**
     * @return array<string,mixed>
     */
    public function repairIfNeeded(): array
    {
        $before = $this->inspect(true);
        if (! empty($before['health'])) {
            $result = [
                'status' => 'noop',
                'changed' => false,
                'health' => true,
                'engine' => (string) ($before['engine'] ?? 'unknown'),
                'added_columns' => [],
                'added_indexes' => [],
                'rebuilt_tables' => [],
                'errors' => [],
            ];
            $this->persistRepairResult($result);
            return $result;
        }

        do_action('lift_teleport_schema_repair_started', $before);

        $engine = (string) ($before['engine'] ?? $this->detectEngine());
        $tables = $this->canonicalTables();
        $addedColumns = [];
        $addedIndexes = [];
        $rebuiltTables = [];
        $errors = [];

        foreach ($tables as $key => $spec) {
            $tableName = (string) ($spec['name'] ?? '');
            if ($tableName === '') {
                continue;
            }

            try {
                $this->createTableIfMissing($key, $spec, $engine);
            } catch (\Throwable $error) {
                $errors[] = sprintf('create:%s:%s', $tableName, $error->getMessage());
                continue;
            }

            $currentColumns = $this->listTableColumns($tableName, true);
            $expectedColumns = (array) ($spec['columns'] ?? []);

            foreach ($expectedColumns as $column => $columnSpec) {
                if (isset($currentColumns[$column])) {
                    continue;
                }

                try {
                    $this->addColumn($tableName, (string) $column, (array) $columnSpec, $engine);
                    $addedColumns[] = $tableName . '.' . $column;
                    continue;
                } catch (\Throwable $error) {
                    if ($engine === 'sqlite') {
                        try {
                            $this->rebuildSqliteTable($key, $spec);
                            $rebuiltTables[] = $tableName;
                            $currentColumns = $this->listTableColumns($tableName, true);
                            if (isset($currentColumns[$column])) {
                                continue;
                            }
                        } catch (\Throwable $rebuildError) {
                            $errors[] = sprintf('rebuild:%s:%s', $tableName, $rebuildError->getMessage());
                        }
                    }

                    $errors[] = sprintf('add:%s.%s:%s', $tableName, $column, $error->getMessage());
                }
            }

            $indexesNow = $this->listTableIndexes($tableName);
            foreach ((array) ($spec['indexes'] ?? []) as $indexName => $indexSpec) {
                if (isset($indexesNow[$indexName])) {
                    continue;
                }

                try {
                    $this->createIndex($tableName, (string) $indexName, (array) $indexSpec, $engine);
                    $addedIndexes[] = $tableName . '.' . $indexName;
                } catch (\Throwable $error) {
                    $errors[] = sprintf('index:%s.%s:%s', $tableName, $indexName, $error->getMessage());
                }
            }
        }

        $after = $this->inspect(true);
        $status = ! empty($after['health']) ? 'completed' : 'failed';

        $result = [
            'status' => $status,
            'changed' => $addedColumns !== [] || $addedIndexes !== [] || $rebuiltTables !== [],
            'health' => ! empty($after['health']),
            'engine' => $engine,
            'added_columns' => $addedColumns,
            'added_indexes' => $addedIndexes,
            'rebuilt_tables' => array_values(array_unique($rebuiltTables)),
            'errors' => $errors,
            'missing_columns' => (array) ($after['missing_columns'] ?? []),
        ];

        $this->persistRepairResult($result);

        if ($status === 'completed') {
            do_action('lift_teleport_schema_repair_completed', $result);
        } else {
            do_action('lift_teleport_schema_repair_failed', $result);
        }

        return $result;
    }

    public function clearCache(): void
    {
        $this->inspectCache = null;
    }

    /**
     * @return array<string,bool>
     */
    public function listTableColumns(string $table, bool $refresh = false): array
    {
        if ($table === '') {
            return [];
        }

        if (! $refresh && is_array($this->inspectCache['tables'] ?? null)) {
            foreach ((array) $this->inspectCache['tables'] as $tableState) {
                if (! is_array($tableState) || (string) ($tableState['name'] ?? '') !== $table) {
                    continue;
                }

                $columns = [];
                foreach ((array) ($tableState['columns'] ?? []) as $column) {
                    if (! is_string($column) || $column === '') {
                        continue;
                    }
                    $columns[$column] = true;
                }
                return $columns;
            }
        }

        $plain = $this->plainIdentifier($table);
        if ($plain === '') {
            return [];
        }

        $previous = $this->db->suppress_errors(true);
        $rows = $this->db->get_results("SHOW COLUMNS FROM {$plain}", ARRAY_A);
        $error = (string) $this->db->last_error;
        $this->db->suppress_errors($previous);

        if ($error !== '' || ! is_array($rows)) {
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = '';
            if (isset($row['Field']) && is_string($row['Field'])) {
                $name = $row['Field'];
            } elseif (isset($row['field']) && is_string($row['field'])) {
                $name = $row['field'];
            }

            if ($name === '') {
                continue;
            }

            $columns[$name] = true;
        }

        return $columns;
    }

    /**
     * @return array<string,bool>
     */
    private function listTableIndexes(string $table): array
    {
        $plain = $this->plainIdentifier($table);
        if ($plain === '') {
            return [];
        }

        $previous = $this->db->suppress_errors(true);
        $rows = $this->db->get_results("SHOW INDEX FROM {$plain}", ARRAY_A);
        $error = (string) $this->db->last_error;
        $this->db->suppress_errors($previous);

        if ($error !== '' || ! is_array($rows)) {
            return [];
        }

        $indexes = [];
        foreach ($rows as $row) {
            $name = '';
            if (isset($row['Key_name']) && is_string($row['Key_name'])) {
                $name = $row['Key_name'];
            } elseif (isset($row['key_name']) && is_string($row['key_name'])) {
                $name = $row['key_name'];
            }

            if ($name === '' || $name === 'PRIMARY') {
                continue;
            }

            $indexes[$name] = true;
        }

        return $indexes;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function persistRepairResult(array $result): void
    {
        update_option(self::OPTION_LAST_REPAIR, [
            'at' => gmdate(DATE_ATOM),
            'status' => (string) ($result['status'] ?? ''),
            'result' => $result,
        ], false);
        $this->clearCache();
    }

    /**
     * @param array<string,mixed> $spec
     */
    private function createTableIfMissing(string $key, array $spec, string $engine): void
    {
        $table = (string) ($spec['name'] ?? '');
        if ($table === '') {
            return;
        }

        if ($this->listTableColumns($table, true) !== []) {
            return;
        }

        if ($engine === 'sqlite') {
            $sql = $this->buildSqliteCreateTableSql($key, $spec, false);
            $this->runWriteQuery($sql);
            foreach ((array) ($spec['indexes'] ?? []) as $indexName => $indexSpec) {
                $this->createIndex($table, (string) $indexName, (array) $indexSpec, $engine);
            }
            return;
        }

        $columnsSql = [];
        foreach ((array) ($spec['columns'] ?? []) as $columnName => $columnSpec) {
            $definition = trim((string) ($columnSpec['mysql'] ?? ''));
            if ($definition === '') {
                continue;
            }
            $columnsSql[] = $this->quoteIdentifier((string) $columnName) . ' ' . $definition;
        }

        if ($columnsSql === []) {
            throw new RuntimeException(sprintf('No canonical columns configured for %s.', $table));
        }

        $primary = (string) ($spec['primary'] ?? '');
        if ($primary !== '') {
            $columnsSql[] = 'PRIMARY KEY (' . $this->quoteIdentifier($primary) . ')';
        }

        foreach ((array) ($spec['indexes'] ?? []) as $indexName => $indexSpec) {
            $idxCols = (array) ($indexSpec['columns'] ?? []);
            if ($idxCols === []) {
                continue;
            }
            $quotedCols = array_map([$this, 'quoteIdentifier'], $idxCols);
            $columnsSql[] = 'KEY ' . $this->quoteIdentifier((string) $indexName) . ' (' . implode(',', $quotedCols) . ')';
        }

        $charset = (string) $this->db->get_charset_collate();
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (%s) %s',
            $this->quoteIdentifier($table),
            implode(', ', $columnsSql),
            $charset
        );
        $this->runWriteQuery($sql);
    }

    /**
     * @param array<string,mixed> $columnSpec
     */
    private function addColumn(string $table, string $column, array $columnSpec, string $engine): void
    {
        $columnDefinition = $engine === 'sqlite'
            ? trim((string) ($columnSpec['sqlite'] ?? $columnSpec['mysql'] ?? ''))
            : trim((string) ($columnSpec['mysql'] ?? ''));

        if ($columnDefinition === '') {
            throw new RuntimeException(sprintf('Missing canonical definition for %s.%s', $table, $column));
        }

        $sql = sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($column),
            $columnDefinition
        );
        $this->runWriteQuery($sql);
    }

    /**
     * @param array<string,mixed> $indexSpec
     */
    private function createIndex(string $table, string $indexName, array $indexSpec, string $engine): void
    {
        $indexColumns = (array) ($indexSpec['columns'] ?? []);
        if ($indexColumns === []) {
            return;
        }

        $quotedCols = array_map([$this, 'quoteIdentifier'], $indexColumns);

        if ($engine === 'sqlite') {
            $sql = sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (%s)',
                $this->quoteIdentifier($indexName),
                $this->quoteIdentifier($table),
                implode(',', $quotedCols)
            );
            $this->runWriteQuery($sql);
            return;
        }

        $sql = sprintf(
            'ALTER TABLE %s ADD INDEX %s (%s)',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($indexName),
            implode(',', $quotedCols)
        );
        $this->runWriteQuery($sql);
    }

    /**
     * @param array<string,mixed> $spec
     */
    private function rebuildSqliteTable(string $key, array $spec): void
    {
        $table = (string) ($spec['name'] ?? '');
        if ($table === '') {
            throw new RuntimeException('Missing table name for SQLite rebuild.');
        }

        $tempTable = $table . '_repair_' . wp_generate_password(6, false, false);
        $existingColumns = $this->listTableColumns($table, true);
        if ($existingColumns === []) {
            throw new RuntimeException(sprintf('Cannot rebuild missing table: %s', $table));
        }

        $this->runWriteQuery('BEGIN');
        try {
            $this->runWriteQuery($this->buildSqliteCreateTableSql($key, array_merge($spec, ['name' => $tempTable]), true));

            $canonicalColumns = array_keys((array) ($spec['columns'] ?? []));
            $copyColumns = [];
            foreach ($canonicalColumns as $column) {
                if (isset($existingColumns[$column])) {
                    $copyColumns[] = $column;
                }
            }

            if ($copyColumns !== []) {
                $quotedCopy = array_map([$this, 'quoteIdentifier'], $copyColumns);
                $sql = sprintf(
                    'INSERT INTO %s (%s) SELECT %s FROM %s',
                    $this->quoteIdentifier($tempTable),
                    implode(',', $quotedCopy),
                    implode(',', $quotedCopy),
                    $this->quoteIdentifier($table)
                );
                $this->runWriteQuery($sql);
            }

            $this->runWriteQuery(sprintf('DROP TABLE %s', $this->quoteIdentifier($table)));
            $this->runWriteQuery(sprintf(
                'ALTER TABLE %s RENAME TO %s',
                $this->quoteIdentifier($tempTable),
                $this->quoteIdentifier($table)
            ));

            foreach ((array) ($spec['indexes'] ?? []) as $indexName => $indexSpec) {
                $this->createIndex($table, (string) $indexName, (array) $indexSpec, 'sqlite');
            }

            $this->runWriteQuery('COMMIT');
        } catch (\Throwable $error) {
            try {
                $this->runWriteQuery('ROLLBACK');
            } catch (\Throwable) {
                // Ignore rollback errors.
            }
            throw new RuntimeException($error->getMessage(), 0, $error);
        }
    }

    /**
     * @param array<string,mixed> $spec
     */
    private function buildSqliteCreateTableSql(string $key, array $spec, bool $ifNotExists): string
    {
        $table = (string) ($spec['name'] ?? '');
        if ($table === '') {
            throw new RuntimeException('Missing SQLite table name.');
        }

        $columns = [];
        foreach ((array) ($spec['columns'] ?? []) as $columnName => $columnSpec) {
            $definition = trim((string) ($columnSpec['sqlite'] ?? ''));
            if ($definition === '') {
                continue;
            }

            $columns[] = $this->quoteIdentifier((string) $columnName) . ' ' . $definition;
        }

        if ($columns === []) {
            throw new RuntimeException(sprintf('No SQLite column definitions found for %s.', $key));
        }

        $exists = $ifNotExists ? 'IF NOT EXISTS ' : '';
        return sprintf(
            'CREATE TABLE %s%s (%s)',
            $exists,
            $this->quoteIdentifier($table),
            implode(', ', $columns)
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalTables(): array
    {
        $jobs = $this->db->prefix . 'lift_jobs';
        $events = $this->db->prefix . 'lift_job_events';

        return [
            'jobs' => [
                'name' => $jobs,
                'primary' => 'id',
                'columns' => [
                    'id' => ['mysql' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT'],
                    'type' => ['mysql' => 'VARCHAR(20) NOT NULL', 'sqlite' => 'TEXT NOT NULL'],
                    'status' => ['mysql' => 'VARCHAR(20) NOT NULL', 'sqlite' => 'TEXT NOT NULL'],
                    'current_step' => ['mysql' => 'VARCHAR(120) DEFAULT NULL', 'sqlite' => 'TEXT DEFAULT NULL'],
                    'attempts' => ['mysql' => 'INT(11) NOT NULL DEFAULT 0', 'sqlite' => 'INTEGER NOT NULL DEFAULT 0'],
                    'progress' => ['mysql' => 'DECIMAL(5,2) NOT NULL DEFAULT 0', 'sqlite' => 'DOUBLE NOT NULL DEFAULT 0'],
                    'message' => ['mysql' => 'TEXT DEFAULT NULL', 'sqlite' => 'TEXT DEFAULT NULL'],
                    'payload' => ['mysql' => 'LONGTEXT DEFAULT NULL', 'sqlite' => 'TEXT DEFAULT NULL'],
                    'result' => ['mysql' => 'LONGTEXT DEFAULT NULL', 'sqlite' => 'TEXT DEFAULT NULL'],
                    'job_token' => ['mysql' => 'VARCHAR(128) DEFAULT NULL', 'sqlite' => 'TEXT DEFAULT NULL'],
                    'lock_owner' => ['mysql' => 'VARCHAR(64) DEFAULT NULL', 'sqlite' => 'TEXT DEFAULT NULL'],
                    'locked_until' => ['mysql' => 'DATETIME DEFAULT NULL', 'sqlite' => 'TEXT DEFAULT NULL'],
                    'heartbeat_at' => ['mysql' => 'DATETIME DEFAULT NULL', 'sqlite' => 'TEXT DEFAULT NULL'],
                    'worker_heartbeat_at' => ['mysql' => 'DATETIME DEFAULT NULL', 'sqlite' => 'TEXT DEFAULT NULL'],
                    'started_at' => ['mysql' => 'DATETIME DEFAULT NULL', 'sqlite' => 'TEXT DEFAULT NULL'],
                    'finished_at' => ['mysql' => 'DATETIME DEFAULT NULL', 'sqlite' => 'TEXT DEFAULT NULL'],
                    'created_at' => ['mysql' => 'DATETIME NOT NULL', 'sqlite' => 'TEXT NOT NULL DEFAULT \'1970-01-01 00:00:00\''],
                    'updated_at' => ['mysql' => 'DATETIME NOT NULL', 'sqlite' => 'TEXT NOT NULL DEFAULT \'1970-01-01 00:00:00\''],
                ],
                'indexes' => [
                    'status' => ['columns' => ['status']],
                    'updated_at' => ['columns' => ['updated_at']],
                    'job_token' => ['columns' => ['job_token']],
                    'status_type_updated_at' => ['columns' => ['status', 'type', 'updated_at']],
                    'created_at' => ['columns' => ['created_at']],
                ],
            ],
            'events' => [
                'name' => $events,
                'primary' => 'id',
                'columns' => [
                    'id' => ['mysql' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT'],
                    'job_id' => ['mysql' => 'BIGINT(20) UNSIGNED NOT NULL', 'sqlite' => 'INTEGER NOT NULL'],
                    'level' => ['mysql' => 'VARCHAR(20) NOT NULL', 'sqlite' => 'TEXT NOT NULL'],
                    'message' => ['mysql' => 'TEXT NOT NULL', 'sqlite' => 'TEXT NOT NULL'],
                    'context' => ['mysql' => 'LONGTEXT DEFAULT NULL', 'sqlite' => 'TEXT DEFAULT NULL'],
                    'created_at' => ['mysql' => 'DATETIME NOT NULL', 'sqlite' => 'TEXT NOT NULL DEFAULT \'1970-01-01 00:00:00\''],
                ],
                'indexes' => [
                    'job_id' => ['columns' => ['job_id']],
                    'created_at' => ['columns' => ['created_at']],
                ],
            ],
        ];
    }

    private function detectEngine(): string
    {
        $class = strtolower(get_class($this->db));
        if (str_contains($class, 'sqlite')) {
            return 'sqlite';
        }

        $serverInfo = '';
        if (method_exists($this->db, 'db_server_info')) {
            $serverInfo = strtolower((string) $this->db->db_server_info());
        }

        if (str_contains($serverInfo, 'mariadb')) {
            return 'mariadb';
        }

        if (str_contains($serverInfo, 'mysql')) {
            return 'mysql';
        }

        return 'unknown';
    }

    private function quoteIdentifier(string $identifier): string
    {
        $sanitized = $this->plainIdentifier($identifier);
        if (! is_string($sanitized) || $sanitized === '') {
            return '';
        }

        return '`' . $sanitized . '`';
    }

    private function plainIdentifier(string $identifier): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_]/', '', $identifier);
        return is_string($sanitized) ? $sanitized : '';
    }

    private function runWriteQuery(string $sql): void
    {
        $previous = $this->db->suppress_errors(true);
        $result = $this->db->query($sql);
        $error = (string) $this->db->last_error;
        $this->db->suppress_errors($previous);

        if ($result === false || $error !== '') {
            throw new RuntimeException($error !== '' ? $error : ('Query failed: ' . $sql));
        }
    }
}
