<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps\Import;

use LiftTeleport\Import\OperatorSessionContinuity;
use LiftTeleport\Jobs\Steps\AbstractStep;
use Throwable;

final class CaptureMergeAdminStep extends AbstractStep
{
    public function key(): string
    {
        return 'import_capture_merge_admin';
    }

    public function run(array $job): array
    {
        $jobId = $this->jobId($job);
        $payload = $this->payload($job);
        $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
        $requestedBy = (int) ($payload['requested_by'] ?? 0);

        $enabled = ! empty($settings['merge_admin']);
        $enabled = (bool) apply_filters('lift_teleport_merge_admin_enabled', $enabled, $job, $payload);

        if (! $enabled) {
            $payload['operator_session_continuity'] = false;
            $payload['operator_session_snapshot'] = [];
            $this->jobs->addEvent($jobId, 'info', 'operator_session_capture_skip', [
                'requested_by' => $requestedBy,
                'reason' => 'merge_admin_disabled',
            ]);

            return [
                'status' => 'next',
                'next_step' => 'import_snapshot',
                'payload' => $payload,
                'progress' => 45,
                'message' => 'Merge admin is disabled for this import.',
            ];
        }

        if ($requestedBy <= 0) {
            $payload['operator_session_continuity'] = false;
            $payload['operator_session_snapshot'] = [];
            $this->jobs->addEvent($jobId, 'warning', 'operator_session_capture_skip', [
                'requested_by' => $requestedBy,
                'reason' => 'no_requested_user',
            ]);

            return [
                'status' => 'next',
                'next_step' => 'import_snapshot',
                'payload' => $payload,
                'progress' => 45,
                'message' => 'Merge admin skipped: no operator user context.',
            ];
        }

        global $table_prefix;
        $continuity = new OperatorSessionContinuity();

        if (! $continuity->isEnabled()) {
            $payload['operator_session_continuity'] = false;
            $payload['operator_session_snapshot'] = [];
            $this->jobs->addEvent($jobId, 'warning', 'operator_session_capture_skip', [
                'requested_by' => $requestedBy,
                'reason' => 'continuity_disabled',
            ]);

            return [
                'status' => 'next',
                'next_step' => 'import_snapshot',
                'payload' => $payload,
                'progress' => 45,
                'message' => 'Merge admin skipped: operator continuity is disabled.',
            ];
        }

        try {
            $snapshot = $continuity->captureForJob($requestedBy, (string) $table_prefix);
            $payload['operator_session_snapshot'] = $snapshot;
            $payload['operator_session_continuity'] = true;
            $payload['operator_session_restore_error'] = null;

            $this->jobs->addEvent($jobId, 'info', 'operator_session_capture_ok', [
                'requested_by' => $requestedBy,
                'captured_meta_count' => is_array($snapshot['meta_rows'] ?? null) ? count($snapshot['meta_rows']) : 0,
            ]);
        } catch (Throwable $error) {
            $payload['operator_session_continuity'] = false;
            $payload['operator_session_snapshot'] = [];
            $this->jobs->addEvent($jobId, 'warning', 'operator_session_capture_failed', [
                'requested_by' => $requestedBy,
                'error_code' => 'operator_session_capture_failed',
                'error_message' => $error->getMessage(),
            ]);
        }

        return [
            'status' => 'next',
            'next_step' => 'import_snapshot',
            'payload' => $payload,
            'progress' => 45,
            'message' => 'Merge admin pre-capture completed.',
        ];
    }
}
