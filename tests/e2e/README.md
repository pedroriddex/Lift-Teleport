# Lift Teleport E2E

Planned E2E matrix:

1. Size profiles: `100MB`, `2GB`, `10GB+`.
2. Datasets: base WP, WooCommerce, serialized-heavy builders.
3. Reliability: session invalidation during `import_restore_database`, browser refresh, network flaps.
4. Security: malicious package (traversal/link), corrupted checksums, wrong password.
5. Flow control: cancel during import, rollback path, stale lock cleanup.

Minimum mandatory scenarios:

1. Export end-to-end and download signed `.lift`.
2. Import end-to-end without `/.maintenance` screen and with continuous progress.
3. Chunk resume with duplicated chunk retry and expected offset reconciliation.
4. Read-only mode blocks write methods with `423`, while `GET` routes remain available.
5. Package checksum verification fails early before destructive steps.
