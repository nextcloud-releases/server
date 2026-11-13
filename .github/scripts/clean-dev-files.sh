#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Remove development files from an app or component directory, using the
# patterns in dev-files.txt. The app's own .nextcloudignore is applied earlier
# by apply-nextcloudignore.sh, so anything this still removes is something the
# app's .nextcloudignore should list.
#
# Usage: clean-dev-files.sh <directory>
# With DEV_FILES_REPORT set, appends "<dir name><TAB>/<path>" for each removal.

set -e

LIST="$(cd "$(dirname "$0")" && pwd)/dev-files.txt"
DIR="${1:-.}"
cd "$DIR"
APP=$(basename "$(pwd)")

remove() {
  [ -e "$1" ] || [ -L "$1" ] || return 0
  rm -rf "$1"
  # An app cannot be expected to list its .nextcloudignore in itself.
  if [ -n "${DEV_FILES_REPORT:-}" ] && [ "$1" != .nextcloudignore ]; then
    printf '%s\t/%s\n' "$APP" "$1" >> "$DEV_FILES_REPORT"
  fi
}

shopt -s nullglob
while IFS= read -r pattern || [ -n "$pattern" ]; do
  [[ -z "$pattern" || "$pattern" == \#* ]] && continue
  pattern="${pattern#/}"
  # Unquoted on purpose: the list holds shell globs.
  # shellcheck disable=SC2206
  matches=($pattern)
  for match in "${matches[@]}"; do
    remove "$match"
    [[ "$match" == *.license ]] || remove "${match}.license"
  done
done < "$LIST"
shopt -u nullglob

# Remove REUSE sidecar files for sourcemaps. Sourcemaps (*.map) ship in the
# release, but their *.map.license sidecars are REUSE metadata that only matters
# in source repos, pure clutter in the tarball.
find . -name '*.map.license' \( -type f -o -type l \) -delete
