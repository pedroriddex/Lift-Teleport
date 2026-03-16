#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <source-site-domain|site-id|wp-root> [--import-into=<site>]"
  exit 1
fi

SOURCE_SITE="$1"
shift || true

IMPORT_TARGET=""
for arg in "$@"; do
  case "$arg" in
    --import-into=*)
      IMPORT_TARGET="${arg#--import-into=}"
      ;;
  esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_LOCAL="$SCRIPT_DIR/wp-local-cli.sh"
if [[ ! -x "$WP_LOCAL" ]]; then
  chmod +x "$WP_LOCAL"
fi

echo "== Lift smoke: export on ${SOURCE_SITE} =="
EXPORT_JSON="$("$WP_LOCAL" "$SOURCE_SITE" lift export --format=json)"
echo "$EXPORT_JSON"

EXPORT_FILE="$(php -r '
  $input = stream_get_contents(STDIN);
  $decoded = json_decode(trim((string) $input), true);
  if (!is_array($decoded)) {
    $lines = preg_split("/\R+/", trim((string) $input));
    foreach (array_reverse($lines ?: []) as $line) {
      $line = trim((string) $line);
      if ($line === "") { continue; }
      $json = json_decode($line, true);
      if (is_array($json)) { $decoded = $json; break; }
    }
  }
  if (!is_array($decoded)) { exit(1); }
  echo (string) ($decoded["file"] ?? "");
' <<< "$EXPORT_JSON" || true)"

if [[ -z "$EXPORT_FILE" || ! -f "$EXPORT_FILE" ]]; then
  echo "Smoke failed: exported .lift file was not found."
  exit 2
fi

if [[ ! -s "$EXPORT_FILE" ]]; then
  echo "Smoke failed: exported .lift file is empty."
  exit 3
fi

echo "Export artifact OK: $EXPORT_FILE ($(du -h "$EXPORT_FILE" | awk '{print $1}'))"

if [[ -n "$IMPORT_TARGET" ]]; then
  echo "== Lift smoke: import into ${IMPORT_TARGET} =="
  "$WP_LOCAL" "$IMPORT_TARGET" lift import "$EXPORT_FILE" --yes --format=json
  echo "Import smoke completed."
fi

echo "Smoke completed successfully."
