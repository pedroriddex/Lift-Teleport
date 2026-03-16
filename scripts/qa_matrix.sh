#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
RUNS="${2:-1}"

if ! [[ "$RUNS" =~ ^[0-9]+$ ]] || [[ "$RUNS" -lt 1 ]]; then
  echo "RUNS must be a positive integer" >&2
  exit 1
fi

cd "$ROOT_DIR"

echo "[Lift QA] root=$ROOT_DIR runs=$RUNS"

for ((i=1; i<=RUNS; i++)); do
  echo "[Lift QA] run $i/$RUNS: php lint"
  find src tests/phpunit -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

  echo "[Lift QA] run $i/$RUNS: phpunit"
  vendor/bin/phpunit -c phpunit.xml.dist

  if [[ -f package.json ]]; then
    echo "[Lift QA] run $i/$RUNS: admin build"
    npm run build >/dev/null
  fi

done

echo "[Lift QA] completed $RUNS run(s) successfully"
