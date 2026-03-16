<?php

declare(strict_types=1);

namespace LiftTeleport\Support;

use Throwable;

final class CapabilityPreflight
{
    private const CACHE_OPTION = 'lift_teleport_capability_preflight_cache';
    private const DEFAULT_CACHE_TTL_SECONDS = 300;

    /**
     * @var array<string,callable>
     */
    private array $probes;

    /**
     * @param array<string,callable> $probes
     */
    public function __construct(array $probes = [])
    {
        $this->probes = $probes;
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(bool $forceRefresh = false): array
    {
        $ttl = max(30, (int) apply_filters('lift_teleport_capability_preflight_ttl_seconds', self::DEFAULT_CACHE_TTL_SECONDS));

        if (! $forceRefresh) {
            $cached = get_option(self::CACHE_OPTION, null);
            if (is_array($cached) && $this->isCacheValid($cached, $ttl)) {
                return $this->normalizeSnapshot($cached, $ttl, 'cache');
            }
        }

        $fresh = $this->runFreshSnapshot($ttl);
        update_option(self::CACHE_OPTION, $fresh, false);

        return $fresh;
    }

    /**
     * @return array<string,mixed>
     */
    public function forJobPayload(string $jobType, bool $forceRefresh = false): array
    {
        $snapshot = $this->snapshot($forceRefresh);
        $snapshot['job_type'] = $jobType;
        return $snapshot;
    }

    /**
     * @return array<int,string>
     */
    public static function cliCandidates(): array
    {
        $candidates = apply_filters('lift_teleport_wp_cli_candidates', [
            'wp',
            '/usr/local/bin/wp',
            '/usr/bin/wp',
        ]);

        if (! is_array($candidates)) {
            $candidates = ['wp', '/usr/local/bin/wp', '/usr/bin/wp'];
        }

        return self::sanitizeCliCandidates($candidates);
    }

    /**
     * @param array<int,mixed> $candidates
     * @return array<int,string>
     */
    public static function sanitizeCliCandidates(array $candidates): array
    {
        $normalized = [];
        foreach ($candidates as $candidate) {
            $safeCandidate = self::normalizeCliCandidate((string) $candidate);
            if ($safeCandidate === '' || in_array($safeCandidate, $normalized, true)) {
                continue;
            }

            $normalized[] = $safeCandidate;
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $cache
     */
    private function isCacheValid(array $cache, int $ttl): bool
    {
        $checkedAt = (string) ($cache['checked_at'] ?? '');
        if ($checkedAt === '') {
            return false;
        }

        $checkedTs = strtotime($checkedAt);
        if (! is_int($checkedTs) || $checkedTs <= 0) {
            return false;
        }

        return (time() - $checkedTs) < $ttl;
    }

    /**
     * @return array<string,mixed>
     */
    private function runFreshSnapshot(int $ttl): array
    {
        $tests = [
            'tar_gzip' => $this->runProbe('tar_gzip', fn () => $this->runTarGzipProbe()),
            'proc_open' => $this->runProbe('proc_open', fn () => $this->runProcOpenProbe()),
            'cli' => $this->runProbe('cli', fn () => $this->runCliProbe()),
        ];

        $decisions = $this->buildDecisions($tests);

        return [
            'checked_at' => gmdate(DATE_ATOM),
            'source' => 'fresh',
            'cache_ttl_seconds' => $ttl,
            'tests' => $tests,
            'decisions' => $decisions,
            'host_profile' => $this->resolveHostProfile($tests),
        ];
    }

    /**
     * @param callable():array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    private function runProbe(string $key, callable $fallback): array
    {
        if (isset($this->probes[$key]) && is_callable($this->probes[$key])) {
            $result = ($this->probes[$key])();
            if (is_array($result)) {
                return $this->normalizeProbeResult($result, 'fail');
            }
        }

        try {
            return $this->normalizeProbeResult($fallback(), 'fail');
        } catch (Throwable $error) {
            return [
                'status' => 'fail',
                'details' => [
                    'message' => $error->getMessage(),
                ],
                'duration_ms' => 0,
            ];
        }
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function normalizeProbeResult(array $result, string $defaultStatus): array
    {
        $status = (string) ($result['status'] ?? $defaultStatus);
        if (! in_array($status, ['pass', 'fail', 'degraded'], true)) {
            $status = $defaultStatus;
        }

        return [
            'status' => $status,
            'details' => is_array($result['details'] ?? null) ? $result['details'] : [],
            'duration_ms' => max(0, (int) ($result['duration_ms'] ?? 0)),
            'command_used' => isset($result['command_used']) ? (string) $result['command_used'] : '',
            'exit_code' => isset($result['exit_code']) ? (int) $result['exit_code'] : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runTarGzipProbe(): array
    {
        $startedAt = microtime(true);
        $tarBinary = CommandRunner::commandExists('tar');
        $gzipBinary = CommandRunner::commandExists('gzip');
        $zstdBinary = CommandRunner::commandExists('zstd');
        $gzipPhpFallback = function_exists('gzopen') && function_exists('gzread');
        $tarPhpFallback = true;

        $details = [
            'tar_binary' => $tarBinary,
            'gzip_binary' => $gzipBinary,
            'zstd_binary' => $zstdBinary,
            'fallback_tar' => $tarPhpFallback,
            'fallback_gzip' => $gzipPhpFallback,
        ];

        $workDir = rtrim(Paths::dataRoot(), '/\\') . '/capability-preflight-' . wp_generate_password(8, false, false);

        try {
            if ($tarBinary && $gzipBinary) {
                Filesystem::ensureDirectory($workDir);
                $probeText = $workDir . '/probe.txt';
                $probeTar = $workDir . '/probe.tar';
                $probeGz = $workDir . '/probe.tar.gz';
                file_put_contents($probeText, 'lift-preflight');

                CommandRunner::run(sprintf(
                    'tar -chf %s -C %s %s',
                    escapeshellarg($probeTar),
                    escapeshellarg($workDir),
                    escapeshellarg('probe.txt')
                ));

                CommandRunner::run(sprintf(
                    'gzip -c %s > %s',
                    escapeshellarg($probeTar),
                    escapeshellarg($probeGz)
                ));

                CommandRunner::run(sprintf('gzip -t %s', escapeshellarg($probeGz)));

                $status = 'pass';
                $details['message'] = 'tar and gzip binaries are available and executable.';
            } elseif ($tarPhpFallback || $gzipPhpFallback) {
                $status = 'degraded';
                $details['message'] = 'System tar/gzip binaries are unavailable; PHP fallback will be used where possible.';
            } else {
                $status = 'fail';
                $details['message'] = 'System tar/gzip binaries are unavailable and no PHP fallback was detected.';
            }
        } catch (Throwable $error) {
            if ($tarPhpFallback || $gzipPhpFallback) {
                $status = 'degraded';
                $details['message'] = 'tar/gzip execution failed; PHP fallback will be used where possible.';
                $details['error'] = $error->getMessage();
            } else {
                $status = 'fail';
                $details['message'] = 'tar/gzip execution failed and no fallback is available.';
                $details['error'] = $error->getMessage();
            }
        } finally {
            if (is_dir($workDir)) {
                Filesystem::deletePath($workDir);
            }
        }

        return [
            'status' => $status,
            'details' => $details,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runProcOpenProbe(): array
    {
        $startedAt = microtime(true);
        if (! CommandRunner::canUseProcOpen()) {
            return [
                'status' => 'fail',
                'details' => [
                    'message' => 'proc_open is disabled by PHP configuration.',
                ],
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        $probe = $this->runCommand(
            sprintf(
                '%s -r %s',
                escapeshellarg((string) PHP_BINARY),
                escapeshellarg('echo "lift-preflight-proc-open";')
            ),
            5
        );
        $status = $probe['exit_code'] === 0 ? 'pass' : 'fail';

        return [
            'status' => $status,
            'details' => [
                'message' => $status === 'pass'
                    ? 'proc_open is available and command execution succeeded.'
                    : 'proc_open is available but command execution failed.',
                'stdout' => trim((string) ($probe['stdout'] ?? '')),
                'stderr' => trim((string) ($probe['stderr'] ?? '')),
            ],
            'duration_ms' => (int) ($probe['duration_ms'] ?? round((microtime(true) - $startedAt) * 1000)),
            'exit_code' => (int) ($probe['exit_code'] ?? 1),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runCliProbe(): array
    {
        $startedAt = microtime(true);

        if (! CommandRunner::available() && ! CommandRunner::canUseProcOpen()) {
            return [
                'status' => 'degraded',
                'details' => [
                    'message' => 'CLI probe skipped because shell command execution is disabled.',
                ],
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'command_used' => '',
                'exit_code' => null,
            ];
        }

        $lastError = '';
        $lastExit = null;
        foreach (self::cliCandidates() as $candidate) {
            $command = sprintf(
                '%s --path=%s --info',
                escapeshellarg($candidate),
                escapeshellarg(rtrim((string) ABSPATH, '/\\'))
            );
            $probe = $this->runCommand($command, 8);
            if ((int) ($probe['exit_code'] ?? 1) === 0) {
                return [
                    'status' => 'pass',
                    'details' => [
                        'message' => 'WP-CLI is executable from PHP context.',
                        'stdout' => trim((string) ($probe['stdout'] ?? '')),
                    ],
                    'duration_ms' => (int) ($probe['duration_ms'] ?? round((microtime(true) - $startedAt) * 1000)),
                    'command_used' => $candidate,
                    'exit_code' => 0,
                ];
            }

            $lastExit = (int) ($probe['exit_code'] ?? 1);
            $lastError = trim((string) ($probe['stderr'] ?? ''));
        }

        return [
            'status' => 'fail',
            'details' => [
                'message' => 'Unable to execute WP-CLI from PHP context.',
                'stderr' => $lastError,
            ],
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'command_used' => '',
            'exit_code' => $lastExit,
        ];
    }

    private static function normalizeCliCandidate(string $candidate): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return '';
        }

        if (preg_match('/[\\x00-\\x1F\\x7F]/', $candidate) === 1) {
            return '';
        }

        if (preg_match('/[;&|`$<>\\n\\r]/', $candidate) === 1) {
            return '';
        }

        if (str_contains($candidate, ' ')) {
            return '';
        }

        if (str_starts_with($candidate, '/')) {
            if (preg_match('#^/[A-Za-z0-9._/-]+$#', $candidate) !== 1) {
                return '';
            }

            if (str_contains($candidate, '/../') || str_ends_with($candidate, '/..')) {
                return '';
            }

            return $candidate;
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', $candidate) !== 1) {
            return '';
        }

        return $candidate;
    }

    /**
     * @param array<string,mixed> $tests
     * @return array<string,mixed>
     */
    private function buildDecisions(array $tests): array
    {
        $procStatus = (string) ($tests['proc_open']['status'] ?? 'fail');
        $cliStatus = (string) ($tests['cli']['status'] ?? 'fail');
        $tarStatus = (string) ($tests['tar_gzip']['status'] ?? 'fail');

        $runner = ($procStatus === 'pass' && $cliStatus === 'pass')
            ? 'cli_worker'
            : 'web_runner';

        $archiveEngine = $tarStatus === 'pass'
            ? 'shell'
            : 'php_stream';

        $tarDetails = is_array($tests['tar_gzip']['details'] ?? null)
            ? $tests['tar_gzip']['details']
            : [];
        $zstdBinary = (bool) ($tarDetails['zstd_binary'] ?? false);
        $gzipBinary = (bool) ($tarDetails['gzip_binary'] ?? false);

        $compressionChain = [];
        if ($zstdBinary) {
            $compressionChain[] = 'zstd';
        }
        if ($gzipBinary) {
            $compressionChain[] = 'gzip';
        }
        $compressionChain[] = 'none';

        $warnings = [];
        foreach (['tar_gzip', 'proc_open', 'cli'] as $testKey) {
            $status = (string) ($tests[$testKey]['status'] ?? 'fail');
            if ($status === 'pass') {
                continue;
            }

            $message = (string) ($tests[$testKey]['details']['message'] ?? '');
            if ($message === '') {
                $message = sprintf('Capability test %s returned %s.', $testKey, $status);
            }

            $warnings[] = $message;
        }

        $riskLevel = $warnings === [] ? 'normal' : 'degraded';
        $blockingPolicy = (string) apply_filters('lift_teleport_capability_blocking_policy', 'auto_fallback');
        if (! in_array($blockingPolicy, ['auto_fallback', 'strict', 'manual'], true)) {
            $blockingPolicy = 'auto_fallback';
        }

        return [
            'runner' => $runner,
            'archive_engine' => $archiveEngine,
            'compression_chain' => array_values(array_unique($compressionChain)),
            'risk_level' => $riskLevel,
            'warnings' => $warnings,
            'blocking_policy' => $blockingPolicy,
        ];
    }

    /**
     * @param array<string,mixed> $tests
     */
    private function resolveHostProfile(array $tests): string
    {
        $procStatus = (string) ($tests['proc_open']['status'] ?? 'fail');
        $cliStatus = (string) ($tests['cli']['status'] ?? 'fail');
        $tarStatus = (string) ($tests['tar_gzip']['status'] ?? 'fail');

        if ($procStatus === 'pass' && $cliStatus === 'pass' && $tarStatus === 'pass') {
            return 'full';
        }

        if ($procStatus === 'pass') {
            return 'limited';
        }

        return 'restricted';
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function normalizeSnapshot(array $snapshot, int $ttl, string $source): array
    {
        $tests = is_array($snapshot['tests'] ?? null) ? $snapshot['tests'] : [];
        $normalizedTests = [
            'tar_gzip' => $this->normalizeProbeResult(is_array($tests['tar_gzip'] ?? null) ? $tests['tar_gzip'] : [], 'fail'),
            'proc_open' => $this->normalizeProbeResult(is_array($tests['proc_open'] ?? null) ? $tests['proc_open'] : [], 'fail'),
            'cli' => $this->normalizeProbeResult(is_array($tests['cli'] ?? null) ? $tests['cli'] : [], 'fail'),
        ];

        $checkedAt = (string) ($snapshot['checked_at'] ?? '');
        if ($checkedAt === '') {
            $checkedAt = gmdate(DATE_ATOM);
        }

        $decisions = is_array($snapshot['decisions'] ?? null)
            ? $snapshot['decisions']
            : $this->buildDecisions($normalizedTests);

        return [
            'checked_at' => $checkedAt,
            'source' => $source,
            'cache_ttl_seconds' => $ttl,
            'tests' => $normalizedTests,
            'decisions' => $decisions,
            'host_profile' => (string) ($snapshot['host_profile'] ?? $this->resolveHostProfile($normalizedTests)),
        ];
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string,duration_ms:int}
     */
    private function runCommand(string $command, int $timeoutSeconds): array
    {
        $timeoutSeconds = max(1, $timeoutSeconds);
        $startedAt = microtime(true);

        if (CommandRunner::canUseProcOpen()) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = @proc_open($command, $descriptors, $pipes);
            if (! is_resource($process)) {
                return [
                    'exit_code' => 1,
                    'stdout' => '',
                    'stderr' => 'Unable to start process.',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ];
            }

            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                stream_set_blocking($pipes[1], false);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                stream_set_blocking($pipes[2], false);
            }

            $stdout = '';
            $stderr = '';
            $timedOut = false;

            while (true) {
                if (isset($pipes[1]) && is_resource($pipes[1])) {
                    $chunk = stream_get_contents($pipes[1]);
                    if (is_string($chunk) && $chunk !== '') {
                        $stdout .= $chunk;
                    }
                }
                if (isset($pipes[2]) && is_resource($pipes[2])) {
                    $chunk = stream_get_contents($pipes[2]);
                    if (is_string($chunk) && $chunk !== '') {
                        $stderr .= $chunk;
                    }
                }

                $status = proc_get_status($process);
                $running = is_array($status) ? (bool) ($status['running'] ?? false) : false;
                if (! $running) {
                    break;
                }

                if ((microtime(true) - $startedAt) > $timeoutSeconds) {
                    $timedOut = true;
                    @proc_terminate($process);
                    break;
                }

                usleep(100000);
            }

            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $chunk = stream_get_contents($pipes[1]);
                if (is_string($chunk) && $chunk !== '') {
                    $stdout .= $chunk;
                }
                fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $chunk = stream_get_contents($pipes[2]);
                if (is_string($chunk) && $chunk !== '') {
                    $stderr .= $chunk;
                }
                fclose($pipes[2]);
            }

            $exitCode = proc_close($process);
            if ($timedOut) {
                $exitCode = 124;
            }

            return [
                'exit_code' => (int) $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        if (CommandRunner::available()) {
            $output = [];
            $exit = 1;
            @exec($command . ' 2>&1', $output, $exit);
            return [
                'exit_code' => (int) $exit,
                'stdout' => implode("\n", $output),
                'stderr' => '',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        return [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'Shell command execution is disabled.',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}
