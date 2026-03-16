<?php

declare(strict_types=1);

namespace LiftTeleport\Support;

final class CliWorkerLauncher
{
    /**
     * @var callable|null
     */
    private $executor;

    /**
     * @var array<int,string>|null
     */
    private ?array $candidates;

    /**
     * @param callable|null $executor
     * @param array<int,string>|null $candidates
     */
    public function __construct(?callable $executor = null, ?array $candidates = null)
    {
        $this->executor = $executor;
        $this->candidates = $candidates;
    }

    /**
     * @return array<string,mixed>
     */
    public function launch(int $jobId, string $wpPath, int $timeoutSeconds = 14400): array
    {
        $enabled = (bool) apply_filters('lift_teleport_enable_cli_worker', true, $jobId);
        if (! $enabled) {
            return [
                'started' => false,
                'mode' => 'web_runner',
                'reason' => 'cli_worker_disabled',
                'message' => 'CLI worker launch is disabled by configuration.',
            ];
        }

        if (! CommandRunner::available() && ! CommandRunner::canUseProcOpen()) {
            return [
                'started' => false,
                'mode' => 'web_runner',
                'reason' => 'shell_execution_disabled',
                'message' => 'Shell execution is disabled in PHP runtime.',
            ];
        }

        $timeoutSeconds = max(60, $timeoutSeconds);
        $wpPath = rtrim(trim($wpPath), '/\\');
        if ($wpPath === '') {
            $wpPath = rtrim((string) ABSPATH, '/\\');
        }

        $workspace = Paths::jobWorkspace($jobId);
        Filesystem::ensureDirectory($workspace);
        $logPath = $workspace . '/cli-worker.log';

        $candidates = $this->resolveCandidates();
        if ($candidates === []) {
            return [
                'started' => false,
                'mode' => 'web_runner',
                'reason' => 'cli_worker_no_safe_candidate',
                'message' => 'No safe WP-CLI candidate is configured for CLI worker mode.',
            ];
        }

        $errors = [];
        foreach ($candidates as $candidate) {
            $command = sprintf(
                '%s --path=%s lift jobs run %d --until-terminal --timeout=%d --format=json',
                escapeshellarg($candidate),
                escapeshellarg($wpPath),
                $jobId,
                $timeoutSeconds
            );

            $result = $this->startBackgroundProcess($command, $logPath);
            if (! empty($result['started'])) {
                return [
                    'started' => true,
                    'mode' => 'cli_worker',
                    'reason' => 'cli_worker_started',
                    'command_used' => $candidate,
                    'pid' => (int) ($result['pid'] ?? 0),
                    'log_path' => $logPath,
                    'started_at' => gmdate(DATE_ATOM),
                ];
            }

            $errors[] = [
                'candidate' => $candidate,
                'message' => (string) ($result['message'] ?? 'unknown failure'),
                'stderr' => (string) ($result['stderr'] ?? ''),
            ];
        }

        return [
            'started' => false,
            'mode' => 'web_runner',
            'reason' => 'cli_worker_launch_failed',
            'message' => 'Failed to launch CLI worker with available WP-CLI candidates.',
            'errors' => $errors,
            'log_path' => $logPath,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function resolveCandidates(): array
    {
        if (is_array($this->candidates) && $this->candidates !== []) {
            return CapabilityPreflight::sanitizeCliCandidates($this->candidates);
        }

        return CapabilityPreflight::cliCandidates();
    }

    /**
     * @return array<string,mixed>
     */
    private function startBackgroundProcess(string $command, string $logPath): array
    {
        if (is_callable($this->executor)) {
            $result = ($this->executor)($command, $logPath);
            if (is_array($result)) {
                return $result;
            }

            return [
                'started' => false,
                'message' => 'Invalid CLI worker executor response.',
                'stderr' => '',
            ];
        }

        $background = sprintf(
            '%s >> %s 2>&1 & echo $!',
            $command,
            escapeshellarg($logPath)
        );

        if (CommandRunner::canUseProcOpen()) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = @proc_open($background, $descriptors, $pipes);
            if (! is_resource($process)) {
                return [
                    'started' => false,
                    'message' => 'Unable to start background process via proc_open.',
                    'stderr' => '',
                ];
            }

            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            $stdout = '';
            $stderr = '';
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $stdout = (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $stderr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[2]);
            }

            $exit = (int) proc_close($process);
            $pid = (int) trim($stdout);
            if ($exit === 0 && $pid > 0) {
                return [
                    'started' => true,
                    'pid' => $pid,
                    'message' => 'CLI worker launched via proc_open.',
                    'stderr' => trim($stderr),
                ];
            }

            return [
                'started' => false,
                'pid' => $pid,
                'message' => 'proc_open launcher returned non-zero exit code.',
                'stderr' => trim($stderr),
            ];
        }

        if (! CommandRunner::available()) {
            return [
                'started' => false,
                'message' => 'Shell execution is disabled.',
                'stderr' => '',
            ];
        }

        $output = [];
        $exit = 1;
        @exec($background, $output, $exit);
        $pid = isset($output[0]) ? (int) trim((string) $output[0]) : 0;
        if ($exit === 0 && $pid > 0) {
            return [
                'started' => true,
                'pid' => $pid,
                'message' => 'CLI worker launched via exec.',
                'stderr' => '',
            ];
        }

        return [
            'started' => false,
            'pid' => $pid,
            'message' => 'exec launcher returned non-zero exit code.',
            'stderr' => '',
        ];
    }
}
