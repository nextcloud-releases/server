#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Snapshot test runner for the release build scripts (assemble.sh,
# clean-dev-files.sh, update-version-php.sh).
#
# These scripts only rearrange/clean a directory tree, so each scenario copies a
# fixture tree, runs the script, and snapshots the result:
#   - assemble / clean-dev-files -> a manifest (sorted relative file paths)
#   - update-version-php         -> the rewritten version.php, build date normalized
#
# Usage:
#   run.sh [--update]    # --update regenerates expected/ from actual output
#
# Each scenario is a directory under scenarios/ containing:
#   args.env     - SCRIPT (assemble|clean-dev-files|update-version-php) + extras
#   fixture/     - the input tree
#   expected/snapshot - the expected manifest or normalized version.php

set -euo pipefail

TEST_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(cd "$TEST_DIR/../.." && pwd)"
SCENARIOS_DIR="$TEST_DIR/scenarios"
SCRIPTS="$REPO_DIR/.github/scripts"

UPDATE_MODE=false
[[ "${1:-}" == "--update" ]] && UPDATE_MODE=true

PASS=0
FAIL=0
ERRORS=""

# Sorted list of files + symlinks relative to a directory.
manifest() {
	(cd "$1" && find . \( -type f -o -type l \) | LC_ALL=C sort)
}

run_scenario() {
	local dir="$1"
	local name
	name=$(basename "$dir")
	echo "--- ${name} ---"

	local SCRIPT CHANNEL BRANCH
	SCRIPT=""
	CHANNEL="stable"
	BRANCH="stable34"
	# shellcheck source=/dev/null
	source "$dir/args.env"

	local work snapshot
	work=$(mktemp -d)
	snapshot=$(mktemp)
	cp -a "$dir/fixture/." "$work/"

	case "$SCRIPT" in
		assemble)
			# assemble.sh `cp -a server $OUTPUT` expects OUTPUT not to exist yet.
			local outparent out
			outparent=$(mktemp -d)
			out="$outparent/nextcloud"
			bash "$SCRIPTS/assemble.sh" "$work" "$out" > /dev/null
			manifest "$out" > "$snapshot"
			rm -rf "$outparent"
			;;
		clean-dev-files)
			bash "$SCRIPTS/clean-dev-files.sh" "$work" > /dev/null
			manifest "$work" > "$snapshot"
			;;
		clean-server-dev-files)
			bash "$SCRIPTS/clean-server-dev-files.sh" "$work" > /dev/null
			manifest "$work" > "$snapshot"
			;;
		update-version-php)
			bash "$SCRIPTS/update-version-php.sh" "$work" "$CHANNEL" "$BRANCH" > /dev/null
			# Normalize the non-deterministic build timestamp (commit hash stays).
			sed -E "s/(\\\$OC_Build = ')[0-9T:+-]+ /\\1<DATE> /" "$work/version.php" > "$snapshot"
			;;
		*)
			echo "  FAIL: unknown SCRIPT '$SCRIPT'"
			FAIL=$((FAIL + 1)); ERRORS="${ERRORS}\n  ${name}: unknown SCRIPT"
			rm -rf "$work" "$snapshot"; return
			;;
	esac

	if [[ "$UPDATE_MODE" == "true" ]]; then
		mkdir -p "$dir/expected"
		cp "$snapshot" "$dir/expected/snapshot"
		echo "  UPDATED expected snapshot"
		PASS=$((PASS + 1))
	elif diff -u "$dir/expected/snapshot" "$snapshot"; then
		echo "  PASS"
		PASS=$((PASS + 1))
	else
		echo "  FAIL: snapshot differs"
		FAIL=$((FAIL + 1)); ERRORS="${ERRORS}\n  ${name}"
	fi

	rm -rf "$work" "$snapshot"
}

for scenario in "$SCENARIOS_DIR"/*/; do
	[[ -f "$scenario/args.env" ]] || continue
	run_scenario "$scenario"
done

echo ""
echo "=== Results: ${PASS} passed, ${FAIL} failed ==="
if [[ $FAIL -gt 0 ]]; then
	echo -e "Failures:${ERRORS}"
	exit 1
fi
