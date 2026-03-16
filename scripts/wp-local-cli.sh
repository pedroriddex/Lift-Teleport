#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <local-site-domain|site-id|wp-root> [wp-cli args...]"
  exit 1
fi

SITE_REF="$1"
shift || true

resolve_wp_root() {
  local ref="$1"

  if [[ -d "$ref" && -f "$ref/wp-config.php" ]]; then
    printf '%s|%s\n' "$ref" ""
    return 0
  fi

  local sites_json="$HOME/Library/Application Support/Local/sites.json"
  if [[ ! -f "$sites_json" ]]; then
    return 1
  fi

  php -r '
    $file = $argv[1];
    $needle = $argv[2];
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data)) { exit(1); }
    foreach ($data as $site) {
      if (!is_array($site)) { continue; }
      $id = (string) ($site["id"] ?? "");
      $domain = "";
      if (isset($site["domain"])) {
        if (is_array($site["domain"])) {
          $domain = (string) ($site["domain"]["domain"] ?? "");
        } else {
          $domain = (string) $site["domain"];
        }
      }
      $path = (string) ($site["path"] ?? "");
      if ($path === "") { continue; }
      if (strpos($path, "~/") === 0) {
        $path = rtrim((string) getenv("HOME"), "/") . substr($path, 1);
      }
      if ($needle === $id || $needle === $domain || (strpos($domain, $needle) !== false)) {
        $candidate = rtrim($path, "/") . "/app/public";
        if (is_file($candidate . "/wp-config.php")) {
          echo $candidate . "|" . $id;
          exit(0);
        }
      }
    }
    exit(1);
  ' "$sites_json" "$ref"
}

RESOLVED="$(resolve_wp_root "$SITE_REF" || true)"
WP_ROOT="${RESOLVED%%|*}"
SITE_ID="${RESOLVED##*|}"
if [[ -z "$WP_ROOT" || ! -f "$WP_ROOT/wp-config.php" ]]; then
  echo "Unable to resolve WordPress root for: $SITE_REF"
  exit 2
fi

WP_BIN="$(command -v wp || true)"
if [[ -z "$WP_BIN" ]]; then
  echo "wp command is not available in PATH"
  exit 3
fi

PREPEND_FILE=""
cleanup() {
  if [[ -n "$PREPEND_FILE" && -f "$PREPEND_FILE" ]]; then
    rm -f "$PREPEND_FILE"
  fi
}
trap cleanup EXIT

if [[ -n "$SITE_ID" ]]; then
  SOCKET_PATH="$HOME/Library/Application Support/Local/run/${SITE_ID}/mysql/mysqld.sock"
  if [[ -S "$SOCKET_PATH" ]]; then
    PREPEND_FILE="$(mktemp "/tmp/lift-local-wp-prepend.XXXXXX")"
    cat > "$PREPEND_FILE" <<PHP
<?php
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost:${SOCKET_PATH}');
}
PHP
  fi
fi

cd "$WP_ROOT"
if [[ -n "$PREPEND_FILE" ]]; then
  exec php -d error_reporting=E_ERROR -d display_errors=0 -d auto_prepend_file="$PREPEND_FILE" "$WP_BIN" --path="$WP_ROOT" --allow-root "$@"
fi

exec php -d error_reporting=E_ERROR -d display_errors=0 "$WP_BIN" --path="$WP_ROOT" --allow-root "$@"
