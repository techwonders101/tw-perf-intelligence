#!/usr/bin/env bash
set -euo pipefail
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
OUTPUT="$PLUGIN_DIR/tw-performance.zip"
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
mkdir "$STAGE/tw-performance"
# Only runtime assets and public documentation belong in a release.
cp "$PLUGIN_DIR/tw-performance.php" "$PLUGIN_DIR/readme.txt" "$PLUGIN_DIR/CHANGELOG.md" "$STAGE/tw-performance/"
cp -R "$PLUGIN_DIR/includes" "$PLUGIN_DIR/admin" "$PLUGIN_DIR/assets" "$STAGE/tw-performance/"
(cd "$STAGE" && zip -qr -X "$STAGE/tw-performance.zip" tw-performance)
mv "$STAGE/tw-performance.zip" "$OUTPUT"
echo "Built: $OUTPUT"
