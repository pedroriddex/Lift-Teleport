<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Import;

use LiftTeleport\Import\DatabaseImporter;
use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Support\CommandRunner;
use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;

final class SnapshotStep extends AbstractStep
{
    private DatabaseImporter $database;

    public function __construct($jobs)
    {
        parent::__construct($jobs);
        $this->database = new DatabaseImporter();
    }

    public function key(): string
    {
        return 'import_snapshot';
    }

    public function run(array $job): array
    {
        $jobId = $this->jobId($job);
        $payload = $this->payload($job);

        $rollbackDir = Paths::jobRollback($jobId);
        Filesystem::ensureDirectory($rollbackDir . '/db');

        $dbSnapshot = $rollbackDir . '/db/before.sql';
        $dbState = is_array($payload['rollback_db_state'] ?? null) ? $payload['rollback_db_state'] : [];
        $result = $this->database->dumpIncremental($dbSnapshot, $dbState, 4);

        $payload['rollback_db_state'] = $result['state'];
        $payload['rollback_db_file'] = $dbSnapshot;

        if (! $result['completed']) {
            return [
                'status' => 'continue',
                'payload' => $payload,
                'progress' => 15 + ($result['progress'] * 0.1),
                'message' => 'Creating rollback database snapshot...',
            ];
        }

        unset($payload['rollback_db_state']);

        $snapshotTar = $rollbackDir . '/wp-content-before.tar';
        if (! file_exists($snapshotTar)) {
            $dirs = [];
            foreach (['plugins', 'themes', 'uploads', 'mu-plugins'] as $dir) {
                if (is_dir(WP_CONTENT_DIR . '/' . $dir)) {
                    $dirs[] = $dir;
                }
            }

            if ($dirs !== []) {
                if (CommandRunner::commandExists('tar')) {
                    $command = sprintf(
                        'tar -chf %s -C %s %s',
                        escapeshellarg($snapshotTar),
                        escapeshellarg(WP_CONTENT_DIR),
                        implode(' ', array_map('escapeshellarg', $dirs))
                    );
                    CommandRunner::run($command);
                }
            }
        }

        $payload['rollback_content_snapshot'] = $snapshotTar;
        $payload['rollback_ready'] = true;

        return [
            'status' => 'next',
            'next_step' => 'import_readonly_on',
            'payload' => $payload,
            'progress' => 55,
            'message' => 'Rollback snapshot created.',
        ];
    }
}
