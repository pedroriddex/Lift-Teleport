<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Import;

use LiftTeleport\Import\DatabaseImporter;
use LiftTeleport\Import\OperatorSessionContinuity;
use LiftTeleport\Import\SerializedReplacer;
use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Support\ArtifactGarbageCollector;
use RuntimeException;
use Throwable;

final class RestoreDatabaseStep extends AbstractStep
{
    private DatabaseImporter $importer;

    private SerializedReplacer $replacer;

    private OperatorSessionContinuity $continuity;

    public function __construct($jobs)
    {
        parent::__construct($jobs);
        $this->importer = new DatabaseImporter();
        $this->replacer = new SerializedReplacer();
        $this->continuity = new OperatorSessionContinuity();
    }

    public function key(): string
    {
        return 'import_restore_database';
    }

    public function run(array $job): array
    {
        global $table_prefix;

        $payload = $this->payload($job);
        $jobId = $this->jobId($job);
        $extracted = (string) ($payload['import_extracted_root'] ?? '');
        if ($extracted === '' || ! is_dir($extracted)) {
            throw new RuntimeException('Extracted package folder is missing.');
        }

        $dumpRelative = ltrim(str_replace('\\', '/', (string) ($payload['import_db_dump_relative'] ?? 'db/dump.sql')), '/');
        if ($dumpRelative === '') {
            $dumpRelative = 'db/dump.sql';
        }

        $dump = $extracted . '/' . $dumpRelative;
        if (! file_exists($dump)) {
            throw new RuntimeException(sprintf('Database dump is missing inside package (%s).', $dumpRelative));
        }

        $phase = (string) ($payload['db_restore_phase'] ?? 'import_sql');

        if ($phase === 'import_sql') {
            $state = is_array($payload['db_import_state'] ?? null) ? $payload['db_import_state'] : [];
            $sourcePrefix = (string) ($payload['import_source_db_prefix'] ?? '');

            $result = $this->importer->importIncremental($dump, $sourcePrefix, $table_prefix, $state, 4);
            $payload['db_import_state'] = $result['state'];
            if (isset($result['metrics']) && is_array($result['metrics'])) {
                $payload['db_import_metrics'] = $result['metrics'];
            }

            if (! $result['completed']) {
                return [
                    'status' => 'continue',
                    'payload' => $payload,
                    'progress' => 68 + ($result['progress'] * 0.22),
                    'message' => 'Restoring database...',
                    'metrics' => $result['metrics'] ?? [],
                ];
            }

            unset($payload['db_import_state']);
            $payload['operator_session_restored'] = false;
            $payload['operator_session_restore_error'] = null;

            if (! empty($payload['operator_session_continuity']) && isset($payload['operator_session_snapshot']) && is_array($payload['operator_session_snapshot'])) {
                $snapshot = $payload['operator_session_snapshot'];
                $strictMode = (bool) apply_filters('lift_teleport_operator_session_restore_strict', false, $job, $snapshot);

                try {
                    $this->continuity->restoreAfterDatabaseImport($snapshot, $table_prefix);
                    $payload['operator_session_restored'] = true;

                    $this->jobs->addEvent($jobId, 'info', 'operator_session_restore_ok', [
                        'requested_by' => (int) ($payload['requested_by'] ?? 0),
                        'restored_user_id' => (int) ($snapshot['user_id'] ?? 0),
                        'restored_meta_count' => is_array($snapshot['meta_rows'] ?? null) ? count($snapshot['meta_rows']) : 0,
                        'strict_mode' => $strictMode,
                    ]);
                } catch (Throwable $error) {
                    $payload['operator_session_restored'] = false;
                    $payload['operator_session_restore_error'] = $error->getMessage();

                    $this->jobs->addEvent($jobId, 'warning', 'operator_session_restore_failed', [
                        'requested_by' => (int) ($payload['requested_by'] ?? 0),
                        'restored_user_id' => (int) ($snapshot['user_id'] ?? 0),
                        'restored_meta_count' => is_array($snapshot['meta_rows'] ?? null) ? count($snapshot['meta_rows']) : 0,
                        'strict_mode' => $strictMode,
                        'error_code' => 'operator_session_restore_failed',
                        'error_message' => $error->getMessage(),
                    ]);

                    if ($strictMode) {
                        throw new RuntimeException('Operator session restoration failed: ' . $error->getMessage(), 0, $error);
                    }
                }
            }

            $payload['db_restore_phase'] = 'replace_urls';
            $payload['replace_batch_state'] = [];

            return [
                'status' => 'continue',
                'payload' => $payload,
                'progress' => 90,
                'message' => 'Database restored. Preparing serialized-safe replacements...',
            ];
        }

        $manifest = is_array($payload['import_manifest'] ?? null) ? $payload['import_manifest'] : [];
        $oldSite = (string) ($manifest['site']['site_url'] ?? '');
        $oldHome = (string) ($manifest['site']['home_url'] ?? '');
        $oldPath = (string) ($manifest['site']['abspath'] ?? '');

        $pairs = array_values(array_filter([
            [$oldSite, site_url()],
            [$oldHome, home_url()],
            [$oldPath, ABSPATH],
        ], static fn (array $pair): bool => $pair[0] !== '' && $pair[0] !== $pair[1]));

        if ($pairs !== []) {
            $state = is_array($payload['replace_batch_state'] ?? null)
                ? $payload['replace_batch_state']
                : (is_array($payload['replace_state'] ?? null) ? $payload['replace_state'] : []);

            $replaceResult = $this->replacer->runIncrementalBatch($pairs, $state, 3);
            $payload['replace_batch_state'] = $replaceResult['state'];
            if (isset($replaceResult['metrics']) && is_array($replaceResult['metrics'])) {
                $payload['replace_metrics'] = $replaceResult['metrics'];
            }

            if (! $replaceResult['completed']) {
                return [
                    'status' => 'continue',
                    'payload' => $payload,
                    'progress' => 90 + ($replaceResult['progress'] * 0.06),
                    'message' => 'Applying serialized-safe replacements...',
                    'metrics' => $replaceResult['metrics'] ?? [],
                ];
            }
        }

        $payload['db_restore_phase'] = 'done';
        unset($payload['replace_batch_state'], $payload['replace_state'], $payload['replace_index']);

        $cleanup = (new ArtifactGarbageCollector($this->jobs))->cleanupAfterStep($job, $this->key());
        if ((int) ($cleanup['bytes_reclaimed'] ?? 0) > 0) {
            $payload = ArtifactGarbageCollector::mergeCleanupPayload($payload, $cleanup, 'import_restore_database');
            $this->jobs->addEvent($jobId, 'info', 'artifact_cleanup_completed', [
                'reason' => 'import_restore_database',
                'bytes_reclaimed' => (int) ($cleanup['bytes_reclaimed'] ?? 0),
                'deleted_paths' => $cleanup['deleted_paths'] ?? [],
            ]);
        }

        return [
            'status' => 'next',
            'next_step' => 'import_finalize',
            'payload' => $payload,
            'progress' => 96,
            'message' => 'Database restored.',
        ];
    }
}
