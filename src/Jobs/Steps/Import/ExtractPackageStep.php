<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Import;

use LiftTeleport\Archive\LiftPackage;
use LiftTeleport\Jobs\Steps\AbstractStep;
use LiftTeleport\Jobs\Steps\StepFailure;
use LiftTeleport\Support\ArtifactGarbageCollector;

final class ExtractPackageStep extends AbstractStep
{
    private LiftPackage $package;

    public function __construct($jobs)
    {
        parent::__construct($jobs);
        $this->package = new LiftPackage();
    }

    public function key(): string
    {
        return 'import_extract_package';
    }

    public function run(array $job): array
    {
        $payload = $this->payload($job);
        $liftFile = (string) ($payload['import_lift_file'] ?? '');
        if ($liftFile === '' || ! file_exists($liftFile)) {
            throw StepFailure::fatal(
                'lift_import_file_missing',
                'Lift file is missing at extraction step.',
                'Upload the package again and retry.'
            );
        }

        $password = isset($payload['password']) ? (string) $payload['password'] : null;
        try {
            $result = $this->package->extractImportPackage($this->jobId($job), $liftFile, $password);
        } catch (\RuntimeException $error) {
            throw StepFailure::fatal(
                'lift_import_extract_failed',
                $error->getMessage() !== '' ? $error->getMessage() : 'Import package could not be extracted.',
                'Re-upload or re-download the .lift file and retry.',
                [],
                $error
            );
        }

        $payload['import_manifest'] = $result['manifest'];
        $payload['import_extracted_root'] = $result['extracted_root'];
        $payload['import_source_db_prefix'] = (string) ($result['manifest']['site']['db_prefix'] ?? '');
        $payload['checksum_verified'] = (bool) ($result['checksum_verified'] ?? false);
        $payload['import_db_dump_relative'] = (string) ($result['db_dump_relative'] ?? 'db/dump.sql');
        $payload['import_package_format'] = (string) ($result['manifest']['format'] ?? 'lift-v1');
        $payload['import_package_format_revision'] = (string) ($result['manifest']['format_revision'] ?? '');
        $payload['integrity_stage'] = 'checksums_verified';

        $cleanup = (new ArtifactGarbageCollector($this->jobs))->cleanupAfterStep($job, $this->key());
        if ((int) ($cleanup['bytes_reclaimed'] ?? 0) > 0) {
            $payload = ArtifactGarbageCollector::mergeCleanupPayload($payload, $cleanup, 'import_extract_package');
            $this->jobs->addEvent($this->jobId($job), 'info', 'artifact_cleanup_completed', [
                'reason' => 'import_extract_package',
                'bytes_reclaimed' => (int) ($cleanup['bytes_reclaimed'] ?? 0),
                'deleted_paths' => $cleanup['deleted_paths'] ?? [],
            ]);
        }

        return [
            'status' => 'next',
            'next_step' => 'import_capture_merge_admin',
            'payload' => $payload,
            'progress' => 40,
            'message' => 'Package extracted and verified.',
            'metrics' => [
                'checksum_verified' => (bool) ($result['checksum_verified'] ?? false),
            ],
        ];
    }
}
