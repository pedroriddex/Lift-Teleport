<?php

declare(strict_types=1);

namespace LiftTeleport\Support;

use RuntimeException;

final class CommandRunner
{
    private const COMMAND_TAIL_BYTES = 16000;

    public static function available(): bool
    {
        $disabled = ini_get('disable_functions');
        $disabledList = array_map('trim', explode(',', (string) $disabled));

        return ! in_array('exec', $disabledList, true)
            && function_exists('exec');
    }

    public static function canUseProcOpen(): bool
    {
        $disabled = ini_get('disable_functions');
        $disabledList = array_map('trim', explode(',', (string) $disabled));

        return ! in_array('proc_open', $disabledList, true)
            && function_exists('proc_open');
    }

    public static function commandExists(string $binary): bool
    {
        $binary = trim($binary);
        if ($binary === '') {
            return false;
        }

        if (self::available()) {
            $quoted = escapeshellarg($binary);
            $output = [];
            $exit = 1;
            @exec("command -v {$quoted} 2>/dev/null", $output, $exit);

            return $exit === 0 && ! empty($output);
        }

        if (! self::canUseProcOpen()) {
            return false;
        }

        $checkCommand = sprintf(
            'command -v %s 1>/dev/null 2>/dev/null',
            escapeshellarg($binary)
        );
        $result = self::runWithProcOpen($checkCommand);
        return (int) ($result['exit_code'] ?? 1) === 0;
    }

    public static function run(string $command): void
    {
        if (self::available()) {
            $output = [];
            $exit = 1;
            @exec($command . ' 2>&1', $output, $exit);

            if ($exit !== 0) {
                throw new RuntimeException(sprintf('Command failed (%d): %s | %s', $exit, $command, implode("\n", $output)));
            }
            return;
        }

        if (! self::canUseProcOpen()) {
            throw new RuntimeException('Shell command execution is disabled by PHP configuration.');
        }

        $result = self::runWithProcOpen($command);
        if ((int) ($result['exit_code'] ?? 1) !== 0) {
            throw new RuntimeException(sprintf(
                'Command failed (%d): %s | %s',
                (int) ($result['exit_code'] ?? 1),
                $command,
                trim((string) ($result['stderr_tail'] ?? ''))
            ));
        }
    }

