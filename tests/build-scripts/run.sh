#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Snapshot test runner for the release build scripts (assemble.sh,
# apply-nextcloudignore.sh, clean-dev-files.sh, update-version-php.sh).
#
# These scripts only rearrange/clean a directory tree, so each scenario copies a
# fixture tree, runs the script, and snapshots the result:
#   - assemble / clean-dev-files / apply-nextcloudignore -> a manifest (sorted
#     relative file paths); clean-dev-files also snapshots its removal report
#     when expected/report exists
#   - update-version-php         -> the rewritten version.php, build date normalized
#
# Usage:
#   run.sh [--update]    # --update regenerates expected/ from actual output
#
# Each scenario is a directory under scenarios/ containing:
#   args.env     - SCRIPT (assemble|apply-nextcloudignore|clean-dev-files|update-version-php) + extras
#   fixture/     - the input tree (apply-nextcloudignore: tracked by a fresh git
#                  repo, except paths listed in UNTRACKED)
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

	local SCRIPT CHANNEL BRANCH UNTRACKED
	SCRIPT=""
	UNTRACKED=""
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
		apply-nextcloudignore)
			# Fixtures cannot ship a .git, so track the tree here. UNTRACKED
			# paths stand in for composer output, which is never committed.
			git -C "$work" init -q
			git -C "$work" add -A
			local path
			for path in $UNTRACKED; do
				git -C "$work" rm -q --cached -- "$path"
			done
			bash "$SCRIPTS/apply-nextcloudignore.sh" "$work" > /dev/null
			rm -rf "$work/.git"
			manifest "$work" > "$snapshot"
			;;
		clean-dev-files)
			local report
			report=$(mktemp)
			DEV_FILES_REPORT="$report" bash "$SCRIPTS/clean-dev-files.sh" "$work" > /dev/null
			manifest "$work" > "$snapshot"
			if [[ -f "$dir/expected/report" ]] || [[ "$UPDATE_MODE" == "true" && -s "$report" ]]; then
				# The report names the directory; fixtures run under a temp name.
				sed "s/^$(basename "$work")\t/app\t/" "$report" > "$snapshot.report"
			fi
			rm -f "$report"
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
		[[ -f "$snapshot.report" ]] && cp "$snapshot.report" "$dir/expected/report"
		echo "  UPDATED expected snapshot"
		PASS=$((PASS + 1))
	elif diff -u "$dir/expected/snapshot" "$snapshot" \
		&& { [[ ! -f "$dir/expected/report" ]] || diff -u "$dir/expected/report" "$snapshot.report"; }; then
		echo "  PASS"
		PASS=$((PASS + 1))
	else
		echo "  FAIL: snapshot differs"
		FAIL=$((FAIL + 1)); ERRORS="${ERRORS}\n  ${name}"
	fi

	rm -rf "$work" "$snapshot" "$snapshot.report"
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
