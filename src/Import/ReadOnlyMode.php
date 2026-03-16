<?php

declare(strict_types=1);

namespace LiftTeleport\Import;

use WP_Error;
use WP_REST_Request;

final class ReadOnlyMode
{
    private const OPTION_KEY = 'lift_teleport_readonly_lock';

    public function enable(int $jobId): void
    {
        if ($jobId <= 0) {
            return;
        }

        $existing = $this->lock();
        if ($existing !== null && (int) $existing['job_id'] === $jobId) {
            return;
        }

        update_option(self::OPTION_KEY, [
            'job_id' => $jobId,
            'enabled_at' => time(),
            'token' => wp_generate_password(32, false, false),
        ], false);
    }

    public function disable(?int $jobId = null): void
    {
        $existing = $this->lock();
        if ($existing === null) {
            return;
        }

        if ($jobId !== null && (int) $existing['job_id'] !== $jobId) {
            return;
        }

        delete_option(self::OPTION_KEY);
    }

    public function isEnabled(): bool
    {
        return $this->lock() !== null;
    }

    public function currentJobId(): ?int
    {
        $lock = $this->lock();
        if ($lock === null) {
            return null;
        }

        return (int) $lock['job_id'];
    }

    public function isStale(int $maxAgeSeconds): bool
    {
        $lock = $this->lock();
        if ($lock === null || $maxAgeSeconds <= 0) {
            return false;
        }

        $enabledAt = (int) ($lock['enabled_at'] ?? 0);
        if ($enabledAt <= 0) {
            return true;
        }

        return (time() - $enabledAt) > $maxAgeSeconds;
    }

    public function effectiveLockTtlSeconds(): int
    {
        $default = 6 * HOUR_IN_SECONDS;
        $ttl = (int) apply_filters('lift_teleport_readonly_max_age_seconds', $default);
        return max(300, $ttl);
    }

    public function shouldReleaseStaleLock(?callable $isImportJobActive = null): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $lock = $this->lock();
        if ($lock === null) {
            return false;
        }

        $jobId = (int) ($lock['job_id'] ?? 0);
        if ($jobId <= 0) {
            return true;
        }

        if ($this->isStale($this->effectiveLockTtlSeconds())) {
            return true;
        }

        if ($isImportJobActive !== null) {
            try {
                $active = (bool) $isImportJobActive($jobId);
                if (! $active) {
                    return true;
                }
            } catch (\Throwable) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{job_id:int,enabled_at:int,token:string}|null
     */
    public function lockInfo(): ?array
    {
        return $this->lock();
    }

    public function guardRequest(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        if ($this->isRestRequest()) {
            return;
        }

        $path = $this->requestPath();
        $allowed = $this->isAllowedWebRequest($path);

        $allowed = (bool) apply_filters('lift_teleport_readonly_allowed_request', $allowed, [
            'path' => $path,
            'method' => $method,
            'job_id' => $this->currentJobId(),
        ]);

        if ($allowed) {
            return;
        }

        if (function_exists('status_header')) {
            status_header(423);
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        wp_die(
            __('Lift Teleport import in progress. The site is temporarily read-only. Please retry in a minute.', 'lift-teleport'),
            __('Lift Teleport Import Running', 'lift-teleport'),
            ['response' => 423]
        );
    }

    public function guardRestRequest(mixed $result, mixed $server, WP_REST_Request $request): mixed
    {
        if (! $this->isEnabled()) {
            return $result;
        }

        $method = strtoupper($request->get_method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $result;
        }

        $route = $request->get_route();
        $allowed = $this->isAllowedRestRoute($route);

        $allowed = (bool) apply_filters(
            'lift_teleport_readonly_allowed_rest_route',
            $allowed,
            $route,
            $method,
            $request
        );

        if ($allowed) {
            return $result;
        }

        return new WP_Error(
            'lift_read_only',
            __('Import in progress. Write operations are temporarily locked.', 'lift-teleport'),
            [
                'status' => 423,
                'job_id' => $this->currentJobId(),
            ]
        );
    }

    /**
     * @return array{job_id:int,enabled_at:int,token:string}|null
     */
    private function lock(): ?array
    {
        $value = get_option(self::OPTION_KEY);
        if (! is_array($value)) {
            return null;
        }

        $jobId = (int) ($value['job_id'] ?? 0);
        if ($jobId <= 0) {
            return null;
        }

        return [
            'job_id' => $jobId,
            'enabled_at' => (int) ($value['enabled_at'] ?? 0),
            'token' => (string) ($value['token'] ?? ''),
        ];
    }

    private function isAllowedWebRequest(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_contains($path, '/wp-json/lift/v1/jobs')) {
            return true;
        }

        if (str_ends_with($path, '/wp-cron.php')) {
            return true;
        }

        return false;
    }

    private function isAllowedRestRoute(string $route): bool
    {
        return str_starts_with($route, '/lift/v1/jobs');
    }

    private function isRestRequest(): bool
    {
        if (isset($_GET['rest_route'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return true;
        }

        $path = $this->requestPath();
        if ($path === '') {
            return false;
        }

        $restPath = parse_url(rest_url(), PHP_URL_PATH);
        $restPrefix = is_string($restPath) ? rtrim($restPath, '/') : '/wp-json';

        return $restPrefix !== '' && str_starts_with($path, $restPrefix);
    }

    private function requestPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '') {
            return '';
        }

        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? $path : '';
    }
}
