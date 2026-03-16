# REST Contract (Lift Teleport)

Namespace: `/wp-json/lift/v1`

## Stability Policy

- Public routes are backward compatible.
- New fields may be added as optional keys.
- Existing status codes and core response shape are preserved.

## Jobs

- `POST /jobs/export`
- `POST /jobs/import`
- `POST /jobs/{id}/upload-chunk`
- `POST /jobs/{id}/upload-complete`
- `POST /jobs/{id}/start`
- `POST /jobs/{id}/heartbeat`
- `POST /jobs/{id}/cancel`
- `GET /jobs/{id}`
- `GET /jobs/{id}/events`
- `GET /jobs/resolve`
- `GET /jobs/{id}/download`

## Unzipper

- `POST /unzipper/jobs`
- `POST /unzipper/jobs/{id}/upload-chunk`
- `POST /unzipper/jobs/{id}/upload-complete`
- `POST /unzipper/jobs/{id}/start`
- `GET /unzipper/jobs/{id}`
- `GET /unzipper/jobs/{id}/entries`
- `GET /unzipper/jobs/{id}/diagnostics`
- `POST /unzipper/jobs/{id}/cleanup`

## Settings / Backups / Diagnostics

- `GET /settings`
- `POST /settings`
- `GET /backups`
- `GET /backups/{id}/download`
- `DELETE /backups/{id}`
- `POST /backups/{id}/import`
- `GET /diagnostics`

## Error Envelope

Errors are returned as `WP_Error` with:

- `code` (internal error code)
- `message` (operator-safe message)
- `data.status` (HTTP status)
- Optional diagnostics:
  - `retryable`
  - `hint`
  - contextual fields (`expected_offset`, `received_offset`, etc.)

## Tokenized Access

Non-admin polling may access job status via `job_token` (`query` or `x-lift-job-token` header), limited to token-matching jobs.
