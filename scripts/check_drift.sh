#!/usr/bin/env bash
set -euo pipefail

CANONICAL="${1:-/Users/pedroreyes/Studio/bracar/wp-content/plugins/lift-teleport}"
RUNTIME="${2:-/Users/pedroreyes/Local Sites/bracar/app/public/wp-content/plugins/lift-teleport}"

if [[ ! -d "$CANONICAL" ]]; then
  echo "ERROR: canonical path not found: $CANONICAL" >&2
  exit 1
fi

if [[ ! -d "$RUNTIME" ]]; then
  echo "ERROR: runtime path not found: $RUNTIME" >&2
  exit 1
fi

TMP1=$(mktemp)
TMP2=$(mktemp)
trap 'rm -f "$TMP1" "$TMP2"' EXIT

(
  cd "$CANONICAL"
  find . -type f \
    ! -path './node_modules/*' \
    ! -path './vendor/*' \
    ! -path './.git/*' \
    -print0 | sort -z | xargs -0 shasum
) > "$TMP1"

(
  cd "$RUNTIME"
  find . -type f \
    ! -path './node_modules/*' \
    ! -path './vendor/*' \
    ! -path './.git/*' \
    -print0 | sort -z | xargs -0 shasum
) > "$TMP2"

if diff -u "$TMP1" "$TMP2" >/dev/null; then
  echo "OK: canonical and runtime plugin trees are in sync"
  exit 0
fi

echo "DRIFT DETECTED between:"
echo "  canonical: $CANONICAL"
echo "  runtime:   $RUNTIME"
echo
diff -u "$TMP1" "$TMP2" || true
exit 2
