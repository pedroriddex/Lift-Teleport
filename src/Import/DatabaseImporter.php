<?php

declare(strict_types=1);

namespace LiftTeleport\Import;

use RuntimeException;
use wpdb;

final class DatabaseImporter
{
    private wpdb $db;
    /**
     * @var array<string,mixed>|null
     */
    private ?array $collationCapabilities = null;

    public function __construct(?wpdb $db = null)
    {
        global $wpdb;
        $this->db = $db ?: $wpdb;
    }

    /**
     * @param array<string,mixed> $state
     * @return array{state:array<string,mixed>,completed:bool,progress:float,metrics:array<string,mixed>}
     */
    public function dumpIncremental(string $filePath, array $state = [], int $timeBudget = 5): array
    {
        $start = microtime(true);

        if (empty($state)) {
            $tables = $this->db->get_col('SHOW TABLES');
            if (! is_array($tables)) {
                throw new RuntimeException('Unable to fetch tables for export.');
            }

            $tables = array_values(array_filter(
                $tables,
                fn (string $table): bool => ! $this->isInternalRuntimeTable($table, (string) $this->db->prefix)
            ));

            $state = [
                'tables' => array_values($tables),
                'table_index' => 0,
                'table_states' => [],
            ];

            file_put_contents($filePath, "-- Lift Teleport SQL Dump\n-- Generated at " . gmdate(DATE_ATOM) . "\n\n");
        }

        $tables = is_array($state['tables'] ?? null) ? array_values($state['tables']) : [];
        $tableIndex = (int) ($state['table_index'] ?? 0);
        $tableStates = is_array($state['table_states'] ?? null) ? $state['table_states'] : [];

        $fh = fopen($filePath, 'ab');
        if ($fh === false) {
            throw new RuntimeException('Unable to open SQL dump file for writing.');
        }

        $chunkSize = 300;
        $totalTables = max(1, count($tables));
        $metrics = [
            'rows_exported' => 0,
            'tables_completed' => 0,
            'queries' => 0,
        ];

        while ($tableIndex < count($tables) && (microtime(true) - $start) < $timeBudget) {
            $table = (string) $tables[$tableIndex];
            $tableState = is_array($tableStates[$table] ?? null) ? $tableStates[$table] : $this->describeTable($table);

            if (empty($tableState['create_written'])) {
                $create = $this->db->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
                if (! isset($create[1])) {
                    $tableIndex++;
                    $metrics['tables_completed']++;
                    continue;
                }

                fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($fh, $create[1] . ";\n\n");
                $tableState['create_written'] = true;
                $metrics['queries']++;
            }

            $rows = $this->fetchDumpRows($table, $tableState, $chunkSize);
            $metrics['queries']++;

            if ($rows !== []) {
                foreach ($rows as $row) {
                    $columns = array_map(static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`', array_keys($row));
                    $values = array_map(fn ($value): string => $this->quoteValue($value), array_values($row));

                    fwrite(
                        $fh,
                        sprintf(
                            "INSERT INTO `%s` (%s) VALUES (%s);\n",
                            $table,
                            implode(', ', $columns),
                            implode(', ', $values)
                        )
                    );
                }

                fwrite($fh, "\n");
                $metrics['rows_exported'] += count($rows);
            }

            $tableCompleted = count($rows) < $chunkSize;

            if (($tableState['mode'] ?? 'offset') === 'keyset') {
                if ($rows !== [] && ! empty($tableState['primary'])) {
                    $lastRow = $rows[count($rows) - 1];
                    $primary = (string) $tableState['primary'];
                    $tableState['row_cursor'] = $lastRow[$primary] ?? null;
                }
            } else {
                $tableState['row_offset'] = (int) ($tableState['row_offset'] ?? 0) + count($rows);
            }

            if ($tableCompleted) {
                $tableIndex++;
                $metrics['tables_completed']++;
            } else {
                $tableStates[$table] = $tableState;
            }

            if ($tableCompleted) {
                unset($tableStates[$table]);
            }
        }

        fclose($fh);

        $state['table_index'] = $tableIndex;
        $state['table_states'] = $tableStates;

        $completed = $tableIndex >= count($tables);
        $tableProgress = (float) ($tableIndex / $totalTables) * 100;

        return [
            'state' => $state,
            'completed' => $completed,
            'progress' => min(100.0, $tableProgress),
            'metrics' => $metrics,
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array{state:array<string,mixed>,completed:bool,progress:float,metrics:array<string,mixed>}
     */
    public function importIncremental(string $filePath, string $sourcePrefix, string $destPrefix, array $state = [], int $timeBudget = 5): array
    {
        $start = microtime(true);

        $fileSize = max(1, filesize($filePath));
        $offset = (int) ($state['offset'] ?? 0);
        $buffer = (string) ($state['buffer'] ?? '');

        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Unable to open SQL file for import.');
        }

        if ($offset > 0) {
            fseek($fh, $offset);
        }

        $metrics = [
            'statements_executed' => 0,
            'bytes_read' => 0,
            'statements_skipped' => 0,
        ];

        while (! feof($fh) && (microtime(true) - $start) < $timeBudget) {
            $chunk = fread($fh, 1024 * 256);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
            $metrics['bytes_read'] += strlen($chunk);

            $statements = $this->extractStatements($buffer);
            foreach ($statements as $statement) {
                $query = trim($statement);
                if ($query === '' || $this->isCommentOnly($query)) {
                    continue;
                }

                $query = $this->rewritePrefix($query, $sourcePrefix, $destPrefix);
                if ($this->shouldSkipInternalStatement($query, $sourcePrefix, $destPrefix)) {
                    $metrics['statements_skipped']++;
                    continue;
                }

                $this->executeImportStatement($query);
                $metrics['statements_executed']++;
            }
        }

        if (feof($fh)) {
            $tail = trim($buffer);
            if ($tail !== '' && ! $this->isCommentOnly($tail)) {
                $query = $this->rewritePrefix($tail, $sourcePrefix, $destPrefix);
                if ($this->shouldSkipInternalStatement($query, $sourcePrefix, $destPrefix)) {
                    $metrics['statements_skipped']++;
                    $buffer = '';
                } else {
                    $this->executeImportStatement($query);
                    $metrics['statements_executed']++;
                    $buffer = '';
                }
            }
        }

        $offset = (int) ftell($fh);
        $completed = feof($fh) && trim($buffer) === '';

        fclose($fh);

        return [
            'state' => [
                'offset' => $offset,
                'buffer' => $buffer,
            ],
            'completed' => $completed,
            'progress' => min(100.0, ((float) $offset / (float) $fileSize) * 100),
            'metrics' => $metrics,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function describeTable(string $table): array
    {
        $columns = $this->db->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
        if (! is_array($columns)) {
            return [
                'create_written' => false,
                'mode' => 'offset',
                'row_offset' => 0,
                'row_cursor' => null,
                'primary' => null,
                'primary_type' => null,
            ];
        }

        $primary = null;
        $primaryType = null;
        foreach ($columns as $column) {
            if (($column['Key'] ?? '') === 'PRI') {
                $primary = (string) ($column['Field'] ?? '');
                $primaryType = (string) ($column['Type'] ?? '');
                break;
            }
        }

        return [
            'create_written' => false,
            'mode' => $primary !== null && $primary !== '' ? 'keyset' : 'offset',
            'row_offset' => 0,
            'row_cursor' => null,
            'primary' => $primary,
            'primary_type' => $primaryType,
        ];
    }

    /**
     * @param array<string,mixed> $tableState
     * @return array<int,array<string,mixed>>
     */
    private function fetchDumpRows(string $table, array $tableState, int $chunkSize): array
    {
        $mode = (string) ($tableState['mode'] ?? 'offset');

        if ($mode === 'keyset' && ! empty($tableState['primary'])) {
            $primary = (string) $tableState['primary'];
            $cursor = $tableState['row_cursor'] ?? null;
            $type = (string) ($tableState['primary_type'] ?? '');
            $numeric = preg_match('/int|decimal|float|double|real|bit|numeric|serial/i', $type) === 1;

            if ($cursor === null) {
                $sql = sprintf("SELECT * FROM `%s` ORDER BY `%s` ASC LIMIT %d", $table, $primary, $chunkSize);
                $rows = $this->db->get_results($sql, ARRAY_A);
                return is_array($rows) ? $rows : [];
            }

            if ($numeric) {
                $rows = $this->db->get_results(
                    $this->db->prepare(
                        "SELECT * FROM `{$table}` WHERE `{$primary}` > %f ORDER BY `{$primary}` ASC LIMIT %d",
                        (float) $cursor,
                        $chunkSize
                    ),
                    ARRAY_A
                );
            } else {
                $rows = $this->db->get_results(
                    $this->db->prepare(
                        "SELECT * FROM `{$table}` WHERE `{$primary}` > %s ORDER BY `{$primary}` ASC LIMIT %d",
                        (string) $cursor,
                        $chunkSize
                    ),
                    ARRAY_A
                );
            }

            return is_array($rows) ? $rows : [];
        }

        $offset = (int) ($tableState['row_offset'] ?? 0);
        $rows = $this->db->get_results(
            $this->db->prepare("SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $chunkSize, $offset),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Splits complete SQL statements and keeps the remaining tail in $buffer.
     *
     * @return array<int,string>
     */
    private function extractStatements(string &$buffer): array
    {
        $statements = [];
        $length = strlen($buffer);
        if ($length === 0) {
            return $statements;
        }

        $start = 0;
        $single = false;
        $double = false;
        $backtick = false;
        $lineComment = false;
        $blockComment = false;
        $escape = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $buffer[$i];
            $next = $i + 1 < $length ? $buffer[$i + 1] : '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                }
                continue;
            }

            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                }
                continue;
            }

            if ($single || $double || $backtick) {
                if ($escape) {
                    $escape = false;
                    continue;
                }

                if (($single || $double) && $char === '\\') {
                    $escape = true;
                    continue;
                }

                if ($single && $char === "'") {
                    $single = false;
                    continue;
                }

                if ($double && $char === '"') {
                    $double = false;
                    continue;
                }

                if ($backtick && $char === '`') {
                    $backtick = false;
                }

                continue;
            }

            if ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($buffer[$i + 2]))) {
                $lineComment = true;
                $i++;
                continue;
            }

            if ($char === '#') {
                $lineComment = true;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $i++;
                continue;
            }

            if ($char === "'") {
                $single = true;
                continue;
            }

            if ($char === '"') {
                $double = true;
                continue;
            }

            if ($char === '`') {
                $backtick = true;
                continue;
            }

            if ($char === ';') {
                $statement = substr($buffer, $start, $i - $start + 1);
                if ($statement !== false) {
                    $statements[] = $statement;
                }
                $start = $i + 1;
            }
        }

