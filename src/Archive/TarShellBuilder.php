<?php

declare(strict_types=1);

namespace LiftTeleport\Archive;

use LiftTeleport\Support\CommandRunner;
use LiftTeleport\Support\Filesystem;
use RuntimeException;
use Throwable;

final class TarShellBuilder
{
    /**
     * @param array<int,array{source:string,target:string,size:int}> $files
     */
    public function createTar(array $files, string $tarPath, ?callable $progressCallback = null): void
    {
        if (! CommandRunner::commandExists('tar')) {
            throw new RuntimeException('System tar binary is not available.');
        }

        Filesystem::ensureDirectory(dirname($tarPath));
        @unlink($tarPath);

        $filesTotal = count($files);
        $bytesTotal = 0;
        foreach ($files as $file) {
            $bytesTotal += max(0, (int) ($file['size'] ?? 0));
        }

        $stageRoot = dirname($tarPath) . '/tar-stage-' . bin2hex(random_bytes(8));
        Filesystem::ensureDirectory($stageRoot);

        $filesDone = 0;
        $bytesDone = 0;
        $this->emit($progressCallback, [
            'phase' => 'tar_build',
            'engine' => 'shell',
            'status' => 'preparing_stage',
            'files_done' => 0,
            'files_total' => $filesTotal,
            'bytes_done' => 0,
            'bytes_total' => $bytesTotal,
        ]);

        try {
            foreach ($files as $file) {
                $source = (string) ($file['source'] ?? '');
                $target = ltrim(str_replace('\\', '/', (string) ($file['target'] ?? '')), '/');
                $size = max(0, (int) ($file['size'] ?? 0));

                if ($source === '' || $target === '' || ! is_file($source) || ! is_readable($source)) {
                    throw new RuntimeException(sprintf('Invalid file while staging tar build: %s', $source));
                }

                $stagePath = $stageRoot . '/' . $target;
                Filesystem::ensureDirectory(dirname($stagePath));
                $this->stageFile($source, $stagePath);

                $filesDone++;
                $bytesDone += $size;
                $this->emit($progressCallback, [
                    'phase' => 'tar_build',
                    'engine' => 'shell',
                    'status' => 'preparing_stage',
                    'files_done' => $filesDone,
                    'files_total' => $filesTotal,
                    'bytes_done' => $bytesDone,
                    'bytes_total' => $bytesTotal,
                ]);
            }

            $command = sprintf(
                'tar -chf %s -C %s .',
                escapeshellarg($tarPath),
                escapeshellarg($stageRoot)
            );

            CommandRunner::runMonitored($command, [
                'output_path' => $tarPath,
                'tick_seconds' => 1.0,
                'stall_timeout_seconds' => (int) apply_filters('lift_teleport_tar_shell_stall_timeout_seconds', 180),
                'hard_timeout_seconds' => (int) apply_filters('lift_teleport_tar_shell_hard_timeout_seconds', 7200),
                'progress_callback' => function (array $state) use ($progressCallback, $filesTotal, $bytesTotal): void {
                    $outputBytes = max(0, (int) ($state['output_bytes'] ?? 0));
                    $elapsed = max(0.0, (float) ($state['elapsed_seconds'] ?? 0.0));

                    $this->emit($progressCallback, [
                        'phase' => 'tar_build',
                        'engine' => 'shell',
                        'status' => 'running',
                        'files_done' => $filesTotal,
                        'files_total' => $filesTotal,
                        'bytes_done' => $bytesTotal > 0 ? min($bytesTotal, $outputBytes) : $outputBytes,
                        'bytes_total' => $bytesTotal,
                        'elapsed_seconds' => $elapsed,
                        'output_bytes' => $outputBytes,
                    ]);
                },
            ]);

            if (! file_exists($tarPath) || (int) filesize($tarPath) <= 0) {
                throw new RuntimeException('System tar produced an empty archive.');
            }

            $this->emit($progressCallback, [
                'phase' => 'tar_build',
                'engine' => 'shell',
                'status' => 'completed',
                'files_done' => $filesTotal,
                'files_total' => $filesTotal,
                'bytes_done' => $bytesTotal,
                'bytes_total' => $bytesTotal,
                'output_bytes' => (int) filesize($tarPath),
            ]);
        } catch (Throwable $error) {
            @unlink($tarPath);
            throw $error;
        } finally {
            Filesystem::deletePath($stageRoot);
        }
    }

    private function stageFile(string $source, string $stagePath): void
    {
        if (@link($source, $stagePath)) {
            return;
        }

        if (function_exists('symlink') && @symlink($source, $stagePath)) {
            return;
        }

        Filesystem::copyFile($source, $stagePath);
    }

    private function emit(?callable $progressCallback, array $metrics): void
    {
        if ($progressCallback !== null) {
            $progressCallback($metrics);
        }
    }
}

