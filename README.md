# Lift Teleport

Lift Teleport is a WordPress plugin for one-click website export and import using the `.lift` format.

## Operational Architecture

- Job orchestration and persistence: `/src/Jobs/*`
- Archive and package infrastructure: `/src/Archive/*`
- Import engine and continuity safeguards: `/src/Import/*`
- API surface: `/src/Api/Routes.php` (progressive decomposition in progress)
- Support services (paths, preflight, launcher, environment, schema): `/src/Support/*`

Architecture and contracts:

- `docs/architecture/current-state.md`
- `docs/contracts/rest.md`
- `docs/contracts/job-payload.md`
- `docs/deprecations.md`

## MVP Features

- Single-site migrations (`WP 6.6+`, `PHP 8.1+`)
- Full-site payload: database + complete `wp-content` (with safe exclusions)
- Re-runnable background jobs with persisted state in DB
- Heartbeat + cooperative cancellation for long-running jobs
- Chunked import upload (`8 MiB` chunks)
- `.lift` package format (`tar + zstd`, fallback `gzip`)
- Strict archive extraction policy (blocks path traversal and link entries)
- Full payload checksum verification before destructive import
- Optional encryption with password (`libsodium`, Argon2id + XChaCha20-Poly1305)
- Rollback snapshot before destructive import + read-only lock guard
- REST API + WP-CLI commands
- React admin UI aligned with Figma node `88:2`

## REST API

Base namespace: `/wp-json/lift/v1`

- `POST /jobs/export`
- `POST /jobs/import`
- `POST /jobs/{id}/upload-chunk`
- `POST /jobs/{id}/upload-complete`
- `POST /jobs/{id}/start`
- `POST /jobs/{id}/cancel`
- `GET /jobs/{id}`
- `GET /jobs/{id}/events`
- `GET /jobs/{id}/download?token=...&expires=...`
- `GET /diagnostics`

## WP-CLI

- `wp lift export [--output=<path>] [--password=<password>] [--format=text|json]`
- `wp lift import <file> [--password=<password>] [--yes] [--format=text|json]`
- `wp lift jobs list [--limit=<n>] [--format=table|json]`
- `wp lift jobs run <job-id> [--until-terminal] [--timeout=<seconds>] [--format=text|json]`
- `wp lift jobs cancel <job-id>`
- `wp lift diagnostics [--format=table|json]`

## Build Admin Assets

```bash
npm install
npm run build
```

## Validation and Tests

```bash
find src -name '*.php' -print0 | xargs -0 -n1 php -l
npm run lint:js
npm run build
composer install
vendor/bin/phpunit -c phpunit.xml.dist
```

## Environment Drift Check

```bash
./scripts/check_drift.sh
```

Defaults:

- Canonical path: `/Users/pedroreyes/Studio/bracar/wp-content/plugins/lift-teleport`
- Runtime path: `/Users/pedroreyes/Local Sites/bracar/app/public/wp-content/plugins/lift-teleport`

## Package Structure (`.lift`)

- `manifest.json`
- `db/dump.sql`
- `content/wp-content/plugins/*`
- `content/wp-content/themes/*`
- `content/wp-content/uploads/*`
- `content/wp-content/mu-plugins/*`
- `checksums/sha256.txt`

## Extensibility Filters

- `lift_teleport_export_excluded_paths`
- `lift_teleport_export_precheck_required_free_bytes`
- `lift_teleport_export_precheck_strict`
- `lift_teleport_import_precheck_required_free_bytes`
- `lift_teleport_max_upload_bytes`
- `lift_teleport_max_chunk_bytes`
- `lift_teleport_readonly_allowed_request`
- `lift_teleport_readonly_allowed_rest_route`
- `lift_teleport_replace_table_allowlist`
- `lift_teleport_replace_table_denylist`
- `lift_teleport_operator_session_continuity_enabled`
- `lift_teleport_operator_session_restore_strict`
- `lift_teleport_disable_wp_auth_check_on_admin_screen`
- `lift_teleport_capability_preflight_ttl_seconds`
- `lift_teleport_wp_cli_candidates`
- `lift_teleport_enable_cli_worker`
- `lift_teleport_cli_worker_timeout_seconds`
- `lift_teleport_capability_blocking_policy`
- `lift_teleport_export_download_allowed_roots`
- `lift_teleport_deprecated_step_alias_used`
