<?php

declare(strict_types=1);

namespace LiftTeleport;

use LiftTeleport\Admin\Assets;
use LiftTeleport\Admin\Menu;
use LiftTeleport\Api\Routes;
use LiftTeleport\Cli\Commands;
use LiftTeleport\Import\ReadOnlyMode;
use LiftTeleport\Jobs\JobRepository;
use LiftTeleport\Jobs\JobRunner;
use LiftTeleport\Support\ArtifactGarbageCollector;
use LiftTeleport\Support\Paths;
use LiftTeleport\Support\SchemaOutOfSyncException;
use LiftTeleport\Unzipper\PackageInspector;
use WP_REST_Request;
use WP_REST_Server;

final class Plugin
{
    private const ASYNC_WORKER_TOKEN_OPTION = 'lift_teleport_async_worker_token';

    private const ASYNC_WORKER_LAST_DISPATCH_OPTION = 'lift_teleport_async_worker_last_dispatch_at';

    private static ?Plugin $instance = null;

    private JobRepository $jobs;

    private JobRunner $runner;

    private Assets $assets;

    private Menu $menu;

    private Routes $routes;

    private ReadOnlyMode $readOnlyMode;

    private int $lastLockSanityCheckAt = 0;

    private int $lastSchemaCheckAt = 0;

    public static function boot(): void
    {
        if (self::$instance !== null) {
            return;
        }

        self::$instance = new self();
        self::$instance->registerHooks();
    }

