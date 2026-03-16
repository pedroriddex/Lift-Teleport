<?php

declare(strict_types=1);

namespace LiftTeleport\Api;

use LiftTeleport\Api\Controllers\BackupsController;
use LiftTeleport\Api\Controllers\ControllerContext;
use LiftTeleport\Api\Controllers\DiagnosticsController;
use LiftTeleport\Api\Controllers\JobsController;
use LiftTeleport\Api\Controllers\SettingsController;
use LiftTeleport\Api\Controllers\UnzipperController;
use LiftTeleport\Jobs\JobRepository;
use LiftTeleport\Jobs\JobRunner;
use WP_REST_Request;
use WP_REST_Server;

final class Routes
{
    private ControllerContext $context;

    private JobsController $jobsController;

    private UnzipperController $unzipperController;

    private BackupsController $backupsController;

    private SettingsController $settingsController;

    private DiagnosticsController $diagnosticsController;

    public function __construct(JobRepository $jobs, JobRunner $runner)
    {
        $this->context = new ControllerContext($jobs, $runner);
        $this->jobsController = new JobsController($this->context);
        $this->unzipperController = new UnzipperController($this->context);
        $this->backupsController = new BackupsController($this->context);
        $this->settingsController = new SettingsController($this->context);
        $this->diagnosticsController = new DiagnosticsController($this->context);
    }

    public function register(): void
    {
        register_rest_route('lift/v1', '/settings', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this->settingsController, 'getSettings'],
                'permission_callback' => [$this, 'canManage'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this->settingsController, 'updateSettings'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);

        register_rest_route('lift/v1', '/backups', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->backupsController, 'getBackups'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/backups/(?P<id>[A-Za-z0-9_-]+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this->backupsController, 'deleteBackup'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/backups/(?P<id>[A-Za-z0-9_-]+)/download', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->backupsController, 'downloadBackup'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/backups/(?P<id>[A-Za-z0-9_-]+)/import', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->backupsController, 'importBackup'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/jobs/export', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->jobsController, 'createExportJob'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/jobs/import', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->jobsController, 'createImportJob'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/unzipper/jobs', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->unzipperController, 'createJob'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/jobs/(?P<id>\d+)/upload-chunk', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->jobsController, 'uploadChunk'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/jobs/(?P<id>\d+)/upload-complete', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->jobsController, 'uploadComplete'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/unzipper/jobs/(?P<id>\d+)/upload-chunk', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->unzipperController, 'uploadChunk'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/unzipper/jobs/(?P<id>\d+)/upload-complete', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->unzipperController, 'uploadComplete'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/jobs/(?P<id>\d+)/start', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->jobsController, 'startJob'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/unzipper/jobs/(?P<id>\d+)/start', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->unzipperController, 'startJob'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/jobs/(?P<id>\d+)/heartbeat', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->jobsController, 'heartbeatJob'],
            'permission_callback' => [$this->jobsController, 'canHeartbeatJob'],
        ]);

