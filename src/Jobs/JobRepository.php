<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs;

use DateTimeImmutable;
use LiftTeleport\Support\SchemaManager;
use LiftTeleport\Support\SchemaOutOfSyncException;
use RuntimeException;
use wpdb;

final class JobRepository
{
    private const DEFAULT_ACTIVE_JOB_STALE_SECONDS = 600;
    private const DEFAULT_SCHEMA_REPAIR_RETRY_INTERVAL_SECONDS = 5;

    /**
     * @var string[]
     */
    private const JOB_WRITABLE_COLUMNS = [
        'type',
        'status',
        'current_step',
        'attempts',
        'progress',
        'message',
        'payload',
        'result',
        'job_token',
        'lock_owner',
        'locked_until',
        'heartbeat_at',
        'worker_heartbeat_at',
        'started_at',
        'finished_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @var string[]
     */
    private const JOB_CRITICAL_COLUMNS = [
        'status',
        'current_step',
        'attempts',
        'progress',
        'message',
        'payload',
        'result',
        'lock_owner',
        'locked_until',
        'heartbeat_at',
        'worker_heartbeat_at',
        'started_at',
        'finished_at',
        'updated_at',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_FAILED_ROLLBACK = 'failed_rollback';
    public const STATUS_CANCELLED = 'cancelled';

    private wpdb $db;

    private SchemaManager $schema;

    /**
     * @var array<string,bool>|null
     */
    private ?array $jobsColumnsCache = null;

    private int $lastSchemaRepairAttemptAt = 0;

    public function __construct(?wpdb $db = null)
    {
        global $wpdb;
        $this->db = $db ?: $wpdb;
        $this->schema = new SchemaManager($this->db);
    }

    public function jobsTable(): string
    {
        return $this->db->prefix . 'lift_jobs';
    }

    public function eventsTable(): string
    {
        return $this->db->prefix . 'lift_job_events';
    }

    public function createTables(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $this->db->get_charset_collate();

        $jobsSql = "CREATE TABLE {$this->jobsTable()} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            current_step VARCHAR(120) DEFAULT NULL,
            attempts INT(11) NOT NULL DEFAULT 0,
            progress DECIMAL(5,2) NOT NULL DEFAULT 0,
            message TEXT DEFAULT NULL,
            payload LONGTEXT DEFAULT NULL,
            result LONGTEXT DEFAULT NULL,
            job_token VARCHAR(128) DEFAULT NULL,
            lock_owner VARCHAR(64) DEFAULT NULL,
            locked_until DATETIME DEFAULT NULL,
            heartbeat_at DATETIME DEFAULT NULL,
            worker_heartbeat_at DATETIME DEFAULT NULL,
            started_at DATETIME DEFAULT NULL,
            finished_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY updated_at (updated_at),
            KEY job_token (job_token),
            KEY status_type_updated_at (status, type, updated_at),
            KEY created_at (created_at)
        ) {$charset};";

        $eventsSql = "CREATE TABLE {$this->eventsTable()} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id BIGINT(20) UNSIGNED NOT NULL,
            level VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            context LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY job_id (job_id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($jobsSql);
        dbDelta($eventsSql);
        $this->repairSchemaIfNeeded();
    }

    /**
     * @return array<string,mixed>
     */
    public function inspectSchema(bool $refresh = false): array
    {
        return $this->schema->inspect($refresh);
    }

    public function isSchemaHealthy(bool $refresh = false): bool
    {
        return $this->schema->isHealthy($refresh);
    }

    /**
     * @return array<string,mixed>
     */
    public function repairSchemaIfNeeded(): array
    {
        $result = $this->schema->repairIfNeeded();
        $this->jobsColumnsCache = null;
        return $result;
    }

    public function hasActiveJob(): bool
    {
        $this->cleanupStaleActiveJobs();
        $this->ensureJobColumns(['status']);

        $statuses = [
            self::STATUS_PENDING,
            self::STATUS_UPLOADING,
            self::STATUS_RUNNING,
        ];

        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = $this->db->prepare(
            "SELECT id FROM {$this->jobsTable()} WHERE status IN ({$placeholders}) LIMIT 1",
            ...$statuses
        );

        return (bool) $this->db->get_var($sql);
    }

    public function hasActiveImportJob(): bool
    {
        $this->cleanupStaleActiveJobs();
        $this->ensureJobColumns(['type', 'status']);

        $statuses = [
            self::STATUS_PENDING,
            self::STATUS_UPLOADING,
            self::STATUS_RUNNING,
        ];

        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = $this->db->prepare(
            "SELECT id FROM {$this->jobsTable()} WHERE type = %s AND status IN ({$placeholders}) LIMIT 1",
            'import',
            ...$statuses
        );

        return (bool) $this->db->get_var($sql);
    }

    public function isImportJobActive(int $jobId): bool
    {
        $this->cleanupStaleActiveJobs();
        $this->ensureJobColumns(['id', 'type', 'status']);

        if ($jobId <= 0) {
            return false;
        }

        $sql = $this->db->prepare(
            "SELECT id FROM {$this->jobsTable()} WHERE id = %d AND type = %s AND status IN (%s, %s, %s) LIMIT 1",
            $jobId,
            'import',
            self::STATUS_PENDING,
            self::STATUS_UPLOADING,
            self::STATUS_RUNNING
        );

        return (bool) $this->db->get_var($sql);
    }

    public function create(string $type, array $payload, string $initialStep, string $status = self::STATUS_PENDING): array
    {
        $now = current_time('mysql', true);
        $insertData = [
            'type' => $type,
            'status' => $status,
            'current_step' => $initialStep,
            'payload' => wp_json_encode($payload),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $insertFormats = ['%s', '%s', '%s', '%s', '%s', '%s'];

        $token = $this->extractJobToken($payload);
        if ($token !== '' && isset($this->jobsColumns()['job_token'])) {
            $insertData['job_token'] = $token;
            $insertFormats[] = '%s';
        }

        $inserted = $this->db->insert(
            $this->jobsTable(),
            $insertData,
            $insertFormats
        );

        if (! $inserted) {
            throw new \RuntimeException('Unable to create job record.');
        }

        $job = $this->get((int) $this->db->insert_id);
        if (! $job) {
            throw new \RuntimeException('Job record was not found after insert.');
        }

        $eventContext = ['type' => $type];
        $fingerprint = is_array($payload['runtime_fingerprint'] ?? null) ? $payload['runtime_fingerprint'] : [];
        if ($fingerprint !== []) {
            $eventContext['runtime_fingerprint'] = $fingerprint;
        }

        $this->addEvent((int) $job['id'], 'info', 'Job created', $eventContext);
        if ($fingerprint !== []) {
            $this->addEvent((int) $job['id'], 'info', 'runtime_fingerprint', $fingerprint);
        }

        return $job;
    }

    public function get(int $jobId): ?array
    {
        $sql = $this->db->prepare("SELECT * FROM {$this->jobsTable()} WHERE id = %d", $jobId);
        $row = $this->db->get_row($sql, ARRAY_A);
        if (! $row) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['attempts'] = (int) $row['attempts'];
        $row['progress'] = (float) $row['progress'];
        $row['payload'] = $this->decodeJsonField($row['payload']);
        $row['result'] = $this->decodeJsonField($row['result']);

        return $row;
    }

    public function findByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $hasTokenColumn = isset($this->jobsColumns()['job_token']);
        if ($hasTokenColumn) {
            $sql = $this->db->prepare(
                "SELECT id FROM {$this->jobsTable()} WHERE job_token = %s ORDER BY id DESC LIMIT 1",
                $token
            );
            $directId = (int) $this->db->get_var($sql);
            if ($directId > 0) {
                $direct = $this->get($directId);
                if ($direct !== null) {
                    return $direct;
                }
            }
        }

        $needle = '%"job_token":"' . $this->db->esc_like($token) . '"%';
        $sql = $this->db->prepare(
            "SELECT id FROM {$this->jobsTable()} WHERE payload LIKE %s ORDER BY id DESC LIMIT 50",
            $needle
        );
        $ids = $this->db->get_col($sql);
        if (! is_array($ids) || $ids === []) {
            return null;
        }

        foreach ($ids as $candidateId) {
            $job = $this->get((int) $candidateId);
            if (! $job) {
                continue;
            }

            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $stored = isset($payload['job_token']) && is_string($payload['job_token']) ? $payload['job_token'] : '';
            if ($stored !== '' && hash_equals($stored, $token)) {
                if ($hasTokenColumn && ((string) ($job['job_token'] ?? '')) === '') {
                    $this->update((int) $job['id'], ['job_token' => $stored]);
                }
                return $job;
            }
        }

        return null;
    }

    public function findRecentByRequester(int $userId, string $type, int $seconds): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $type = trim($type);
        if ($type === '') {
            return null;
        }

        $seconds = max(60, $seconds);
        $threshold = gmdate('Y-m-d H:i:s', time() - $seconds);

        $sql = $this->db->prepare(
            "SELECT id FROM {$this->jobsTable()} WHERE type = %s AND created_at >= %s ORDER BY id DESC LIMIT 50",
            $type,
            $threshold
        );
        $ids = $this->db->get_col($sql);
        if (! is_array($ids) || $ids === []) {
            return null;
        }

        foreach ($ids as $candidateId) {
            $job = $this->get((int) $candidateId);
            if (! $job) {
                continue;
            }

            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            if ((int) ($payload['requested_by'] ?? 0) === $userId) {
                return $job;
            }
        }

        return null;
    }

    public function getNextRunnable(): ?array
    {
        $this->cleanupStaleActiveJobs();
        $this->ensureJobColumns(['status', 'locked_until']);

        $statuses = [self::STATUS_PENDING, self::STATUS_RUNNING];
        $now = current_time('mysql', true);

        $sql = $this->db->prepare(
            "SELECT * FROM {$this->jobsTable()} WHERE status IN (%s, %s) AND (locked_until IS NULL OR locked_until < %s) ORDER BY id ASC LIMIT 1",
            $statuses[0],
            $statuses[1],
            $now
        );

        $row = $this->db->get_row($sql, ARRAY_A);
        if (! $row) {
            return null;
        }

        return $this->get((int) $row['id']);
    }

    public function cleanupStaleActiveJobs(?int $seconds = null): int
    {
        $seconds = $this->staleSeconds($seconds);
        $threshold = time() - $seconds;
        $table = $this->jobsTable();
        $this->ensureJobColumns(['status']);

        $columns = $this->jobsColumns();
        $selectStatus = isset($columns['status']) ? 'status' : "'' AS status";
        $selectType = isset($columns['type']) ? 'type' : "'' AS type";
        $selectLockedUntil = isset($columns['locked_until']) ? 'locked_until' : 'NULL AS locked_until';
        $selectHeartbeat = isset($columns['heartbeat_at']) ? 'heartbeat_at' : 'NULL AS heartbeat_at';
        $selectWorkerHeartbeat = isset($columns['worker_heartbeat_at']) ? 'worker_heartbeat_at' : 'NULL AS worker_heartbeat_at';
        $selectUpdatedAt = isset($columns['updated_at']) ? 'updated_at' : 'NULL AS updated_at';

        $sql = $this->db->prepare(
            "SELECT id, {$selectStatus}, {$selectType}, {$selectLockedUntil}, {$selectHeartbeat}, {$selectWorkerHeartbeat}, {$selectUpdatedAt}
             FROM {$table}
             WHERE status IN (%s, %s, %s, %s)",
            self::STATUS_PENDING,
            self::STATUS_UPLOADING,
            self::STATUS_RUNNING,
            self::STATUS_DRAFT
        );

        $rows = $this->db->get_results($sql, ARRAY_A);
        if (! is_array($rows) || $rows === []) {
            return 0;
        }

        $now = current_time('mysql', true);
        $recovered = 0;

        foreach ($rows as $row) {
            if ($this->isFreshActiveRow($row, $threshold)) {
                continue;
            }

            $jobId = (int) ($row['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            $status = (string) ($row['status'] ?? '');
            $isRunning = $status === self::STATUS_RUNNING;
            $newStatus = $isRunning ? self::STATUS_FAILED : self::STATUS_CANCELLED;
            $message = $isRunning
                ? 'Worker stalled: no worker heartbeat within timeout.'
                : 'Cancelled automatically after stale inactivity timeout.';

            $updateData = [
                'status' => $newStatus,
                'finished_at' => $now,
                'lock_owner' => null,
                'locked_until' => null,
                'message' => $message,
                'updated_at' => $now,
            ];
            if (isset($columns['heartbeat_at'])) {
                $updateData['heartbeat_at'] = null;
            }
            if (isset($columns['worker_heartbeat_at'])) {
                $updateData['worker_heartbeat_at'] = null;
            }

            $updated = $this->db->update(
                $table,
                $updateData,
                ['id' => $jobId],
                $this->formatsFor($updateData),
                ['%d']
            );

            if (! $updated) {
                continue;
            }

            $recovered++;
            $staleReason = $isRunning ? 'worker_heartbeat_timeout' : 'stale_inactivity';
            $this->addEvent($jobId, $isRunning ? 'error' : 'warning', $isRunning ? 'Job failed: worker heartbeat stalled.' : 'Job auto-cancelled as stale.', [
                'stale_after_seconds' => $seconds,
                'last_heartbeat_at' => (string) ($row['heartbeat_at'] ?? ''),
                'last_worker_heartbeat_at' => (string) ($row['worker_heartbeat_at'] ?? ''),
                'last_updated_at' => (string) ($row['updated_at'] ?? ''),
                'stale_reason' => $staleReason,
                'error_code' => $isRunning ? 'lift_worker_stalled' : '',
            ]);
        }

        return $recovered;
    }

    public function update(int $jobId, array $data): void
    {
        if (isset($data['payload']) && is_array($data['payload'])) {
            $data['payload'] = wp_json_encode($data['payload']);
        }

        if (isset($data['result']) && is_array($data['result'])) {
            $data['result'] = wp_json_encode($data['result']);
        }

        $data['updated_at'] = current_time('mysql', true);

        $rawData = $data;
        $data = $this->prepareUpdateData($jobId, $rawData, true);
        $formats = $this->formatsFor($data);

        $updated = $this->db->update(
            $this->jobsTable(),
            $data,
            ['id' => $jobId],
            $formats,
            ['%d']
        );

        if ($updated !== false) {
            return;
        }

        $lastError = (string) $this->db->last_error;
        if (! $this->isSchemaWriteError($lastError)) {
            throw new RuntimeException($lastError !== '' ? $lastError : 'Unable to update job record.');
        }

        $repair = $this->attemptSchemaRepair('update_failed_schema_mismatch', [
            'job_id' => $jobId,
            'error' => $lastError,
            'columns' => array_keys($rawData),
        ]);

        $data = $this->prepareUpdateData($jobId, $rawData, false);
        $formats = $this->formatsFor($data);

        $retry = $this->db->update(
            $this->jobsTable(),
            $data,
            ['id' => $jobId],
            $formats,
            ['%d']
        );

        if ($retry !== false) {
            return;
        }

        $retryError = (string) $this->db->last_error;
        throw new SchemaOutOfSyncException(
            'Schema mismatch detected in lift_jobs. Auto-repair attempted.',
            [
                'job_id' => $jobId,
                'initial_error' => $lastError,
                'retry_error' => $retryError,
                'attempted_columns' => array_keys($rawData),
                'repair_result' => $repair,
                'missing_columns' => (array) (($repair['missing_columns']['jobs'] ?? [])),
            ]
        );
    }

    public function markRunning(int $jobId): void
    {
        $this->update($jobId, [
            'status' => self::STATUS_RUNNING,
            'heartbeat_at' => current_time('mysql', true),
            'worker_heartbeat_at' => current_time('mysql', true),
            'started_at' => current_time('mysql', true),
        ]);
    }

    public function markCompleted(int $jobId, array $result = []): void
    {
        $this->update($jobId, [
            'status' => self::STATUS_COMPLETED,
            'progress' => 100,
            'result' => $result,
            'finished_at' => current_time('mysql', true),
            'lock_owner' => null,
            'locked_until' => null,
            'heartbeat_at' => null,
            'worker_heartbeat_at' => null,
        ]);

        $this->addEvent($jobId, 'info', 'Job completed', $result);
    }

    public function markFailed(int $jobId, string $message, bool $rollbackFailed = false): void
    {
        $this->update($jobId, [
            'status' => $rollbackFailed ? self::STATUS_FAILED_ROLLBACK : self::STATUS_FAILED,
            'message' => $message,
            'finished_at' => current_time('mysql', true),
            'lock_owner' => null,
            'locked_until' => null,
            'heartbeat_at' => null,
            'worker_heartbeat_at' => null,
        ]);

        $this->addEvent($jobId, 'error', $message);
    }

    public function cancel(int $jobId): void
    {
        $this->update($jobId, [
            'status' => self::STATUS_CANCELLED,
            'finished_at' => current_time('mysql', true),
            'lock_owner' => null,
            'locked_until' => null,
            'heartbeat_at' => null,
            'worker_heartbeat_at' => null,
            'message' => 'Cancelled by user.',
        ]);

        $this->addEvent($jobId, 'warning', 'Job cancelled by user');
    }

    public function claimLock(int $jobId, string $owner, int $seconds = 30): bool
    {
        $this->ensureJobColumns(['lock_owner', 'locked_until']);

        $until = gmdate('Y-m-d H:i:s', time() + $seconds);
        $now = current_time('mysql', true);

        $sql = $this->db->prepare(
            "UPDATE {$this->jobsTable()}
             SET lock_owner = %s, locked_until = %s
             WHERE id = %d
             AND (lock_owner IS NULL OR locked_until IS NULL OR locked_until < %s OR lock_owner = %s)",
            $owner,
            $until,
            $jobId,
            $now,
            $owner
        );

        $updated = $this->db->query($sql);
        if ($updated !== false) {
            return (int) $updated === 1;
        }

        $lastError = (string) $this->db->last_error;
        if (! $this->isSchemaWriteError($lastError)) {
            return false;
        }

        $this->attemptSchemaRepair('claim_lock_failed_schema_mismatch', [
            'job_id' => $jobId,
            'error' => $lastError,
        ]);
        $this->ensureJobColumns(['lock_owner', 'locked_until']);

        $updated = $this->db->query($sql);
        return (int) $updated === 1;
    }

    public function releaseLock(int $jobId, ?string $owner = null): void
    {
        $this->ensureJobColumns(['lock_owner', 'locked_until']);

        if ($owner) {
            $sql = $this->db->prepare(
                "UPDATE {$this->jobsTable()} SET lock_owner = NULL, locked_until = NULL WHERE id = %d AND lock_owner = %s",
                $jobId,
                $owner
            );
            $this->db->query($sql);
            return;
        }

        $this->update($jobId, ['lock_owner' => null, 'locked_until' => null]);
    }

    public function requestCancel(int $jobId): void
    {
        $job = $this->get($jobId);
        if (! $job) {
            return;
        }

        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $payload['cancel_requested'] = true;
        $payload['cancel_requested_at'] = gmdate(DATE_ATOM);

        $this->update($jobId, [
            'payload' => $payload,
            'message' => 'Cancellation requested.',
        ]);

        $this->addEvent($jobId, 'warning', 'Cancellation requested');
    }

    public function addEvent(int $jobId, string $level, string $message, array $context = []): void
    {
        $this->db->insert(
            $this->eventsTable(),
            [
                'job_id' => $jobId,
                'level' => $level,
                'message' => $message,
                'context' => wp_json_encode($context),
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }

    public function events(int $jobId, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $sql = $this->db->prepare(
            "SELECT * FROM {$this->eventsTable()} WHERE job_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
            $jobId,
            $perPage,
            $offset
        );

        $rows = $this->db->get_results($sql, ARRAY_A);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['job_id'] = (int) $row['job_id'];
            $row['context'] = json_decode((string) $row['context'], true) ?: [];
            return $row;
        }, $rows ?: []);
    }

    public function recent(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $sql = $this->db->prepare(
            "SELECT * FROM {$this->jobsTable()} ORDER BY id DESC LIMIT %d",
            $limit
        );

        $rows = $this->db->get_results($sql, ARRAY_A) ?: [];

        return array_values(array_filter(array_map(function (array $row): array {
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['attempts'] = (int) ($row['attempts'] ?? 0);
            $row['progress'] = (float) ($row['progress'] ?? 0);
            $row['payload'] = $this->decodeJsonField(isset($row['payload']) ? (string) $row['payload'] : null);
            $row['result'] = $this->decodeJsonField(isset($row['result']) ? (string) $row['result'] : null);
            return $row;
        }, $rows), static fn (array $row): bool => (int) ($row['id'] ?? 0) > 0));
    }

    public function cleanupOldJobs(int $days = 7): int
    {
        $days = max(1, $days);
        $threshold = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $statuses = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_FAILED_ROLLBACK, self::STATUS_CANCELLED];

        $sqlIds = $this->db->prepare(
            "SELECT id FROM {$this->jobsTable()} WHERE status IN (%s, %s, %s, %s) AND updated_at < %s",
            $statuses[0],
            $statuses[1],
            $statuses[2],
            $statuses[3],
            $threshold
        );

        $ids = $this->db->get_col($sqlIds);
        if (! $ids) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $idParams = array_map('intval', $ids);

        $eventSql = $this->db->prepare(
            "DELETE FROM {$this->eventsTable()} WHERE job_id IN ({$placeholders})",
            ...$idParams
        );
        $this->db->query($eventSql);

        $jobSql = $this->db->prepare(
            "DELETE FROM {$this->jobsTable()} WHERE id IN ({$placeholders})",
            ...$idParams
        );
        $deleted = $this->db->query($jobSql);

        return (int) $deleted;
    }

    /**
     * @return array<int,int>
     */
    public function staleTerminalJobIdsByType(string $type, int $seconds, int $limit = 200): array
    {
        $type = trim($type);
        if ($type === '') {
            return [];
        }

        $seconds = max(60, $seconds);
        $limit = max(1, min(1000, $limit));
        $threshold = gmdate('Y-m-d H:i:s', time() - $seconds);

        $sql = $this->db->prepare(
            "SELECT id FROM {$this->jobsTable()}
             WHERE type = %s
               AND status IN (%s, %s, %s, %s)
               AND updated_at < %s
             ORDER BY id ASC
             LIMIT %d",
            $type,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_FAILED_ROLLBACK,
            self::STATUS_CANCELLED,
            $threshold,
            $limit
        );

        $ids = $this->db->get_col($sql);
        if (! is_array($ids) || $ids === []) {
            return [];
        }

        return array_values(array_map('intval', $ids));
    }

    public function deleteWithEvents(int $jobId): void
    {
        if ($jobId <= 0) {
            return;
        }

        $this->db->delete($this->eventsTable(), ['job_id' => $jobId], ['%d']);
        $this->db->delete($this->jobsTable(), ['id' => $jobId], ['%d']);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function prepareUpdateData(int $jobId, array $data, bool $allowRepair): array
    {
        $filtered = [];
        foreach ($data as $column => $value) {
            if (! in_array((string) $column, self::JOB_WRITABLE_COLUMNS, true)) {
                continue;
            }
            $filtered[(string) $column] = $value;
        }

        if ($filtered === []) {
            throw new RuntimeException('No writable columns provided for job update.');
        }

        $available = $this->jobsColumns();
        $missing = array_values(array_diff(array_keys($filtered), array_keys($available)));

        if ($missing !== [] && $allowRepair) {
            $repair = $this->attemptSchemaRepair('update_missing_columns', [
                'job_id' => $jobId,
                'missing_columns' => $missing,
            ]);

            $this->safeAddSchemaEvent($jobId, 'schema_drift_detected', [
                'missing_columns' => $missing,
                'repair_status' => (string) ($repair['status'] ?? ''),
            ]);

            $available = $this->jobsColumns(true);
            $missing = array_values(array_diff(array_keys($filtered), array_keys($available)));
        }

        if ($missing !== []) {
            $criticalMissing = array_values(array_intersect($missing, self::JOB_CRITICAL_COLUMNS));
            if ($criticalMissing !== []) {
                throw new SchemaOutOfSyncException(
                    'Schema mismatch detected in lift_jobs. Auto-repair attempted.',
                    [
                        'job_id' => $jobId,
                        'missing_columns' => $criticalMissing,
                    ]
                );
            }

            foreach ($missing as $column) {
                unset($filtered[$column]);
            }
        }

        if ($filtered === []) {
            throw new RuntimeException('No update columns remain after schema validation.');
        }

        return $filtered;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function attemptSchemaRepair(string $reason, array $context = []): array
    {
        $interval = (int) apply_filters(
            'lift_teleport_schema_repair_retry_interval_seconds',
            self::DEFAULT_SCHEMA_REPAIR_RETRY_INTERVAL_SECONDS
        );
        $interval = max(0, $interval);

        $now = time();
        if ($interval > 0 && $this->lastSchemaRepairAttemptAt > 0 && ($now - $this->lastSchemaRepairAttemptAt) < $interval) {
            return [
                'status' => 'throttled',
                'changed' => false,
                'health' => $this->isSchemaHealthy(true),
                'reason' => $reason,
                'context' => $context,
            ];
        }

        $this->lastSchemaRepairAttemptAt = $now;
        $jobId = isset($context['job_id']) ? (int) $context['job_id'] : 0;
        $this->safeAddSchemaEvent($jobId, 'schema_repair_started', [
            'reason' => $reason,
            'context' => $context,
        ]);

        $result = $this->repairSchemaIfNeeded();
        $this->safeAddSchemaEvent(
            $jobId,
            ! empty($result['health']) ? 'schema_repair_completed' : 'schema_repair_failed',
            [
                'reason' => $reason,
                'repair_result' => $result,
            ]
        );
        return $result;
    }

    /**
     * @param string[] $columns
     */
    private function ensureJobColumns(array $columns): void
    {
        $available = $this->jobsColumns();
        $missing = array_values(array_diff($columns, array_keys($available)));
        if ($missing === []) {
            return;
        }

        $repair = $this->attemptSchemaRepair('required_columns_missing', [
            'missing_columns' => $missing,
        ]);
        $available = $this->jobsColumns(true);
        $missing = array_values(array_diff($columns, array_keys($available)));
        if ($missing === []) {
            return;
        }

        throw new SchemaOutOfSyncException(
            'Schema mismatch detected in lift_jobs. Auto-repair attempted.',
            [
                'missing_columns' => $missing,
                'repair_result' => $repair,
            ]
        );
    }

    /**
     * @return array<string,bool>
     */
    private function jobsColumns(bool $refresh = false): array
    {
        if (! $refresh && is_array($this->jobsColumnsCache)) {
            return $this->jobsColumnsCache;
        }

        $this->jobsColumnsCache = $this->schema->listTableColumns($this->jobsTable(), true);
        return $this->jobsColumnsCache;
    }

    /**
     * @param array<string,mixed> $data
     * @return string[]
     */
    private function formatsFor(array $data): array
    {
        $formats = [];
        foreach ($data as $value) {
            if (is_int($value)) {
                $formats[] = '%d';
            } elseif (is_float($value)) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }

        return $formats;
    }

    private function isSchemaWriteError(string $error): bool
    {
        if ($error === '') {
            return false;
        }

        $normalized = strtolower($error);
        $patterns = [
            'unknown column',
            'no such column',
            'has no column named',
            'no such table',
            'column not found',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function safeAddSchemaEvent(int $jobId, string $message, array $context = []): void
    {
        if ($jobId <= 0) {
            return;
        }

        try {
            $this->addEvent($jobId, 'warning', $message, $context);
        } catch (\Throwable) {
            // Avoid recursive failures while schema is recovering.
        }
    }

    private function decodeJsonField(?string $value): array
    {
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function extractJobToken(array $payload): string
    {
        $token = isset($payload['job_token']) && is_string($payload['job_token'])
            ? trim($payload['job_token'])
            : '';

        if ($token === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/', $token) !== 1) {
            return '';
        }

        return $token;
    }

    private function staleSeconds(?int $seconds = null): int
    {
        $default = self::DEFAULT_ACTIVE_JOB_STALE_SECONDS;
        $value = $seconds ?? $default;
        $value = (int) apply_filters('lift_teleport_active_job_stale_seconds', $value);
        return max(60, $value);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isFreshActiveRow(array $row, int $thresholdUnix): bool
    {
        $status = (string) ($row['status'] ?? '');
        $lockUntil = isset($row['locked_until']) && is_string($row['locked_until'])
            ? strtotime($row['locked_until'])
            : false;
        if ($status !== self::STATUS_RUNNING && $lockUntil !== false && $lockUntil >= time()) {
            return true;
        }

        $heartbeat = isset($row['heartbeat_at']) && is_string($row['heartbeat_at'])
            ? strtotime($row['heartbeat_at'])
            : false;
        $workerHeartbeat = isset($row['worker_heartbeat_at']) && is_string($row['worker_heartbeat_at'])
            ? strtotime($row['worker_heartbeat_at'])
            : false;
        if ($workerHeartbeat !== false && $workerHeartbeat >= $thresholdUnix) {
            return true;
        }

        // Backward compatibility for older schema/jobs where worker_heartbeat_at was unavailable.
        if (! array_key_exists('worker_heartbeat_at', $row) && $heartbeat !== false && $heartbeat >= $thresholdUnix) {
            return true;
        }

        if ($status === self::STATUS_RUNNING && $workerHeartbeat === false) {
            return false;
        }

        $updated = isset($row['updated_at']) && is_string($row['updated_at'])
            ? strtotime($row['updated_at'])
            : false;

        return $updated !== false && $updated >= $thresholdUnix;
    }
}
