<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Export;

use LiftTeleport\Import\DatabaseImporter;
use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;

final class DumpDatabaseStep extends AbstractStep
{
    private DatabaseImporter $database;

    public function __construct($jobs)
    {
        parent::__construct($jobs);
        $this->database = new DatabaseImporter();
    }

    public function key(): string
    {
        return 'export_dump_database';
    }

    public function run(array $job): array
    {
        $jobId = $this->jobId($job);
        $payload = $this->payload($job);

        $dbDir = Paths::jobWorkspace($jobId) . '/db';
        Filesystem::ensureDirectory($dbDir);

        $dumpPath = $dbDir . '/dump.sql';
        $state = is_array($payload['db_dump_state'] ?? null) ? $payload['db_dump_state'] : [];

        $result = $this->database->dumpIncremental($dumpPath, $state, 5);
        $payload['db_dump_state'] = $result['state'];
        $payload['db_dump_file'] = $dumpPath;
        if (isset($result['metrics']) && is_array($result['metrics'])) {
            $payload['db_dump_metrics'] = $result['metrics'];
        }

        if (! $result['completed']) {
            return [
                'status' => 'continue',
                'payload' => $payload,
                'progress' => 5 + ($result['progress'] * 0.45),
                'message' => 'Dumping database...',
                'metrics' => $result['metrics'] ?? [],
            ];
        }

        unset($payload['db_dump_state']);

        return [
            'status' => 'next',
            'next_step' => 'export_build_manifest',
            'payload' => $payload,
            'progress' => 50,
            'message' => 'Database dump completed.',
        ];
    }
}
