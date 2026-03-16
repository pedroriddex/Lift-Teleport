<?php

declare(strict_types=1);

namespace LiftTeleport\Import;

use RuntimeException;
use wpdb;

final class SerializedReplacer
{
    private wpdb $db;

    public function __construct(?wpdb $db = null)
    {
        global $wpdb;
        $this->db = $db ?: $wpdb;
    }

    /**
     * @param array<string,mixed> $state
     * @return array{state:array<string,mixed>,completed:bool,progress:float,metrics:array<string,mixed>}
     */
    public function runIncremental(string $search, string $replace, array $state = [], int $timeBudget = 4): array
    {
        return $this->runIncrementalBatch([[$search, $replace]], $state, $timeBudget);
    }

    /**
     * @param array<int,array{0:string,1:string}|array{search:string,replace:string}> $replacements
     * @param array<string,mixed> $state
     * @return array{state:array<string,mixed>,completed:bool,progress:float,metrics:array<string,mixed>}
     */
    public function runIncrementalBatch(array $replacements, array $state = [], int $timeBudget = 4): array
    {
        $replacements = $this->normalizeReplacements($replacements);
        if ($replacements === []) {
            return [
                'state' => [],
                'completed' => true,
                'progress' => 100.0,
                'metrics' => [
                    'rows_scanned' => 0,
                    'rows_updated' => 0,
                    'tables_completed' => 0,
                    'current_table' => null,
                    'table_index' => 0,
                    'total_tables' => 0,
                    'cursor_stalls' => 0,
                ],
            ];
        }

        $start = microtime(true);
        $hash = md5((string) wp_json_encode($replacements));

        if (empty($state) || (string) ($state['replacements_hash'] ?? '') !== $hash) {
            $prefixLike = method_exists($this->db, 'esc_like')
                ? $this->db->esc_like($this->db->prefix)
                : addcslashes($this->db->prefix, '_%\\');

            $tables = $this->db->get_col($this->db->prepare('SHOW TABLES LIKE %s', $prefixLike . '%'));
            $tables = is_array($tables) ? array_values($tables) : [];

            $tables = array_values(array_filter($tables, function (string $table): bool {
                return ! $this->isInternalRuntimeTable($table, (string) $this->db->prefix);
            }));

            $allow = apply_filters('lift_teleport_replace_table_allowlist', []);
            if (is_array($allow) && $allow !== []) {
                $allowed = array_map('strval', $allow);
                $tables = array_values(array_filter($tables, static fn (string $table): bool => in_array($table, $allowed, true)));
            }

            $deny = apply_filters('lift_teleport_replace_table_denylist', []);
            if (is_array($deny) && $deny !== []) {
                $blocked = array_map('strval', $deny);
                $tables = array_values(array_filter($tables, static fn (string $table): bool => ! in_array($table, $blocked, true)));
            }

            $state = [
                'replacements_hash' => $hash,
                'tables' => $tables,
                'table_index' => 0,
                'table_states' => [],
            ];
        }

        $tables = is_array($state['tables'] ?? null) ? array_values($state['tables']) : [];
        $tableIndex = (int) ($state['table_index'] ?? 0);
        $tableStates = is_array($state['table_states'] ?? null) ? $state['table_states'] : [];

        $chunk = (int) apply_filters('lift_teleport_replace_chunk_size', 200);
        if ($chunk <= 0) {
            $chunk = 200;
        }

        $totalTables = max(1, count($tables));

        $metrics = [
            'rows_scanned' => 0,
            'rows_updated' => 0,
            'tables_completed' => 0,
            'current_table' => null,
            'table_index' => $tableIndex,
            'total_tables' => count($tables),
            'cursor_stalls' => 0,
        ];

        while ($tableIndex < count($tables) && (microtime(true) - $start) < $timeBudget) {
            $table = (string) $tables[$tableIndex];
            $tableState = is_array($tableStates[$table] ?? null) ? $tableStates[$table] : $this->describeTable($table);
            $metrics['current_table'] = $table;

            $textColumns = is_array($tableState['text_columns'] ?? null) ? $tableState['text_columns'] : [];
            if ($textColumns === []) {
                $tableIndex++;
                $metrics['tables_completed']++;
                $metrics['table_index'] = $tableIndex;
                unset($tableStates[$table]);
                continue;
            }

            $rows = $this->fetchRows($table, $tableState, $chunk);
            if (! is_array($rows)) {
                throw new RuntimeException(sprintf('Failed to scan table for URL replacement: %s', $table));
            }

            $rowCount = count($rows);
            $metrics['rows_scanned'] += $rowCount;
            $tableState['processed_rows'] = (int) ($tableState['processed_rows'] ?? 0) + $rowCount;

            foreach ($rows as $row) {
                $updates = [];
                foreach ($textColumns as $column) {
                    $value = $row[$column] ?? null;
                    if (! is_string($value) || ! $this->containsAnySearch($value, $replacements)) {
                        continue;
                    }

                    $updatedValue = $this->replaceMaybeSerializedMany($value, $replacements);
                    if ($updatedValue !== $value) {
                        $updates[$column] = $updatedValue;
                    }
                }

                if ($updates === []) {
                    continue;
                }

                $primary = (string) ($tableState['primary'] ?? '');
                if ($primary === '' || ! array_key_exists($primary, $row)) {
                    continue;
                }

                $where = [$primary => $row[$primary]];
                $updated = $this->db->update($table, $updates, $where);
                if ($updated !== false && $updated > 0) {
                    $metrics['rows_updated'] += (int) $updated;
                }
            }

            $tableCompleted = $rowCount < $chunk;

            if (($tableState['mode'] ?? 'offset') === 'keyset') {
                if ($rows !== [] && ! empty($tableState['primary'])) {
                    $last = $rows[$rowCount - 1];
                    $primary = (string) $tableState['primary'];
                    $lastCursor = $last[$primary] ?? null;
                    $previousCursor = $tableState['row_cursor'] ?? null;

                    if ($lastCursor !== null && $previousCursor !== null && (string) $lastCursor === (string) $previousCursor) {
                        // Fallback to offset mode if keyset cursor does not advance.
                        $tableState['mode'] = 'offset';
                        $tableState['row_offset'] = (int) ($tableState['row_offset'] ?? 0) + $rowCount;
                        $tableState['row_cursor'] = null;
                        $tableState['cursor_stall_count'] = (int) ($tableState['cursor_stall_count'] ?? 0) + 1;
                        $metrics['cursor_stalls']++;
                    } elseif ($lastCursor !== null) {
                        $tableState['row_cursor'] = $lastCursor;
                    }
                }
            } else {
                $tableState['row_offset'] = (int) ($tableState['row_offset'] ?? 0) + $rowCount;
            }

            if ($tableCompleted) {
                $tableIndex++;
                $metrics['tables_completed']++;
                $metrics['table_index'] = $tableIndex;
                unset($tableStates[$table]);
                continue;
            }

            $tableStates[$table] = $tableState;
            $metrics['table_index'] = $tableIndex;
        }

        $completed = $tableIndex >= count($tables);
        $progress = $completed ? 100.0 : $this->estimateProgress($tables, $tableIndex, $tableStates, $totalTables, $chunk);

        return [
            'state' => [
                'replacements_hash' => $hash,
                'tables' => $tables,
                'table_index' => $tableIndex,
                'table_states' => $tableStates,
            ],
            'completed' => $completed,
            'progress' => $progress,
            'metrics' => $metrics,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function describeTable(string $table): array
    {
        $columns = $this->db->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
        if (! is_array($columns) || $columns === []) {
            return [
                'primary' => null,
                'primary_type' => null,
                'text_columns' => [],
                'mode' => 'offset',
                'row_offset' => 0,
                'row_cursor' => null,
                'processed_rows' => 0,
                'estimated_rows' => 0,
                'cursor_stall_count' => 0,
            ];
        }

        $primary = null;
        $primaryType = null;
        $textColumns = [];

        foreach ($columns as $column) {
            $name = (string) ($column['Field'] ?? '');
            $type = strtolower((string) ($column['Type'] ?? ''));
            $key = (string) ($column['Key'] ?? '');

            if ($primary === null && $key === 'PRI') {
                $primary = $name;
                $primaryType = $type;
            }

            if ($name !== '' && preg_match('/char|text|json/i', $type)) {
                $textColumns[] = $name;
            }
        }

        $estimatedRows = 0;
        if (method_exists($this->db, 'get_var')) {
            $estimate = $this->db->get_var(
                $this->db->prepare(
                    'SELECT TABLE_ROWS FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
                    $table
                )
            );
            $estimatedRows = max(0, (int) $estimate);
        }

        return [
            'primary' => $primary,
            'primary_type' => $primaryType,
            'text_columns' => $textColumns,
            'mode' => $primary !== null ? 'keyset' : 'offset',
            'row_offset' => 0,
            'row_cursor' => null,
            'processed_rows' => 0,
            'estimated_rows' => $estimatedRows,
            'cursor_stall_count' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $tableState
     * @return array<int,array<string,mixed>>
     */
    private function fetchRows(string $table, array $tableState, int $chunk): array
    {
        $mode = (string) ($tableState['mode'] ?? 'offset');
        $textColumns = is_array($tableState['text_columns'] ?? null) ? $tableState['text_columns'] : [];
        $primary = (string) ($tableState['primary'] ?? '');

        if ($textColumns === []) {
            return [];
        }

        $selectedColumns = $textColumns;
        if ($primary !== '') {
            array_unshift($selectedColumns, $primary);
        }

        $selectedColumns = array_values(array_unique($selectedColumns));
        $selectSql = implode(', ', array_map([$this, 'quoteIdentifier'], $selectedColumns));

        if ($mode === 'keyset' && $primary !== '') {
            $cursor = $tableState['row_cursor'] ?? null;
            $type = (string) ($tableState['primary_type'] ?? '');
            $integerPrimary = $this->isIntegerLikeType($type);

            if ($cursor === null) {
                $query = sprintf(
                    'SELECT %s FROM %s ORDER BY %s ASC LIMIT %d',
                    $selectSql,
                    $this->quoteIdentifier($table),
                    $this->quoteIdentifier($primary),
                    $chunk
                );
                $rows = $this->db->get_results($query, ARRAY_A);
                return is_array($rows) ? $rows : [];
            }

            if ($integerPrimary) {
                $query = sprintf(
                    'SELECT %s FROM %s WHERE %s > %%d ORDER BY %s ASC LIMIT %%d',
                    $selectSql,
                    $this->quoteIdentifier($table),
                    $this->quoteIdentifier($primary),
                    $this->quoteIdentifier($primary)
                );

                $rows = $this->db->get_results(
                    $this->db->prepare($query, (int) $cursor, $chunk),
                    ARRAY_A
                );
            } else {
                $query = sprintf(
                    'SELECT %s FROM %s WHERE %s > %%s ORDER BY %s ASC LIMIT %%d',
                    $selectSql,
                    $this->quoteIdentifier($table),
                    $this->quoteIdentifier($primary),
                    $this->quoteIdentifier($primary)
                );

                $rows = $this->db->get_results(
                    $this->db->prepare($query, (string) $cursor, $chunk),
                    ARRAY_A
                );
            }

            return is_array($rows) ? $rows : [];
        }

        $offset = (int) ($tableState['row_offset'] ?? 0);
        $query = sprintf(
            'SELECT %s FROM %s LIMIT %%d OFFSET %%d',
            $selectSql,
            $this->quoteIdentifier($table)
        );

        $rows = $this->db->get_results($this->db->prepare($query, $chunk, $offset), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    private function replaceMaybeSerialized(string $value, string $search, string $replace): string
    {
        return $this->replaceMaybeSerializedMany($value, [
            ['search' => $search, 'replace' => $replace],
        ]);
    }

    /**
     * @param array<int,array{search:string,replace:string}> $replacements
     */
    private function replaceMaybeSerializedMany(string $value, array $replacements): string
    {
        if (! is_serialized($value)) {
            return $this->applyReplacements($value, $replacements);
        }

        $decoded = @unserialize($value);
        if ($decoded === false && $value !== 'b:0;') {
            return $this->applyReplacements($value, $replacements);
        }

        $updated = $this->recursiveReplace($decoded, $replacements);
        return serialize($updated);
    }

    /**
     * @param array<int,array{search:string,replace:string}> $replacements
     */
    private function recursiveReplace(mixed $data, array $replacements): mixed
    {
        if (is_string($data)) {
            return $this->applyReplacements($data, $replacements);
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->recursiveReplace($value, $replacements);
            }
            return $data;
        }

        if (is_object($data)) {
            if ($this->isIncompleteClassObject($data)) {
                // Skip unknown-class payloads safely; mutating incomplete objects can fatally error.
                return $data;
            }

            foreach ($data as $key => $value) {
                $data->{$key} = $this->recursiveReplace($value, $replacements);
            }
            return $data;
        }

        return $data;
    }

    /**
     * @param array<int,array{0:string,1:string}|array{search:string,replace:string}> $replacements
     * @return array<int,array{search:string,replace:string}>
     */
    private function normalizeReplacements(array $replacements): array
    {
        $normalized = [];
        foreach ($replacements as $pair) {
            if (is_array($pair) && isset($pair[0], $pair[1])) {
                $search = (string) $pair[0];
                $replace = (string) $pair[1];
            } elseif (is_array($pair) && array_key_exists('search', $pair) && array_key_exists('replace', $pair)) {
                $search = (string) $pair['search'];
                $replace = (string) $pair['replace'];
            } else {
                continue;
            }

            if ($search === '' || $search === $replace) {
                continue;
            }

            $key = $search . "\x00" . $replace;
            $normalized[$key] = [
                'search' => $search,
                'replace' => $replace,
            ];
        }

        return array_values($normalized);
    }

    /**
     * @param array<int,array{search:string,replace:string}> $replacements
     */
    private function containsAnySearch(string $value, array $replacements): bool
    {
        foreach ($replacements as $pair) {
            if (str_contains($value, $pair['search'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array{search:string,replace:string}> $replacements
     */
    private function applyReplacements(string $value, array $replacements): string
    {
        foreach ($replacements as $pair) {
            $value = str_replace($pair['search'], $pair['replace'], $value);
        }

        return $value;
    }

    private function isIncompleteClassObject(object $object): bool
    {
        return get_class($object) === '__PHP_Incomplete_Class';
    }

    /**
     * @param array<int,string> $tables
     * @param array<string,mixed> $tableStates
     */
    private function estimateProgress(array $tables, int $tableIndex, array $tableStates, int $totalTables, int $chunk): float
    {
        $fraction = 0.0;
        $table = $tables[$tableIndex] ?? null;
        if (is_string($table) && isset($tableStates[$table]) && is_array($tableStates[$table])) {
            $state = $tableStates[$table];
            $processed = max(0, (int) ($state['processed_rows'] ?? 0));
            $estimated = max(0, (int) ($state['estimated_rows'] ?? 0));

            if ($estimated > 0) {
                $fraction = min(0.99, (float) $processed / (float) max(1, $estimated));
            } elseif ($processed > 0) {
                $fraction = min(0.99, (float) $processed / (float) max(1, $chunk * 20));
            }
        }

        $progress = ((float) $tableIndex + $fraction) / (float) max(1, $totalTables);
        return min(100.0, max(0.0, $progress * 100.0));
    }

    private function isIntegerLikeType(string $type): bool
    {
        return preg_match('/tinyint|smallint|mediumint|int|bigint|serial|bit/i', $type) === 1;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @return array<int,string>
     */
    private function internalRuntimeTables(string $prefix): array
    {
        $suffixes = apply_filters('lift_teleport_internal_runtime_tables', [
            'lift_jobs',
            'lift_job_events',
        ]);

        if (! is_array($suffixes)) {
            $suffixes = ['lift_jobs', 'lift_job_events'];
        }

        $tables = [];
        foreach ($suffixes as $suffix) {
            $suffix = trim((string) $suffix);
            if ($suffix === '') {
                continue;
            }

            $tables[] = $prefix . $suffix;
        }

        return array_values(array_unique($tables));
    }

    private function isInternalRuntimeTable(string $table, string $prefix): bool
    {
        foreach ($this->internalRuntimeTables($prefix) as $internalTable) {
            if (strcasecmp($table, $internalTable) === 0) {
                return true;
            }
        }

        return false;
    }
}
