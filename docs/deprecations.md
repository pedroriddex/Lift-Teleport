# Deprecations

## Current Window (next release)

### Legacy import step key

- Deprecated: `import_maintenance_on`
- Replacement: `import_readonly_on`
- Compatibility: accepted as alias for one release cycle.
- Behavior: emits a deprecation warning event when resolved by `Factory`.

### Legacy frontend progress label

- Deprecated label key: `import_maintenance_on`
- Frontend now resolves it to read-only mode wording for backward compatibility.

## Removal policy

- Deprecated aliases remain available for one release.
- The next major cleanup removes alias keys and dead step files once all active jobs are beyond the deprecation window.
