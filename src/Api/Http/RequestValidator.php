<?php

declare(strict_types=1);

namespace LiftTeleport\Api\Http;

final class RequestValidator
{
    /**
     * @return array{valid:bool,error_code:string,message:string,status:int,context:array<string,mixed>}
     */
    public static function validateChunkBounds(int $offset, int $chunkSize, int $totalBytes): array
    {
        if ($offset < 0) {
            return [
                'valid' => false,
                'error_code' => 'lift_invalid_offset',
                'message' => 'Chunk offset must be >= 0.',
                'status' => 400,
                'context' => ['received_offset' => $offset],
            ];
        }

        if ($chunkSize <= 0) {
            return [
                'valid' => false,
                'error_code' => 'lift_invalid_chunk_size',
                'message' => 'Chunk payload is empty.',
                'status' => 400,
                'context' => ['received_chunk_size' => $chunkSize],
            ];
        }

        if ($totalBytes > 0 && $offset > $totalBytes) {
            return [
                'valid' => false,
                'error_code' => 'lift_invalid_offset',
                'message' => 'Chunk offset exceeds total upload size.',
                'status' => 400,
                'context' => [
                    'received_offset' => $offset,
                    'total_bytes' => $totalBytes,
                ],
            ];
        }

        if ($totalBytes > 0 && ($offset + $chunkSize) > $totalBytes) {
            return [
                'valid' => false,
                'error_code' => 'lift_chunk_bounds_exceeded',
                'message' => 'Chunk exceeds declared upload size.',
                'status' => 400,
                'context' => [
                    'received_offset' => $offset,
                    'chunk_size' => $chunkSize,
                    'total_bytes' => $totalBytes,
                ],
            ];
        }

        return [
            'valid' => true,
            'error_code' => '',
            'message' => '',
            'status' => 200,
            'context' => [],
        ];
    }

    public static function isPathInsideRoot(string $path, string $root): bool
    {
        $path = self::normalizePath($path);
        $root = self::normalizePath($root);

        if ($path === '' || $root === '') {
            return false;
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }
}