    public static function activate(): void
    {
        $jobs = new JobRepository();
        $jobs->createTables();
        $jobs->repairSchemaIfNeeded();

        Paths::ensureBaseDirs();

        wp_clear_scheduled_hook('lift_teleport_cleanup_jobs');
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'lift_teleport_cleanup_jobs');
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('lift_teleport_cleanup_jobs');
        wp_clear_scheduled_hook('lift_teleport_process_jobs');
        delete_option('lift_teleport_readonly_lock');
        delete_option('lift_teleport_startup_storage_cleanup_at');
        delete_option(self::ASYNC_WORKER_TOKEN_OPTION);
        delete_option(self::ASYNC_WORKER_LAST_DISPATCH_OPTION);
    }

    private function __construct()
    {
        $this->jobs = new JobRepository();
        $this->runner = new JobRunner($this->jobs);
        $this->assets = new Assets();
        $this->menu = new Menu($this->assets);
        $this->routes = new Routes($this->jobs, $this->runner);
        $this->readOnlyMode = new ReadOnlyMode();
    }

    private function registerHooks(): void
    {
        add_action('plugins_loaded', [$this, 'onPluginsLoaded']);
        add_action('init', [$this, 'guardReadOnlyRequests'], 1);
        add_action('rest_api_init', [$this->routes, 'register']);
        add_action('admin_menu', [$this->menu, 'register']);
        add_action('admin_enqueue_scripts', [$this->assets, 'enqueue']);
        add_filter('rest_pre_dispatch', [$this, 'guardReadOnlyRestRequests'], 5, 3);

        add_action('lift_teleport_process_jobs', [$this, 'processNextJob']);
        add_action('lift_teleport_cleanup_jobs', [$this, 'cleanup']);
        add_action('lift_teleport_dispatch_worker', [$this, 'dispatchBackgroundWorker'], 10, 1);
        add_action('wp_ajax_lift_teleport_worker', [$this, 'handleBackgroundWorkerAjax']);
        add_action('wp_ajax_nopriv_lift_teleport_worker', [$this, 'handleBackgroundWorkerAjax']);

        if (defined('WP_CLI') && WP_CLI) {
            Commands::register($this->jobs, $this->runner);
        }
    }

    public function onPluginsLoaded(): void
    {
        load_plugin_textdomain('lift-teleport', false, dirname(LIFT_TELEPORT_BASENAME) . '/languages');
        Paths::ensureBaseDirs();
        $this->ensureSchemaHealthy(true);
        $this->sanitizeRuntimeLocks(true);
        $this->startupStorageSanityCleanup();
    }

    public function guardReadOnlyRequests(): void
    {
        $this->sanitizeRuntimeLocks();
        $this->readOnlyMode->guardRequest();
    }

    public function guardReadOnlyRestRequests(mixed $result, WP_REST_Server $server, WP_REST_Request $request): mixed
    {
        return $this->readOnlyMode->guardRestRequest($result, $server, $request);
    }

    public function processNextJob(): void
    {
        $this->sanitizeRuntimeLocks();
        if (! $this->ensureSchemaHealthy()) {
            return;
        }

        try {
            $job = $this->jobs->getNextRunnable();
        } catch (SchemaOutOfSyncException $error) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Lift Teleport] Unable to fetch runnable job because schema is out of sync: ' . $error->getMessage());
            }
            return;
        }

        if (! $job) {
            return;
        }

        $this->runner->run((int) $job['id']);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function dispatchBackgroundWorker(array $context = []): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (! (bool) apply_filters('lift_teleport_enable_async_worker', true, $context)) {
            return;
        }

        $force = ! empty($context['force']);
        if (defined('LIFT_TELEPORT_ASYNC_WORKER') && LIFT_TELEPORT_ASYNC_WORKER && ! $force) {
            return;
        }

        if (! $this->jobs->hasActiveJob()) {
            return;
        }

        $now = time();
        $minInterval = (int) apply_filters('lift_teleport_async_worker_dispatch_interval_seconds', 3, $context);
        $minInterval = max(1, min(30, $minInterval));
        $lastDispatchAt = (int) get_option(self::ASYNC_WORKER_LAST_DISPATCH_OPTION, 0);
        if (! $force && $lastDispatchAt > 0 && ($now - $lastDispatchAt) < $minInterval) {
            return;
        }

        update_option(self::ASYNC_WORKER_LAST_DISPATCH_OPTION, $now, false);

        $url = add_query_arg(
            [
                'action' => 'lift_teleport_worker',
                'lift_worker_token' => $this->asyncWorkerToken(),
                '_' => (string) microtime(true),
            ],
            admin_url('admin-ajax.php')
        );

        $timeout = (float) apply_filters('lift_teleport_async_worker_dispatch_timeout_seconds', 0.5, $context);
        $timeout = max(0.05, min(5.0, $timeout));
        $args = [
            'timeout' => $timeout,
            'blocking' => false,
            'sslverify' => (bool) apply_filters('https_local_ssl_verify', false),
            'headers' => [
                'Cache-Control' => 'no-cache',
            ],
        ];

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response) && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Lift Teleport] Async worker dispatch failed: ' . $response->get_error_message());
        }
    }

    public function handleBackgroundWorkerAjax(): void
    {
        $providedToken = isset($_REQUEST['lift_worker_token'])
            ? (string) sanitize_text_field(wp_unslash((string) $_REQUEST['lift_worker_token']))
            : '';
        $expectedToken = $this->asyncWorkerToken();
        if ($providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            status_header(403);
            wp_die('forbidden');
        }

        if (! defined('LIFT_TELEPORT_ASYNC_WORKER')) {
            define('LIFT_TELEPORT_ASYNC_WORKER', true);
        }

        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $runtimeBudget = (int) apply_filters('lift_teleport_async_worker_runtime_seconds', 45);
        $runtimeBudget = max(10, min(240, $runtimeBudget));
        $sliceSeconds = (int) apply_filters('lift_teleport_async_worker_slice_seconds', 8);
        $sliceSeconds = max(3, min(20, $sliceSeconds));
        $maxJobsPerBurst = (int) apply_filters('lift_teleport_async_worker_max_jobs_per_burst', 8);
        $maxJobsPerBurst = max(1, min(50, $maxJobsPerBurst));

        $deadline = microtime(true) + $runtimeBudget;
        $processed = 0;
        $startedAt = microtime(true);

        while ($processed < $maxJobsPerBurst && microtime(true) < $deadline) {
            $this->sanitizeRuntimeLocks();
            if (! $this->ensureSchemaHealthy()) {
                break;
            }

            try {
                $job = $this->jobs->getNextRunnable();
            } catch (SchemaOutOfSyncException $error) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Lift Teleport] Async worker aborted due to schema mismatch: ' . $error->getMessage());
                }
                break;
            }

            if (! $job) {
                break;
            }

            $this->runner->run((int) $job['id'], $sliceSeconds);
            $processed++;
        }

        $activeJob = $this->jobs->getNextRunnable();
        if ($activeJob) {
            $this->runner->scheduleProcessing();
            $this->dispatchBackgroundWorker([
                'source' => 'async_worker_tail',
                'force' => true,
            ]);
        }

        wp_send_json_success([
            'processed_jobs' => $processed,
            'active_job_remaining' => (bool) $activeJob,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    public function cleanup(): void
    {
        $this->jobs->cleanupOldJobs(7);
        (new ArtifactGarbageCollector($this->jobs))->cleanupRetention();

        $this->cleanupStaleUnzipperArtifacts();
    }

    private function cleanupStaleReadOnlyLock(): void
    {
        if ($this->readOnlyMode->shouldReleaseStaleLock(function (int $jobId): bool {
            return $this->jobs->isImportJobActive($jobId);
        })) {
            $this->readOnlyMode->disable();
        }
    }

    private function cleanupLegacyMaintenanceFile(): void
    {
        $maintenance = ABSPATH . '.maintenance';
        if (! file_exists($maintenance)) {
            return;
        }

        if ($this->jobs->hasActiveImportJob()) {
            return;
        }

        $maxAge = (int) apply_filters('lift_teleport_legacy_maintenance_cleanup_age_seconds', 300);
        $maxAge = max(30, $maxAge);
        $modifiedAt = @filemtime($maintenance);
        if (is_int($modifiedAt) && $modifiedAt > 0 && (time() - $modifiedAt) < $maxAge) {
            return;
        }

        @unlink($maintenance);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Lift Teleport] Removed stale .maintenance file because no Lift import job is active.');
        }
    }

    private function sanitizeRuntimeLocks(bool $force = false): void
    {
        $interval = (int) apply_filters('lift_teleport_lock_sanity_check_interval_seconds', 15);
        $interval = max(1, $interval);
        $now = time();

        if (! $force && ($now - $this->lastLockSanityCheckAt) < $interval) {
            return;
        }

        $this->lastLockSanityCheckAt = $now;
        $this->cleanupStaleReadOnlyLock();
        $this->cleanupLegacyMaintenanceFile();
    }

    private function ensureSchemaHealthy(bool $force = false): bool
    {
        $interval = (int) apply_filters('lift_teleport_schema_health_check_interval_seconds', 60);
        $interval = max(5, $interval);
        $now = time();

        if (! $force && ($now - $this->lastSchemaCheckAt) < $interval) {
            return $this->jobs->isSchemaHealthy();
        }

        $this->lastSchemaCheckAt = $now;

        try {
            if ($this->jobs->isSchemaHealthy(true)) {
                return true;
            }

            $result = $this->jobs->repairSchemaIfNeeded();
            if (! empty($result['health'])) {
                return true;
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Lift Teleport] Schema repair failed: ' . wp_json_encode($result));
            }
        } catch (\Throwable $error) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Lift Teleport] Schema health check failed: ' . $error->getMessage());
            }
        }

        return false;
    }

    private function cleanupStaleUnzipperArtifacts(): void
    {
        $staleSeconds = (int) apply_filters('lift_teleport_unzipper_stale_cleanup_seconds', 6 * HOUR_IN_SECONDS);
        $staleSeconds = max(300, $staleSeconds);

        $jobIds = $this->jobs->staleTerminalJobIdsByType('unzipper', $staleSeconds, 300);
        if ($jobIds === []) {
            return;
        }

        $inspector = new PackageInspector();
        foreach ($jobIds as $jobId) {
            $job = $this->jobs->get((int) $jobId);
            if (! $job) {
                continue;
            }

            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $unzipper = is_array($payload['unzipper'] ?? null) ? $payload['unzipper'] : [];
            $cleanedAt = (string) ($unzipper['cleaned_at'] ?? '');
            if ($cleanedAt !== '') {
                continue;
            }

            $inspector->cleanup((int) $jobId);

            unset($unzipper['artifacts']);
            $unzipper['cleaned_at'] = gmdate(DATE_ATOM);
            $unzipper['cleanup_reason'] = 'stale_retention';
            $payload['unzipper'] = $unzipper;

            $this->jobs->update((int) $jobId, [
                'payload' => $payload,
                'message' => 'Unzipper artifacts cleaned by retention policy.',
            ]);
            $this->jobs->addEvent((int) $jobId, 'info', 'unzipper_cleanup_completed', [
                'reason' => 'stale_retention',
                'stale_seconds' => $staleSeconds,
            ]);
        }
    }

    private function startupStorageSanityCleanup(): void
    {
        $interval = (int) apply_filters('lift_teleport_startup_storage_cleanup_interval_seconds', 900);
        $interval = max(60, $interval);
        $now = time();
        $last = (int) get_option('lift_teleport_startup_storage_cleanup_at', 0);

        if ($last > 0 && ($now - $last) < $interval) {
            return;
        }

        update_option('lift_teleport_startup_storage_cleanup_at', $now, false);
        (new ArtifactGarbageCollector($this->jobs))->cleanupOrphanedJobDirectories(50);
    }

    private function asyncWorkerToken(): string
    {
        $token = (string) get_option(self::ASYNC_WORKER_TOKEN_OPTION, '');
        if ($token !== '' && preg_match('/^[A-Za-z0-9]{32,}$/', $token) === 1) {
            return $token;
        }

        $token = wp_generate_password(64, false, false);
        update_option(self::ASYNC_WORKER_TOKEN_OPTION, $token, false);
        return $token;
    }
}
