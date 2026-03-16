<?php

declare(strict_types=1);

namespace LiftTeleport\Cli;

use LiftTeleport\Archive\LiftPackage;
use LiftTeleport\Import\ReadOnlyMode;
use LiftTeleport\Jobs\JobRepository;
use LiftTeleport\Jobs\JobRunner;
use LiftTeleport\Jobs\Steps\Factory;
use LiftTeleport\Settings\SettingsRepository;
use LiftTeleport\Support\ArtifactGarbageCollector;
use LiftTeleport\Support\CapabilityPreflight;
use LiftTeleport\Support\Environment;
use LiftTeleport\Support\Filesystem;
use LiftTeleport\Support\Paths;

final class Commands
{
    private JobRepository $jobs;

    private JobRunner $runner;

    public function __construct(JobRepository $jobs, JobRunner $runner)
    {
        $this->jobs = $jobs;
        $this->runner = $runner;
    }

    public static function register(JobRepository $jobs, JobRunner $runner): void
    {
        if (! class_exists('WP_CLI')) {
            return;
        }

        $instance = new self($jobs, $runner);

        \WP_CLI::add_command('lift export', [$instance, 'export']);
        \WP_CLI::add_command('lift import', [$instance, 'import']);
        \WP_CLI::add_command('lift jobs list', [$instance, 'jobsList']);
        \WP_CLI::add_command('lift jobs run', [$instance, 'jobsRun']);
        \WP_CLI::add_command('lift jobs cancel', [$instance, 'jobsCancel']);
        \WP_CLI::add_command('lift diagnostics', [$instance, 'diagnostics']);
        \WP_CLI::add_command('lift verify', [$instance, 'verify']);
    }

    /**
     * ## OPTIONS
     * [--output=<path>]
     * : Optional output path to copy the generated .lift file.
     *
     * [--password=<password>]
     * : Encrypt package with password.
     *
     * [--format=<format>]
     * : Output format: text|json.
     */
    public function export(array $args, array $assocArgs): void
    {
        $this->guardRuntimeDriftOrFail();

        if ($this->jobs->hasActiveJob()) {
            \WP_CLI::error('Another job is currently active.');
        }

        $payload = [
            'password' => (string) ($assocArgs['password'] ?? ''),
            'requested_by' => $this->currentCliUserId(),
            'settings' => (new SettingsRepository())->forJobPayload(),
        ];
        $payload = $this->attachCapabilityPreflight($payload, 'export');
        $payload = $this->attachRuntimeFingerprint($payload, 'cli_export');

        $job = $this->jobs->create('export', $payload, Factory::initialStepForType('export'));
        Paths::ensureJobDirs((int) $job['id']);
        $this->recordCapabilityPreflightEvent((int) $job['id'], $payload);

        $job = $this->waitForCompletion((int) $job['id']);

        if (($job['status'] ?? '') !== JobRepository::STATUS_COMPLETED) {
            \WP_CLI::error((string) ($job['message'] ?? 'Export failed.'));
        }

        $result = is_array($job['result'] ?? null) ? $job['result'] : [];
        $file = (string) ($result['file'] ?? '');

        if ($file === '' || ! file_exists($file)) {
            \WP_CLI::error('Export completed but output file was not found.');
        }

        if (! empty($assocArgs['output'])) {
            Filesystem::copyFile($file, (string) $assocArgs['output']);
            $this->respond(
                ['status' => 'completed', 'file' => (string) $assocArgs['output'], 'job_id' => (int) ($job['id'] ?? 0)],
                (string) ($assocArgs['format'] ?? 'text'),
                sprintf('Export saved to %s', (string) $assocArgs['output'])
            );
            return;
        }

        $this->respond(
            ['status' => 'completed', 'file' => $file, 'job_id' => (int) ($job['id'] ?? 0)],
            (string) ($assocArgs['format'] ?? 'text'),
            sprintf('Export completed: %s', $file)
        );
    }

