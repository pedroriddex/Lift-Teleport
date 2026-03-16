<?php

declare(strict_types=1);

namespace LiftTeleport\Api\Http;

use WP_Error;

final class ErrorResponder
{
    /**
     * @param array<string,mixed> $context
     */
    public static function error(string $code, string $message, int $status, array $context = []): WP_Error
    {
        return new WP_Error($code, $message, [
            'status' => $status,
            'error_code' => $code,
            'context' => $context,
        ]);
    }
}
