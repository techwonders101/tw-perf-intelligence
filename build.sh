#!/usr/bin/env bash
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_SLUG="tw-performance"
PARENT_DIR="$(dirname "$PLUGIN_DIR")"

VERSION=$(grep -m1 'Version:' "$PLUGIN_DIR/tw-performance.php" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
OUTPUT="$PLUGIN_DIR/${PLUGIN_SLUG}-${VERSION}.zip"

cd "$PARENT_DIR"

zip -r "$OUTPUT" "$PLUGIN_SLUG" \
    --exclude "*.sh" \
    --exclude "*/.git/*" \
    --exclude "*/.git" \
    --exclude "*/.*" \
    --exclude ".*" \
    --exclude "*.zip" \
    --exclude "__MACOSX/*" \
    --exclude "*.DS_Store"

echo "Built: $OUTPUT"
