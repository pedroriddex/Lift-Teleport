<?php

declare(strict_types=1);

namespace LiftTeleport\Api\Controllers;

use LiftTeleport\Jobs\JobRepository;
use LiftTeleport\Support\ArtifactGarbageCollector;
use LiftTeleport\Support\Environment;
use LiftTeleport\Support\SchemaOutOfSyncException;
use WP_REST_Request;
use WP_REST_Response;

final class DiagnosticsController
{
    public function __construct(private ControllerContext $ctx)
    {
    }

    public function getDiagnostics(WP_REST_Request $request): WP_REST_Response
    {
        $schema = $this->ctx->jobs()->inspectSchema(true);
        $refreshCapabilities = filter_var(
            $request->get_param('refresh_capabilities'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) === true;
        $environment = Environment::diagnostics($this->ctx->jobs(), $refreshCapabilities);
        $storage = is_array($environment['storage'] ?? null)
            ? $environment['storage']
            : (new ArtifactGarbageCollector($this->ctx->jobs()))->storageSummary();
        $active = null;
        $hasActiveJob = false;
        $workerHealth = 'idle';
        $workerLastHeartbeatAt = '';
        $staleReason = '';
        $staleTtl = (int) apply_filters('lift_teleport_active_job_stale_seconds', 600);
        $staleTtl = max(60, $staleTtl);
        if (! empty($schema['health'])) {
            try {
                $active = $this->ctx->jobs()->getNextRunnable();
                $hasActiveJob = $this->ctx->jobs()->hasActiveJob();
            } catch (SchemaOutOfSyncException) {
                $active = null;
                $hasActiveJob = false;
            }
        }

        if ($active) {
            $workerLastHeartbeatAt = (string) ($active['worker_heartbeat_at'] ?? '');
            if ($workerLastHeartbeatAt === '') {
                $workerLastHeartbeatAt = (string) ($active['heartbeat_at'] ?? '');
            }

            $status = (string) ($active['status'] ?? '');
            $workerHealth = in_array($status, [JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING, JobRepository::STATUS_UPLOADING], true)
                ? 'unknown'
                : 'idle';

            $heartbeatTs = $workerLastHeartbeatAt !== '' ? strtotime($workerLastHeartbeatAt) : false;
            if ($heartbeatTs !== false && $heartbeatTs > 0) {
                if ((time() - $heartbeatTs) > $staleTtl) {
                    $workerHealth = 'stalled';
                    $staleReason = 'worker_heartbeat_timeout';
                } else {
                    $workerHealth = 'healthy';
                    $staleReason = '';
                }
            } elseif ($status === JobRepository::STATUS_RUNNING) {
                $workerHealth = 'stalled';
                $staleReason = 'missing_worker_heartbeat';
            }
        }

        return new WP_REST_Response([
            'environment' => $environment,
            'storage' => $storage,
            'schema' => $schema,
            'has_active_job' => $hasActiveJob,
            'active_job' => $active ? $this->ctx->sanitizeJob($active, true) : null,
            'worker_health' => $workerHealth,
            'worker_last_heartbeat_at' => $workerLastHeartbeatAt,
            'stale_reason' => $staleReason,
            'timestamp' => gmdate(DATE_ATOM),
        ]);
    }
}
