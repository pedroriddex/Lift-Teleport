# Lift Teleport Current State

## Runtime Model

- Plugin bootstrap: `lift-teleport.php` -> `LiftTeleport\Plugin::boot()`.
- Core orchestration: DB-backed jobs in `wp_lift_jobs` + event stream in `wp_lift_job_events`.
- Execution model: resumable step pipeline with lock claiming, heartbeat, retry policy, and cron scheduling.
- Import protection model: plugin-level read-only lock (no hard dependency on `/.maintenance`).
- Artifact lifecycle: managed under `wp-content/lift-teleport-data` with aggressive cleanup policies.
- REST layer refactor state:
  - `src/Api/Routes.php` acts as thin router + compatibility wrappers.
  - Endpoint logic moved into domain controllers under `src/Api/Controllers/*`.
  - Shared REST helpers and guards centralized in `src/Api/Controllers/ControllerContext.php`.

## Main Flows

1. Export
- `export_validate`
- `export_dump_database`
- `export_build_manifest`
- `export_package`
- `export_finalize`

2. Import
- `import_validate_package`
- `import_precheck_space`
- `import_capture_merge_admin`
- `import_snapshot`
- `import_readonly_on`
- `import_extract_package`
- `import_restore_database`
- `import_sync_content`
- `import_finalize`

3. Unzipper
- `unzipper_validate_package`
- `unzipper_quick_scan`
- `unzipper_full_integrity`
- `unzipper_finalize`

## Current Structural Hotspots

- `src/Api/Routes.php` (monolithic endpoint + workflow logic)
- `src/Jobs/JobRepository.php` (schema + persistence + state transitions)
- `src/Archive/CompressionEngine.php` (multi-strategy archive handling)
- `src/Archive/LiftPackage.php` (format, manifest, checksums, verification)

## Operational Guarantees

- Single active job policy to reduce destructive concurrency.
- Import preflight fail-fast before destructive stage.
- Structured event logs for operator diagnostics.
- Runtime capability preflight snapshot attached per job.

## Environment Drift

- Canonical source path is expected in Studio workspace.
- Runtime can differ on Local/SSH deployments.
- Drift check script: `scripts/check_drift.sh`.
