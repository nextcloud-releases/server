#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Generate migration metadata file (NC30+).
# Installs Nextcloud to a temp directory, runs occ migrations:generate-metadata.
# Usage: generate-metadata.sh <nextcloud-dir> <version> <output-dir>

set -e

# Wrap the raw migration metadata JSON with build timestamps and write the
# final .metadata file. Reads env: METADATA_FILE, OUTPUT_FILE, BUILD_START,
# BUILD_DURATION. Kept as a function so it can be tested without occ/a database.
wrap_metadata() {
  php << 'EOPHP'
<?php
$raw = file_get_contents(getenv('METADATA_FILE'));
$meta = json_decode($raw, true) ?: [];
$meta['build'] = [
    'initiated' => (int)getenv('BUILD_START'),
    'duration' => (int)getenv('BUILD_DURATION'),
];
file_put_contents(
    getenv('OUTPUT_FILE'),
    json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
echo "Metadata file created\n";
EOPHP
}

# When sourced (tests), stop here after defining functions.
[[ "${BASH_SOURCE[0]}" != "${0}" ]] && return 0

NC="${1:?Usage: generate-metadata.sh <nextcloud-dir> <version> <output-dir>}"
VERSION="${2:?Missing version}"
OUTPUT_DIR="${3:?Missing output directory}"
START_TS=$(date +%s)

# Install to a temp copy to avoid polluting the release tree
INSTALL_DIR=$(mktemp -d)
cp -a "$NC" "$INSTALL_DIR/nextcloud"
chmod 755 "$INSTALL_DIR/nextcloud/occ"
chmod 777 "$INSTALL_DIR/nextcloud/config"

echo "Installing Nextcloud for metadata generation..."
php "$INSTALL_DIR/nextcloud/occ" maintenance:install \
  --admin-user admin --admin-pass admin 2>&1 || true

echo "Generating migration metadata..."
php "$INSTALL_DIR/nextcloud/occ" migrations:generate-metadata > /tmp/metadata-raw.json 2>/dev/null || true

if [ ! -s /tmp/metadata-raw.json ]; then
  echo "Warning: could not generate metadata"
  rm -rf "$INSTALL_DIR"
  exit 0
fi

NOW_TS=$(date +%s)
DURATION=$((NOW_TS - START_TS))
mkdir -p "$OUTPUT_DIR"

export METADATA_FILE="/tmp/metadata-raw.json"
export OUTPUT_FILE="$OUTPUT_DIR/nextcloud-${VERSION}.metadata"
export BUILD_START="$START_TS"
export BUILD_DURATION="$DURATION"

wrap_metadata

rm -rf "$INSTALL_DIR" /tmp/metadata-raw.json
