# Job Payload Contract

This document defines stable payload keys used across job steps.

## Common Keys

- `job_token` (string): resumable access token for polling/recovery.
- `requested_by` (int): user id that started the job.
- `settings` (array): immutable snapshot of settings for this job.
- `runtime_fingerprint` (array): plugin/runtime fingerprint at job creation.
- `capability_preflight` (array): host capability snapshot at job creation.
- `execution_plan` (array): selected runner/archive plan for this job.
- `step_metrics` (array): per-step execution metrics.
- `diagnostics` (array): operational state/progress metadata.

## Export Payload Keys

- `password`
- `manifest`
- `package`
- `download`
- `storage_cleanup`

## Import Payload Keys

- `file_name`, `total_bytes`, `uploaded_bytes`
- `upload_temp_path`, `upload_path`
- `source_file` (import from backup)
- `import_lift_file`, `import_lift_size`, `import_compression`
- `operator_session_continuity`, `operator_session_snapshot`, `operator_session_restored`
- `readonly_enabled`
- `rollback_*`
- `cancel_requested`, `cancel_reason_*`

## Unzipper Payload Keys

- `unzipper.quick_status`
- `unzipper.full_status`
- `unzipper.summary`
- `unzipper.artifacts`
- `unzipper.cleanup_on_close`

## Forward Compatibility

- Unknown keys must be ignored by readers.
- New optional keys may be added without migration.
- Legacy keys can remain for one deprecation window when aliasing behavior is enabled.
