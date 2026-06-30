#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# End-to-end live test of the updater_server bump (`updater:bump`) against a real
# updater_server checkout: it runs the bump, regenerates config.php via the
# updater_server Makefile, and runs updater_server's own Behat integration suite.
# This validates the layer BumpParityTest cannot - config.php (built by the
# Makefile) and the feature scenarios actually executing against it.
#
# The strongest positioning is to start from a release's REAL production
# pre-state: the parent of that release's merge commit. Resolve it with
#   gh api repos/nextcloud-releases/updater_server/pulls/<PR> --jq .merge_commit_sha
# then pass "<sha>^1" as --base.
#
# Usage:
#   tests/live-replay.sh --base <git-ref> [--min-php 8.2] [--repo <dir>] \
#       <tag>:<internalVersion> [<tag>:<internalVersion> ...]
#
# Example - replay the 34.0.1 RC1 batch on its real pre-state (PR #1387):
#   tests/live-replay.sh --base cb7ae67^1 --min-php 8.2 \
#       v34.0.1rc1:34.0.1.0 v33.0.6rc1:33.0.6.0 v32.0.12rc1:32.0.12.0
#
# Signatures are fake but consistent: Behat only needs config.php and the
# .feature signature blocks to agree, which the tool guarantees by construction.
set -uo pipefail

CONSOLE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/bin/console"
REPO_DIR="${UPDATER_SERVER_DIR:-/tmp/updater_server-live}"
BASE="master"
MIN_PHP="8.1"
FAKE='AAAA000000000000000000000000000000000000000000000000000000000000AAAA000000000000000000000000000000000000000000000000000000000000AAAA000000000000000000000000000000000000000000000000000000000000AAAA000000000000000000000000000000000000000000000000000000000000AAAA000000000000000000000000000000000000000000000000000000000000AAAA00000000000000=='
SPECS=()

while [ $# -gt 0 ]; do
  case "$1" in
    --base)    BASE="$2"; shift 2 ;;
    --min-php) MIN_PHP="$2"; shift 2 ;;
    --repo)    REPO_DIR="$2"; shift 2 ;;
    *)         SPECS+=("$1"); shift ;;
  esac
done
[ ${#SPECS[@]} -gt 0 ] || { echo "usage: $0 --base <ref> [--min-php X] <tag>:<internal> ..."; exit 2; }

if [ ! -d "$REPO_DIR/.git" ]; then
  echo ":: cloning updater_server -> $REPO_DIR"
  gh repo clone nextcloud-releases/updater_server "$REPO_DIR" -- --depth=300
fi
cd "$REPO_DIR"
git fetch --depth=300 origin "${BASE%%^*}" >/dev/null 2>&1 || true
git checkout -f "$BASE" >/dev/null 2>&1 || { echo "cannot checkout $BASE"; exit 1; }
git clean -fdq -e vendor
[ -x vendor/bin/behat ] || composer install --no-interaction --no-progress

for spec in "${SPECS[@]}"; do
  tag="${spec%%:*}"; internal="${spec##*:}"
  echo ":: bump $tag (internal $internal)"
  php "$CONSOLE" updater:bump "$tag" "$REPO_DIR" \
    --bz2-sig "$FAKE" --zip-sig "$FAKE" --internal-version "$internal" --min-php "$MIN_PHP"
done

echo ":: make config/config.php"
make config/config.php >/dev/null || { echo "make failed"; exit 1; }

echo ":: behat"
php -S localhost:8888 -t "$REPO_DIR" >/tmp/live-replay-server.log 2>&1 &
SRV=$!
sleep 1
( cd tests/integration && ../../vendor/bin/behat --colors . )
RC=$?
kill "$SRV" 2>/dev/null
exit "$RC"
