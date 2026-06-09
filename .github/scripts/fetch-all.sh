#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Fetch all release components (server, 3rdparty, apps, updater, skeleton).
# Usage: fetch-all.sh <tag-or-branch> <config.json> <output-dir> [--docs]
#
# Examples:
#   fetch-all.sh v34.0.1 stable34.json /tmp/build
#   fetch-all.sh stable34 stable34.json /tmp/build --docs

set -e

REF="${1:?Usage: fetch-all.sh <ref> <config.json> <output-dir> [--docs]}"
CONFIG="${2:?Missing config.json path}"
OUTPUT="${3:?Missing output directory}"
FETCH_DOCS="${4:-}"

mkdir -p "$OUTPUT"

clone() {
  local repo="$1" ref="$2" dest="$3"
  echo "Fetching $repo@$ref → $dest"
  git clone --depth 1 --branch "$ref" "https://github.com/$repo.git" "$dest" -q 2>/dev/null \
    || git clone --depth 1 "https://github.com/$repo.git" "$dest" -q
}

# Server
clone "nextcloud/server" "$REF" "$OUTPUT/server"
git -C "$OUTPUT/server" rev-parse HEAD > "$OUTPUT/server/release-commit-hash"

# 3rdparty: clone at the same ref as server (matches the release script)
rm -rf "$OUTPUT/server/3rdparty"
clone "nextcloud/3rdparty" "$REF" "$OUTPUT/3rdparty"
rm -rf "$OUTPUT/3rdparty/.git" "$OUTPUT/3rdparty/.github" "$OUTPUT/3rdparty/.gitignore" "$OUTPUT/3rdparty/README.md"

# Updater (only 2 files needed)
UPDATER_TMP=$(mktemp -d)
clone "nextcloud/updater" "$REF" "$UPDATER_TMP"
mkdir -p "$OUTPUT/updater"
cp "$UPDATER_TMP/index.php" "$OUTPUT/updater/index.php"
cp "$UPDATER_TMP/updater.phar" "$OUTPUT/updater/updater.phar"
rm -rf "$UPDATER_TMP"

# Example files (becomes core/skeleton)
clone "nextcloud/example-files" "$REF" "$OUTPUT/example-files"
rm -rf "$OUTPUT/example-files/.git"

# Apps from JSON config
jq -r '.[] | "\(.id) \(.repo) \(.composer_args // "")"' "$CONFIG" | while read -r id repo composer_args; do
  clone "$repo" "$REF" "$OUTPUT/apps/$id"
  rm -rf "$OUTPUT/apps/$id/.git"

  # Run composer if there are real non-dev dependencies
  if [ -f "$OUTPUT/apps/$id/composer.json" ] && jq -e '
    .require // {} | to_entries
    | map(select(
      .key != "php"
      and (.key | startswith("ext-") | not)
      and (.key | startswith("bamarni/") | not)
    ))
    | length > 0
  ' "$OUTPUT/apps/$id/composer.json" > /dev/null 2>&1; then
    echo "  Running composer install for $id"
    read -r -a ARGS <<< "${composer_args:---no-dev -a --quiet}"
    (cd "$OUTPUT/apps/$id" && COMPOSER_ALLOW_SUPERUSER=1 composer install "${ARGS[@]}")
  fi
done

# Docs (optional)
if [ "$FETCH_DOCS" = "--docs" ]; then
  MAJOR=$(echo "$REF" | sed 's/^v//;s/\..*//')
  DOCS_TMP=$(mktemp -d)
  git clone --depth 1 --branch gh-pages "https://github.com/nextcloud/documentation.git" "$DOCS_TMP" -q 2>/dev/null || true

  if [ -d "$DOCS_TMP" ]; then
    mkdir -p "$OUTPUT/docs/user_manual/en" "$OUTPUT/docs/admin_manual"
    for base in "server/${MAJOR}" "server/stable${MAJOR}" "${MAJOR}" "."; do
      if [ -d "$DOCS_TMP/${base}/user_manual/en" ]; then
        cp -a "$DOCS_TMP/${base}/user_manual/en/"* "$OUTPUT/docs/user_manual/en/"
        break
      fi
    done
    for base in "server/${MAJOR}" "server/stable${MAJOR}" "${MAJOR}" "."; do
      if [ -d "$DOCS_TMP/${base}/admin_manual" ]; then
        cp -a "$DOCS_TMP/${base}/admin_manual/"* "$OUTPUT/docs/admin_manual/"
        break
      fi
    done
    find "$DOCS_TMP" -name "Nextcloud_User_Manual.pdf" -exec cp -a {} "$OUTPUT/docs/" \; 2>/dev/null
    rm -rf "$DOCS_TMP"
  fi
fi

echo "All components fetched to $OUTPUT"
