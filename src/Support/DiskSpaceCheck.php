<?php

declare(strict_types=1);

namespace LiftTeleport\Support;

final class DiskSpaceCheck
{
    /**
     * @param array<int,string> $paths
     * @return array{
     *   required:int,
     *   available:?int,
     *   path_used:string,
     *   paths:array<string,int|null>,
     *   insufficient:bool,
     *   confidence:string
     * }
     */
    public static function evaluate(array $paths, int $required): array
    {
        $required = max(1, $required);
        $samples = [];

        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '' || isset($samples[$path])) {
                continue;
            }

            $samples[$path] = Filesystem::freeDiskSpace($path);
        }

        $available = null;
        $pathUsed = '';
        $maxPositive = null;
        $minPositive = null;
        foreach ($samples as $path => $bytes) {
            if (! is_int($bytes) || $bytes <= 0) {
                continue;
            }

            if ($maxPositive === null || $bytes > $maxPositive) {
                $maxPositive = $bytes;
                $pathUsed = (string) $path;
            }

            if ($minPositive === null || $bytes < $minPositive) {
                $minPositive = $bytes;
            }
        }

        if ($maxPositive !== null) {
            $available = $maxPositive;
        }

        if ($available !== null && $available <= 0 && $pathUsed !== '' && self::writableProbe($pathUsed)) {
            $available = null;
            $pathUsed = '';
        }

        $confidence = 'unknown';
        if ($available !== null) {
            $confidence = 'measured';
            if ($minPositive !== null && $minPositive > 0) {
                $spread = (float) $available / (float) $minPositive;
                if ($spread >= 10.0) {
                    $confidence = 'mixed';
                }
            }
        }

        $insufficient = $confidence === 'measured' && $available !== null && $available < $required;

        return [
            'required' => $required,
            'available' => $available,
            'path_used' => $pathUsed,
            'paths' => $samples,
            'insufficient' => $insufficient,
            'confidence' => $confidence,
        ];
    }

    private static function writableProbe(string $path): bool
    {
        $target = trim($path);
        if ($target === '') {
            return false;
        }

        while (! file_exists($target)) {
            $parent = dirname($target);
            if ($parent === $target) {
                return false;
            }
            $target = $parent;
        }

        if (! is_dir($target) || ! is_writable($target)) {
            return false;
        }

        $probe = @tempnam($target, 'lift-probe-');
        if (! is_string($probe) || $probe === '') {
            return false;
        }

        $ok = @file_put_contents($probe, '1') !== false;
        @unlink($probe);

        return $ok;
    }
}
