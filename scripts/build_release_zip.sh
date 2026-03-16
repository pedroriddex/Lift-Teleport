#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PLUGIN_NAME="$(basename "$PLUGIN_DIR")"
OUTPUT_DIR="${2:-$(dirname "$PLUGIN_DIR")/release-artifacts}"
BUILD_STAMP="$(date +%Y%m%d-%H%M%S)"
OUTPUT_FILE="${3:-${PLUGIN_NAME}-${BUILD_STAMP}.zip}"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

STAGE="$TMP_DIR/$PLUGIN_NAME"
mkdir -p "$STAGE"

rsync -a --delete \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude '.codex/' \
  --exclude 'node_modules/' \
  --exclude 'tests/' \
  --exclude 'scripts/' \
  --exclude '*.log' \
  --exclude '*.zip' \
  --exclude 'release-artifacts/' \
  --exclude '.DS_Store' \
  --exclude 'composer.lock' \
  --exclude 'package-lock.json' \
  "$PLUGIN_DIR/" "$STAGE/"

mkdir -p "$OUTPUT_DIR"
(
  cd "$TMP_DIR"
  rm -f "$OUTPUT_DIR/$OUTPUT_FILE"
  zip -rq "$OUTPUT_DIR/$OUTPUT_FILE" "$PLUGIN_NAME"
)

echo "Release package generated: $OUTPUT_DIR/$OUTPUT_FILE"
