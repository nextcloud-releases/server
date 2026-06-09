#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Set permissions, create archives, and generate checksums.
# Usage: package.sh <nextcloud-dir> <version> <output-dir>
#
# Creates: nextcloud-VERSION.tar.bz2, .zip, .sha256, .sha512, .md5

set -e

# Generate sha256/sha512/md5 sidecars for the release archives
# (nextcloud-<version>.{tar.bz2,zip}) in <output-dir>.
generate_checksums() {
  local output="$1" version="$2" file
  for file in "nextcloud-${version}.tar.bz2" "nextcloud-${version}.zip"; do
    [ -f "$output/$file" ] || continue
    ( cd "$output" \
      && sha256sum "$file" > "${file}.sha256" \
      && sha512sum "$file" > "${file}.sha512" \
      && md5sum "$file" > "${file}.md5" )
  done
}

# When sourced (tests), stop here after defining functions.
[[ "${BASH_SOURCE[0]}" != "${0}" ]] && return 0

NC="${1:?Usage: package.sh <nextcloud-dir> <version> <output-dir>}"
VERSION="${2:?Missing version}"
OUTPUT="${3:?Missing output directory}"

mkdir -p "$OUTPUT"

# Match release script: dirs 755, files 644, owner nobody:nogroup
echo "Setting permissions..."
find "$NC" -type d -exec chmod 755 {} \;
find "$NC" -type f -exec chmod 644 {} \;
sudo chown -R nobody:nogroup "$NC"

# Create archives from parent directory
PARENT=$(dirname "$NC")
NAME=$(basename "$NC")

echo "Creating tar.bz2..."
(cd "$PARENT" && sudo tar jcf "$OUTPUT/nextcloud-${VERSION}.tar.bz2" "$NAME" --format=gnu)

echo "Creating zip..."
(cd "$PARENT" && sudo zip -rq9 "$OUTPUT/nextcloud-${VERSION}.zip" "$NAME")

# Generate checksums
echo "Generating checksums..."
generate_checksums "$OUTPUT" "$VERSION"

echo "Packages created:"
ls -lh "$OUTPUT/nextcloud-${VERSION}"*
