#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Assemble a Nextcloud release from fetched components.
# Usage: assemble.sh <build-dir> <output-dir>
#
# Expects the build dir to contain: server/, 3rdparty/, apps/*/, updater/, example-files/

set -e

BUILD="${1:?Usage: assemble.sh <build-dir> <output-dir>}"
OUTPUT="${2:?Missing output directory}"

# Server is the base
cp -a "$BUILD/server" "$OUTPUT"

# Replace 3rdparty
rm -rf "$OUTPUT/3rdparty"
cp -a "$BUILD/3rdparty" "$OUTPUT/3rdparty"

# Place apps
for app_dir in "$BUILD/apps"/*/; do
  id=$(basename "$app_dir")
  cp -a "$app_dir" "$OUTPUT/apps/$id"
done

# Updater: only index.php + updater.phar
mkdir -p "$OUTPUT/updater"
cp "$BUILD/updater/index.php" "$OUTPUT/updater/index.php"
cp "$BUILD/updater/updater.phar" "$OUTPUT/updater/updater.phar"

# Example files replace skeleton
rm -rf "$OUTPUT/core/skeleton"
cp -a "$BUILD/example-files" "$OUTPUT/core/skeleton"

# Docs (if fetched)
if [ -d "$BUILD/docs" ] && [ "$(ls -A "$BUILD/docs")" ]; then
  rm -f "$OUTPUT/core/doc/user/index.php" "$OUTPUT/core/doc/admin/index.php"
  [ -d "$BUILD/docs/user_manual/en" ] && cp -a "$BUILD/docs/user_manual/en/"* "$OUTPUT/core/doc/user/"
  [ -d "$BUILD/docs/admin_manual" ] && cp -a "$BUILD/docs/admin_manual/"* "$OUTPUT/core/doc/admin/"
  [ -f "$BUILD/docs/Nextcloud_User_Manual.pdf" ] && cp -a "$BUILD/docs/Nextcloud_User_Manual.pdf" "$OUTPUT/core/skeleton/Nextcloud Manual.pdf"
fi

# Final cleanup
find "$OUTPUT" -name .git -exec rm -rf {} + 2>/dev/null || true
rm -f "$OUTPUT/config/config.php"
rm -rf "$OUTPUT/data"

echo "Release assembled at $OUTPUT"