    /**
     * ## OPTIONS
     * <file>
     * : Path to .lift file.
     *
     * [--password=<password>]
     * : Password for encrypted archives.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * [--format=<format>]
     * : Output format: text|json.
     */
    public function import(array $args, array $assocArgs): void
    {
        $this->guardRuntimeDriftOrFail();

        if ($this->jobs->hasActiveJob()) {
            \WP_CLI::error('Another job is currently active.');
        }

        $file = (string) ($args[0] ?? '');
        if ($file === '' || ! file_exists($file)) {
            \WP_CLI::error('Import file not found.');
        }

        if (empty($assocArgs['yes'])) {
            \WP_CLI::confirm('This will overwrite the current site. Continue?');
        }

        $payload = [
            'password' => (string) ($assocArgs['password'] ?? ''),
            'source_file' => $file,
            'import_lift_file' => $file,
            'import_lift_size' => filesize($file),
            'requested_by' => $this->currentCliUserId(),
            'settings' => (new SettingsRepository())->forJobPayload(),
            'operator_session_continuity' => false,
            'operator_session_restored' => false,
            'operator_session_restore_error' => null,
        ];
        $payload = $this->attachCapabilityPreflight($payload, 'import');
        $payload = $this->attachRuntimeFingerprint($payload, 'cli_import');

        $job = $this->jobs->create('import', $payload, Factory::initialStepForType('import'), JobRepository::STATUS_PENDING);
        Paths::ensureJobDirs((int) $job['id']);
        $this->recordCapabilityPreflightEvent((int) $job['id'], $payload);

        $job = $this->waitForCompletion((int) $job['id']);

        if (($job['status'] ?? '') !== JobRepository::STATUS_COMPLETED) {
            \WP_CLI::error((string) ($job['message'] ?? 'Import failed.'));
        }

        $this->respond(
            ['status' => 'completed', 'job_id' => (int) ($job['id'] ?? 0), 'site_url' => site_url()],
            (string) ($assocArgs['format'] ?? 'text'),
            'Import completed successfully.'
        );
    }