    /**
     * @param array{
     *   progress_callback?:callable,
     *   output_path?:string,
     *   tick_seconds?:float,
     *   stall_timeout_seconds?:int,
     *   hard_timeout_seconds?:int
     * } $options
     * @return array{exit_code:int,duration_seconds:float,stdout_tail:string,stderr_tail:string,output_bytes:int}
     */
    public static function runMonitored(string $command, array $options = []): array
    {
        if (! self::canUseProcOpen()) {
            self::run($command);
            $outputPath = isset($options['output_path']) && is_string($options['output_path']) ? $options['output_path'] : '';
            $outputBytes = $outputPath !== '' && file_exists($outputPath) ? (int) filesize($outputPath) : 0;
            return [
                'exit_code' => 0,
                'duration_seconds' => 0.0,
                'stdout_tail' => '',
                'stderr_tail' => '',
                'output_bytes' => $outputBytes,
            ];
        }

        $progressCallback = isset($options['progress_callback']) && is_callable($options['progress_callback'])
            ? $options['progress_callback']
            : null;
        $outputPath = isset($options['output_path']) && is_string($options['output_path']) ? $options['output_path'] : '';
        $tickSeconds = isset($options['tick_seconds']) ? (float) $options['tick_seconds'] : 1.0;
        $tickSeconds = max(0.2, min(5.0, $tickSeconds));
        $stallTimeout = isset($options['stall_timeout_seconds']) ? (int) $options['stall_timeout_seconds'] : 90;
        $stallTimeout = max(15, $stallTimeout);
        $hardTimeout = isset($options['hard_timeout_seconds']) ? (int) $options['hard_timeout_seconds'] : 3600;
        $hardTimeout = max($stallTimeout, $hardTimeout);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException(sprintf('Unable to start command: %s', $command));
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

        $startedAt = microtime(true);
        $lastActivityAt = $startedAt;
        $lastOutputBytes = $outputPath !== '' && file_exists($outputPath) ? (int) filesize($outputPath) : 0;
        $stdoutTail = '';
        $stderrTail = '';
        $stalled = false;
        $timedOut = false;

        $readPipe = static function ($pipe, string &$tail): bool {
            if (! is_resource($pipe)) {
                return false;
            }

            $chunk = stream_get_contents($pipe);
            if (! is_string($chunk) || $chunk === '') {
                return false;
            }

            $tail .= $chunk;
            if (strlen($tail) > self::COMMAND_TAIL_BYTES) {
                $tail = substr($tail, -self::COMMAND_TAIL_BYTES);
            }

            return true;
        };

        while (true) {
            $now = microtime(true);
            $elapsed = $now - $startedAt;

            $hadOutput = false;
            if (isset($pipes[1]) && $readPipe($pipes[1], $stdoutTail)) {
                $hadOutput = true;
            }
            if (isset($pipes[2]) && $readPipe($pipes[2], $stderrTail)) {
                $hadOutput = true;
            }

            $outputBytes = $outputPath !== '' && file_exists($outputPath) ? (int) filesize($outputPath) : 0;
            if ($outputBytes > $lastOutputBytes) {
                $lastOutputBytes = $outputBytes;
                $hadOutput = true;
            }

            if ($hadOutput) {
                $lastActivityAt = $now;
            }

            if ($progressCallback !== null) {
                $progressCallback([
                    'elapsed_seconds' => round($elapsed, 3),
                    'output_bytes' => $lastOutputBytes,
                    'stdout_tail' => $stdoutTail,
                    'stderr_tail' => $stderrTail,
                ]);
            }

            $status = proc_get_status($process);
            $running = is_array($status) ? (bool) ($status['running'] ?? false) : false;
            if (! $running) {
                break;
            }

            if ((int) floor($elapsed) >= $hardTimeout) {
                $timedOut = true;
                break;
            }

            if (($now - $lastActivityAt) >= $stallTimeout) {
                $stalled = true;
                break;
            }

            usleep((int) ($tickSeconds * 1_000_000));
        }

        if ($stalled || $timedOut) {
            @proc_terminate($process);
            usleep(200000);
            $status = proc_get_status($process);
            if (is_array($status) && ! empty($status['running'])) {
                @proc_terminate($process, 9);
            }
        }

        if (isset($pipes[1])) {
            $readPipe($pipes[1], $stdoutTail);
            fclose($pipes[1]);
        }
        if (isset($pipes[2])) {
            $readPipe($pipes[2], $stderrTail);
            fclose($pipes[2]);
        }

        $exitCode = proc_close($process);
        $duration = microtime(true) - $startedAt;
        $outputBytes = $outputPath !== '' && file_exists($outputPath) ? (int) filesize($outputPath) : $lastOutputBytes;

        if ($timedOut) {
            throw new RuntimeException(sprintf(
                'Command timed out after %ds: %s | stderr: %s',
                $hardTimeout,
                $command,
                trim($stderrTail)
            ));
        }

        if ($stalled) {
            throw new RuntimeException(sprintf(
                'Command stalled after %ds without output growth: %s | stderr: %s',
                $stallTimeout,
                $command,
                trim($stderrTail)
            ));
        }

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf(
                'Command failed (%d): %s | stderr: %s',
                $exitCode,
                $command,
                trim($stderrTail)
            ));
        }

        return [
            'exit_code' => $exitCode,
            'duration_seconds' => round($duration, 3),
            'stdout_tail' => $stdoutTail,
            'stderr_tail' => $stderrTail,
            'output_bytes' => $outputBytes,
        ];
    }

    /**
     * @return array{exit_code:int,stdout_tail:string,stderr_tail:string}
     */
    private static function runWithProcOpen(string $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException(sprintf('Unable to start command: %s', $command));
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $stdoutTail = '';
        $stderrTail = '';

        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $stdoutTail = stream_get_contents($pipes[1]) ?: '';
            fclose($pipes[1]);
        }

        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderrTail = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[2]);
        }

        if (strlen($stdoutTail) > self::COMMAND_TAIL_BYTES) {
            $stdoutTail = substr($stdoutTail, -self::COMMAND_TAIL_BYTES);
        }
        if (strlen($stderrTail) > self::COMMAND_TAIL_BYTES) {
            $stderrTail = substr($stderrTail, -self::COMMAND_TAIL_BYTES);
        }

        $exitCode = proc_close($process);
        return [
            'exit_code' => (int) $exitCode,
            'stdout_tail' => $stdoutTail,
            'stderr_tail' => $stderrTail,
        ];
    }
}
