<?php

declare(strict_types=1);

namespace LiftTeleport\Support;

final class DownloadToken
{
    public static function generate(int $jobId, int $expires): string
    {
        $payload = $jobId . '|' . $expires;
        return hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    public static function verify(int $jobId, int $expires, string $token): bool
    {
        if ($expires < time()) {
            return false;
        }

        $expected = self::generate($jobId, $expires);
        return hash_equals($expected, $token);
    }
}