        $buffer = substr($buffer, $start) ?: '';

        return $statements;
    }

    private function isCommentOnly(string $query): bool
    {
        $sql = ltrim($query);
        if ($sql === '') {
            return true;
        }

        while ($sql !== '') {
            if (str_starts_with($sql, '--')) {
                $newLine = strpos($sql, "\n");
                if ($newLine === false) {
                    return true;
                }

                $sql = ltrim(substr($sql, $newLine + 1));
                continue;
            }

            if ($sql[0] === '#') {
                $newLine = strpos($sql, "\n");
                if ($newLine === false) {
                    return true;
                }

                $sql = ltrim(substr($sql, $newLine + 1));
                continue;
            }

            if (str_starts_with($sql, '/*')) {
                $end = strpos($sql, '*/');
                if ($end === false) {
                    return true;
                }

                $sql = ltrim(substr($sql, $end + 2));
                continue;
            }

            break;
        }

        return $sql === '';
    }

    private function rewritePrefix(string $query, string $sourcePrefix, string $destPrefix): string
    {
        if ($sourcePrefix === '' || $sourcePrefix === $destPrefix) {
            return $query;
        }

        $quotedSource = preg_quote($sourcePrefix, '/');

        $patterns = [
            '/(CREATE TABLE IF NOT EXISTS `)' . $quotedSource . '([^`]+`)/i',
            '/(CREATE TABLE `)' . $quotedSource . '([^`]+`)/i',
            '/(DROP TABLE IF EXISTS `)' . $quotedSource . '([^`]+`)/i',
            '/(INSERT INTO `)' . $quotedSource . '([^`]+`)/i',
            '/(ALTER TABLE `)' . $quotedSource . '([^`]+`)/i',
            '/(LOCK TABLES `)' . $quotedSource . '([^`]+`)/i',
            '/(TRUNCATE TABLE `)' . $quotedSource . '([^`]+`)/i',
            '/(UPDATE `)' . $quotedSource . '([^`]+`)/i',
            '/(DELETE FROM `)' . $quotedSource . '([^`]+`)/i',
        ];

        foreach ($patterns as $pattern) {
            $query = preg_replace($pattern, '$1' . $destPrefix . '$2', $query) ?? $query;
        }

        return $query;
    }

    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $dbh = $this->db->dbh;
        if ($dbh && function_exists('mysqli_real_escape_string')) {
            return "'" . mysqli_real_escape_string($dbh, (string) $value) . "'";
        }

        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
    }

    private function executeImportStatement(string $query): void
    {
        $result = $this->queryWithSuppressedErrors($query);
        if ($result !== false) {
            return;
        }

        $lastError = (string) $this->db->last_error;
        $error = strtolower($lastError);

        if ($this->isUnknownCollationError($lastError)) {
            $rewritten = $this->rewriteUnsupportedCollations($query);
            if ($rewritten !== $query) {
                $retry = $this->queryWithSuppressedErrors($rewritten);
                if ($retry !== false) {
                    return;
                }

                $lastError = (string) $this->db->last_error;
                $error = strtolower($lastError);
                $query = $rewritten;
            }
        }

        if ($this->isCreateTableStatement($query) && str_contains($error, 'already exists')) {
            $table = $this->extractTableNameFromCreate($query);
            if ($table !== '') {
                $dropQuery = sprintf('DROP TABLE IF EXISTS `%s`', str_replace('`', '``', $table));
                $dropResult = $this->queryWithSuppressedErrors($dropQuery);
                if ($dropResult === false) {
                    throw new RuntimeException(sprintf('SQL import failed after create-table conflict: %s', $this->db->last_error));
                }

                $retry = $this->queryWithSuppressedErrors($query);
                if ($retry !== false) {
                    return;
                }
            }
        }

        throw new RuntimeException(sprintf('SQL import failed: %s', $this->db->last_error));
    }

    private function queryWithSuppressedErrors(string $sql): int|bool
    {
        $previous = $this->db->suppress_errors(true);

        try {
            return $this->db->query($sql);
        } finally {
            $this->db->suppress_errors($previous);
        }
    }

    private function isUnknownCollationError(string $error): bool
    {
        return str_contains(strtolower($error), 'unknown collation');
    }

    private function rewriteUnsupportedCollations(string $query): string
    {
        $query = $this->replaceCollationPattern($query, '/\bCOLLATE\s*=\s*[`"\']?([a-zA-Z0-9_]+)[`"\']?/i');
        return $this->replaceCollationPattern($query, '/\bCOLLATE\s+(?!\=)[`"\']?([a-zA-Z0-9_]+)[`"\']?/i');
    }

    private function replaceCollationPattern(string $query, string $pattern): string
    {
        return (string) preg_replace_callback(
            $pattern,
            function (array $matches): string {
                $input = strtolower((string) ($matches[1] ?? ''));
                if ($input === '') {
                    return (string) $matches[0];
                }

                $replacement = $this->resolveSupportedCollation($input);
                if ($replacement === null || $replacement === $input) {
                    return (string) $matches[0];
                }

                return str_replace((string) $matches[1], $replacement, (string) $matches[0]);
            },
            $query
        );
    }

    private function resolveSupportedCollation(string $collation): ?string
    {
        $capabilities = $this->getCollationCapabilities();
        $supported = (array) ($capabilities['supported'] ?? []);
        if (isset($supported[$collation])) {
            return $collation;
        }

        $charset = $this->charsetFromCollation($collation);
        if ($charset === '') {
            return null;
        }

        $defaults = (array) ($capabilities['defaults'] ?? []);
        if (isset($defaults[$charset])) {
            return (string) $defaults[$charset];
        }

        foreach ($this->fallbackCharsets($charset) as $candidateCharset) {
            if (isset($defaults[$candidateCharset])) {
                return (string) $defaults[$candidateCharset];
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function getCollationCapabilities(): array
    {
        if ($this->collationCapabilities !== null) {
            return $this->collationCapabilities;
        }

        $rows = $this->db->get_results('SHOW COLLATION', ARRAY_A);
        if (! is_array($rows)) {
            $this->collationCapabilities = [
                'supported' => [],
                'defaults' => [],
            ];

            return $this->collationCapabilities;
        }

        $supported = [];
        $defaults = [];

        foreach ($rows as $row) {
            $name = strtolower((string) ($row['Collation'] ?? ''));
            $charset = strtolower((string) ($row['Charset'] ?? ''));
            $isDefault = strtoupper((string) ($row['Default'] ?? '')) === 'YES';

            if ($name === '' || $charset === '') {
                continue;
            }

            $supported[$name] = true;

            if ($isDefault && ! isset($defaults[$charset])) {
                $defaults[$charset] = $name;
            }
        }

        $this->collationCapabilities = [
            'supported' => $supported,
            'defaults' => $defaults,
        ];

        return $this->collationCapabilities;
    }

    private function charsetFromCollation(string $collation): string
    {
        $parts = explode('_', strtolower($collation), 2);
        if (! isset($parts[0])) {
            return '';
        }

        $charset = trim((string) $parts[0]);
        if ($charset === 'utf8mb3' || $charset === 'utf8mb4' || $charset === 'utf8') {
            return $charset;
        }

        return $charset;
    }

    /**
     * @return array<int,string>
     */
    private function fallbackCharsets(string $charset): array
    {
        if ($charset === 'utf8mb3' || $charset === 'utf8') {
            return ['utf8mb3', 'utf8', 'utf8mb4'];
        }

        if ($charset === 'utf8mb4') {
            return ['utf8mb4', 'utf8mb3', 'utf8'];
        }

        return [$charset];
    }

    private function isCreateTableStatement(string $query): bool
    {
        return preg_match('/^\\s*CREATE\\s+TABLE\\b/i', $query) === 1;
    }

    private function extractTableNameFromCreate(string $query): string
    {
        if (preg_match('/^\\s*CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?`([^`]+)`/i', $query, $matches) === 1) {
            return (string) $matches[1];
        }

        if (preg_match('/^\\s*CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?([a-zA-Z0-9_]+)/i', $query, $matches) === 1) {
            return (string) $matches[1];
        }

        return '';
    }

    private function shouldSkipInternalStatement(string $query, string $sourcePrefix, string $destPrefix): bool
    {
        $trimmed = ltrim($query);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^(CREATE TABLE|DROP TABLE|INSERT INTO|ALTER TABLE|LOCK TABLES|TRUNCATE TABLE|UPDATE|DELETE FROM)\b/i', $trimmed) !== 1) {
            return false;
        }

        $prefixes = array_values(array_unique(array_filter([
            $sourcePrefix,
            $destPrefix,
            (string) $this->db->prefix,
        ], static fn (string $prefix): bool => $prefix !== '')));

        foreach ($prefixes as $prefix) {
            foreach ($this->internalRuntimeTables($prefix) as $table) {
                $quoted = preg_quote($table, '/');
                if (preg_match('/`' . $quoted . '`/i', $trimmed) === 1) {
                    return true;
                }

                if (preg_match('/\b' . $quoted . '\b/i', $trimmed) === 1) {
                    return true;
                }
            }
        }

        return false;
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