    /**
     * ## OPTIONS
     * [--limit=<n>]
     * : Number of jobs to list.
     *
     * [--format=<format>]
     * : Output format: table|json.
     */
    public function jobsList(array $args, array $assocArgs): void
    {
        $rows = $this->jobs->recent((int) ($assocArgs['limit'] ?? 20));
        $rows = array_map(static function (array $job): array {
            return [
                'id' => (int) ($job['id'] ?? 0),
                'type' => (string) ($job['type'] ?? ''),
                'status' => (string) ($job['status'] ?? ''),
                'step' => (string) ($job['current_step'] ?? ''),
                'progress' => (float) ($job['progress'] ?? 0),
                'updated_at' => (string) ($job['updated_at'] ?? ''),
            ];
        }, $rows);

        $format = (string) ($assocArgs['format'] ?? 'table');
        if ($format === 'json') {
            \WP_CLI::log((string) wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        \WP_CLI\Utils\format_items('table', $rows, ['id', 'type', 'status', 'step', 'progress', 'updated_at']);
    }

    /**
     * ## OPTIONS
     * <job-id>
     * : Job ID to execute.
     *
     * [--until-terminal]
     * : Continue running ticks until job reaches terminal status.
     *
     * [--timeout=<seconds>]
     * : Max wait time for --until-terminal mode. Default 600.
     *
     * [--format=<format>]
     * : Output format: text|json.
     */
    public function jobsRun(array $args, array $assocArgs): void
    {
        $id = (int) ($args[0] ?? 0);
        if ($id <= 0) {
            \WP_CLI::error('Job ID is required.');
        }

        $untilTerminal = ! empty($assocArgs['until-terminal']);
        $timeout = max(1, (int) ($assocArgs['timeout'] ?? 600));
        $format = (string) ($assocArgs['format'] ?? 'text');

        if ($untilTerminal) {
            $deadline = time() + $timeout;
            do {
                $job = $this->runner->run($id, 15);
                if (! $job) {
                    \WP_CLI::error('Job not found.');
                }

                $status = (string) ($job['status'] ?? '');
                if (in_array($status, [JobRepository::STATUS_COMPLETED, JobRepository::STATUS_FAILED, JobRepository::STATUS_FAILED_ROLLBACK, JobRepository::STATUS_CANCELLED], true)) {
                    $this->respond($job, $format, sprintf('Job %d finished with status: %s', $id, $status));
                    return;
                }

                usleep(400000);
            } while (time() < $deadline);

            \WP_CLI::error(sprintf('Timed out waiting for job %d to reach terminal status.', $id));
        }

        $job = $this->runner->run($id, 15);
        if (! $job) {
            \WP_CLI::error('Job not found.');
        }

        $this->respond($job, $format, sprintf('Job %d executed. Status: %s', $id, (string) $job['status']));
    }

    /**
     * ## OPTIONS
     * <job-id>
     * : Job ID to cancel.
     */
    public function jobsCancel(array $args): void
    {
        $id = (int) ($args[0] ?? 0);
        if ($id <= 0) {
            \WP_CLI::error('Job ID is required.');
        }

        $job = $this->jobs->get($id);
        if (! $job) {
            \WP_CLI::error('Job not found.');
        }

        if (($job['type'] ?? '') === 'import') {
            (new ReadOnlyMode())->disable($id);

            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            if (! empty($payload['maintenance_enabled'])) {
                @unlink(ABSPATH . '.maintenance');
            }
        }

        $status = (string) ($job['status'] ?? '');
        if (in_array($status, [JobRepository::STATUS_RUNNING, JobRepository::STATUS_PENDING], true)) {
            $this->jobs->requestCancel($id);
        } else {
            $this->jobs->cancel($id);

            $cancelled = $this->jobs->get($id);
            if (is_array($cancelled)) {
                $cleanup = (new ArtifactGarbageCollector($this->jobs))->cleanupTerminal($cancelled, JobRepository::STATUS_CANCELLED);
                if ((int) ($cleanup['bytes_reclaimed'] ?? 0) > 0) {
                    $payload = is_array($cancelled['payload'] ?? null) ? $cancelled['payload'] : [];
                    $payload = ArtifactGarbageCollector::mergeCleanupPayload($payload, $cleanup, 'cli_cancel');
                    $this->jobs->update($id, ['payload' => $payload]);
                    $this->jobs->addEvent($id, 'info', 'artifact_cleanup_completed', [
                        'reason' => 'cli_cancel',
                        'terminal_status' => JobRepository::STATUS_CANCELLED,
                        'bytes_reclaimed' => (int) ($cleanup['bytes_reclaimed'] ?? 0),
                        'deleted_paths' => $cleanup['deleted_paths'] ?? [],
                    ]);
                }
            }
        }

        \WP_CLI::success(sprintf('Job %d cancelled.', $id));
    }

    /**
     * ## OPTIONS
     * [--format=<format>]
     * : Output format: table|json.
     *
     * [--refresh-capabilities]
     * : Force refresh capability preflight snapshot.
     */
    public function diagnostics(array $args, array $assocArgs): void
    {
        $refreshCapabilities = ! empty($assocArgs['refresh-capabilities']);
        $data = Environment::diagnostics($this->jobs, $refreshCapabilities);
        $data['active_job'] = $this->jobs->hasActiveJob();

        $format = (string) ($assocArgs['format'] ?? 'table');
        if ($format === 'json') {
            \WP_CLI::log((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        $rows = [];
        foreach ($data as $key => $value) {
            $rows[] = ['key' => $key, 'value' => is_scalar($value) ? (string) $value : (string) wp_json_encode($value)];
        }

        \WP_CLI\Utils\format_items('table', $rows, ['key', 'value']);
    }

    /**
     * ## OPTIONS
     * <file>
     * : Path to .lift file.
     *
     * [--password=<password>]
     * : Password for encrypted archives.
     *
     * [--format=<format>]
     * : Output format: text|json.
     */
    public function verify(array $args, array $assocArgs): void
    {
        $file = (string) ($args[0] ?? '');
        if ($file === '' || ! file_exists($file) || ! is_readable($file)) {
            \WP_CLI::error('Verify file not found or unreadable.');
        }

        $password = (string) ($assocArgs['password'] ?? '');
        $format = (string) ($assocArgs['format'] ?? 'text');

        $verifyJobId = (int) (time() . random_int(100, 999));
        Paths::ensureJobDirs($verifyJobId);

        try {
            $result = (new LiftPackage())->extractImportPackage(
                $verifyJobId,
                $file,
                $password !== '' ? $password : null
            );

            $manifest = is_array($result['manifest'] ?? null) ? $result['manifest'] : [];
            $payload = [
                'verified' => true,
                'file' => $file,
                'compression' => (string) ($result['compression'] ?? ''),
                'encrypted' => (bool) ($result['encrypted'] ?? false),
                'checksum_verified' => (bool) ($result['checksum_verified'] ?? false),
                'format' => (string) ($manifest['format'] ?? ''),
                'format_revision' => (string) ($manifest['format_revision'] ?? ''),
                'db_dump' => (string) ($result['db_dump_relative'] ?? ''),
            ];

            $this->respond(
                $payload,
                $format,
                sprintf(
                    'Verify completed. format=%s revision=%s compression=%s checksum_verified=%s',
                    (string) $payload['format'],
                    (string) $payload['format_revision'],
                    (string) $payload['compression'],
                    (bool) $payload['checksum_verified'] ? 'true' : 'false'
                )
            );
        } catch (\Throwable $error) {
            if ($format === 'json') {
                \WP_CLI::log((string) wp_json_encode([
                    'verified' => false,
                    'file' => $file,
                    'error' => $error->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                \WP_CLI::halt(1);
            }

            \WP_CLI::error('Verify failed: ' . $error->getMessage());
        } finally {
            Filesystem::deletePath(Paths::jobRoot($verifyJobId));
        }
    }

    private function waitForCompletion(int $jobId): array
    {
        $tries = 0;
        while ($tries < 3000) {
            $tries++;
            $job = $this->runner->run($jobId, 10);
            if (! $job) {
                \WP_CLI::error('Job disappeared unexpectedly.');
            }

            $status = (string) ($job['status'] ?? '');
            if (in_array($status, [JobRepository::STATUS_COMPLETED, JobRepository::STATUS_FAILED, JobRepository::STATUS_FAILED_ROLLBACK, JobRepository::STATUS_CANCELLED], true)) {
                return $job;
            }

            usleep(250000);
        }

        \WP_CLI::error('Timed out waiting for job completion.');
    }

    private function currentCliUserId(): int
    {
        if (function_exists('get_current_user_id')) {
            return max(0, (int) get_current_user_id());
        }

        return 0;
    }

    private function guardRuntimeDriftOrFail(): void
    {
        $fingerprint = Environment::runtimeFingerprint();
        $matches = (bool) ($fingerprint['runtime_matches_canonical'] ?? true);
        if ($matches) {
            return;
        }

        $enforce = (bool) apply_filters('lift_teleport_enforce_runtime_match', false, $fingerprint);
        if (! $enforce) {
            return;
        }

        \WP_CLI::error(
            'Runtime plugin path does not match canonical build path. ' .
            'Deploy matching build or disable lift_teleport_enforce_runtime_match.'
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function attachCapabilityPreflight(array $payload, string $jobType): array
    {
        $snapshot = (new CapabilityPreflight())->forJobPayload($jobType);
        $payload['capability_preflight'] = $snapshot;
        $payload['execution_plan'] = array_merge(
            is_array($snapshot['decisions'] ?? null) ? $snapshot['decisions'] : [],
            [
                'job_type' => $jobType,
                'selected_at' => gmdate(DATE_ATOM),
            ]
        );

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function recordCapabilityPreflightEvent(int $jobId, array $payload): void
    {
        $preflight = is_array($payload['capability_preflight'] ?? null)
            ? $payload['capability_preflight']
            : [];
        if ($preflight === []) {
            return;
        }

        $tests = is_array($preflight['tests'] ?? null) ? $preflight['tests'] : [];
        $this->jobs->addEvent($jobId, 'info', 'capability_preflight_completed', [
            'checked_at' => (string) ($preflight['checked_at'] ?? ''),
            'source' => (string) ($preflight['source'] ?? ''),
            'host_profile' => (string) ($preflight['host_profile'] ?? ''),
            'runner' => (string) ($preflight['decisions']['runner'] ?? ''),
            'archive_engine' => (string) ($preflight['decisions']['archive_engine'] ?? ''),
            'tar_gzip_status' => (string) ($tests['tar_gzip']['status'] ?? ''),
            'proc_open_status' => (string) ($tests['proc_open']['status'] ?? ''),
            'cli_status' => (string) ($tests['cli']['status'] ?? ''),
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function attachRuntimeFingerprint(array $payload, string $source): array
    {
        $payload['runtime_fingerprint'] = Environment::runtimeFingerprint();
        $payload['runtime_fingerprint_source'] = $source;
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function respond(array $payload, string $format, string $text): void
    {
        if ($format === 'json') {
            \WP_CLI::log((string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        \WP_CLI::success($text);
    }
}