        register_rest_route('lift/v1', '/jobs/(?P<id>\d+)/cancel', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->jobsController, 'cancelJob'],
            'permission_callback' => [$this->jobsController, 'canCancelJob'],
        ]);

        register_rest_route('lift/v1', '/jobs/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->jobsController, 'getJob'],
            'permission_callback' => [$this->jobsController, 'canReadJob'],
        ]);

        register_rest_route('lift/v1', '/unzipper/jobs/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->unzipperController, 'getJob'],
            'permission_callback' => [$this->jobsController, 'canReadJob'],
        ]);

        register_rest_route('lift/v1', '/unzipper/jobs/(?P<id>\d+)/entries', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->unzipperController, 'getEntries'],
            'permission_callback' => [$this->jobsController, 'canReadJob'],
        ]);

        register_rest_route('lift/v1', '/unzipper/jobs/(?P<id>\d+)/diagnostics', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->unzipperController, 'getDiagnostics'],
            'permission_callback' => [$this->jobsController, 'canReadJob'],
        ]);

        register_rest_route('lift/v1', '/unzipper/jobs/(?P<id>\d+)/cleanup', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->unzipperController, 'cleanupJob'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('lift/v1', '/jobs/(?P<id>\d+)/events', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->jobsController, 'getEvents'],
            'permission_callback' => [$this->jobsController, 'canReadJob'],
        ]);

        register_rest_route('lift/v1', '/jobs/resolve', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->jobsController, 'resolveJob'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('lift/v1', '/jobs/(?P<id>\d+)/download', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->jobsController, 'downloadExport'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('lift/v1', '/diagnostics', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->diagnosticsController, 'getDiagnostics'],
            'permission_callback' => [$this, 'canManage'],
        ]);
    }

    public function canManage(): bool
    {
        return $this->context->canManage();
    }

    // Backward-compatible public wrappers.

    public function getSettings(WP_REST_Request $request): WP_REST_Response
    {
        return $this->settingsController->getSettings($request);
    }

    public function updateSettings(WP_REST_Request $request)
    {
        return $this->settingsController->updateSettings($request);
    }

    public function getBackups(WP_REST_Request $request): WP_REST_Response
    {
        return $this->backupsController->getBackups($request);
    }

    public function downloadBackup(WP_REST_Request $request)
    {
        return $this->backupsController->downloadBackup($request);
    }

    public function deleteBackup(WP_REST_Request $request)
    {
        return $this->backupsController->deleteBackup($request);
    }

    public function importBackup(WP_REST_Request $request)
    {
        return $this->backupsController->importBackup($request);
    }

    public function canReadJob(WP_REST_Request $request): bool
    {
        return $this->jobsController->canReadJob($request);
    }

    public function canCancelJob(WP_REST_Request $request): bool
    {
        return $this->jobsController->canCancelJob($request);
    }

    public function canHeartbeatJob(WP_REST_Request $request): bool
    {
        return $this->jobsController->canHeartbeatJob($request);
    }

    public function createExportJob(WP_REST_Request $request)
    {
        return $this->jobsController->createExportJob($request);
    }

    public function createImportJob(WP_REST_Request $request)
    {
        return $this->jobsController->createImportJob($request);
    }

    public function createUnzipperJob(WP_REST_Request $request)
    {
        return $this->unzipperController->createJob($request);
    }

    public function uploadUnzipperChunk(WP_REST_Request $request)
    {
        return $this->unzipperController->uploadChunk($request);
    }

    public function uploadUnzipperComplete(WP_REST_Request $request)
    {
        return $this->unzipperController->uploadComplete($request);
    }

    public function startUnzipperJob(WP_REST_Request $request)
    {
        return $this->unzipperController->startJob($request);
    }

    public function getUnzipperJob(WP_REST_Request $request)
    {
        return $this->unzipperController->getJob($request);
    }

    public function getUnzipperEntries(WP_REST_Request $request)
    {
        return $this->unzipperController->getEntries($request);
    }

    public function getUnzipperDiagnostics(WP_REST_Request $request)
    {
        return $this->unzipperController->getDiagnostics($request);
    }

    public function cleanupUnzipperJob(WP_REST_Request $request)
    {
        return $this->unzipperController->cleanupJob($request);
    }

    public function getDiagnostics(WP_REST_Request $request): WP_REST_Response
    {
        return $this->diagnosticsController->getDiagnostics($request);
    }

    public function resolveJob(WP_REST_Request $request)
    {
        return $this->jobsController->resolveJob($request);
    }

    public function uploadChunk(WP_REST_Request $request)
    {
        return $this->jobsController->uploadChunk($request);
    }

    public function uploadComplete(WP_REST_Request $request)
    {
        return $this->jobsController->uploadComplete($request);
    }

    public function startJob(WP_REST_Request $request)
    {
        return $this->jobsController->startJob($request);
    }

    public function heartbeatJob(WP_REST_Request $request)
    {
        return $this->jobsController->heartbeatJob($request);
    }

    public function cancelJob(WP_REST_Request $request)
    {
        return $this->jobsController->cancelJob($request);
    }

    public function getJob(WP_REST_Request $request)
    {
        return $this->jobsController->getJob($request);
    }

    public function getEvents(WP_REST_Request $request)
    {
        return $this->jobsController->getEvents($request);
    }

    public function downloadExport(WP_REST_Request $request)
    {
        return $this->jobsController->downloadExport($request);
    }
}
