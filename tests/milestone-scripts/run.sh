#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Snapshot test runner for update-milestones.sh and audit-milestones.sh.
#
# The milestone scripts produce no files - they only call `gh api`. So each
# scenario injects a stateful fake gh (fake-gh.sh) and asserts on:
#   - journal.txt : the sequence of mutating API calls (create/close/move)
#   - stdout.txt  : the script's combined stdout/stderr
#   - exit        : the script's exit code
# whichever of those the scenario's expected/ directory contains.
#
# Usage:
#   run.sh [--update]    # --update regenerates expected/ from actual output
#
# Each scenario is a directory under scenarios/ containing:
#   args.env     - SCRIPT (update|audit), TAG, CONFIG, TAGONLY, EXTRA
#   fixture.json - initial gh state (see fake-gh.sh for the shape)
#   expected/    - journal.txt / stdout.txt / exit (any subset)

set -euo pipefail

TEST_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(cd "$TEST_DIR/../.." && pwd)"
SCENARIOS_DIR="$TEST_DIR/scenarios"
CONFIGS="$TEST_DIR/configs"
FAKE_GH="$TEST_DIR/fake-gh.sh"
UPDATE_SCRIPT="$REPO_DIR/.github/scripts/update-milestones.sh"
AUDIT_SCRIPT="$REPO_DIR/.github/scripts/audit-milestones.sh"

UPDATE_MODE=false
[[ "${1:-}" == "--update" ]] && UPDATE_MODE=true

PASS=0
FAIL=0
ERRORS=""

run_scenario() {
	local dir="$1"
	local name
	name=$(basename "$dir")
	echo "--- ${name} ---"

	# Scenario args (with defaults)
	local SCRIPT TAG CONFIG TAGONLY EXTRA
	SCRIPT="update"
	TAG=""
	CONFIG="stable33.json"
	TAGONLY="tag-only.json"
	EXTRA=""
	# shellcheck source=/dev/null
	source "$dir/args.env"

	local state journal stdout_f rc
	state=$(mktemp)
	journal=$(mktemp)
	stdout_f=$(mktemp)
	cp "$dir/fixture.json" "$state"
	: > "$journal"

	set +e
	if [[ "$SCRIPT" == "audit" ]]; then
		GH="$FAKE_GH" GH_STATE="$state" GH_JOURNAL="$journal" \
			bash "$AUDIT_SCRIPT" "$CONFIGS/$CONFIG" "$CONFIGS/$TAGONLY" > "$stdout_f" 2>&1
	else
		# shellcheck disable=SC2086  # EXTRA intentionally word-splits into flags
		GH="$FAKE_GH" GH_STATE="$state" GH_JOURNAL="$journal" \
			bash "$UPDATE_SCRIPT" "$TAG" "$CONFIGS/$CONFIG" "$CONFIGS/$TAGONLY" $EXTRA > "$stdout_f" 2>&1
	fi
	rc=$?
	set -e

	if [[ "$UPDATE_MODE" == "true" ]]; then
		mkdir -p "$dir/expected"
		cp "$journal" "$dir/expected/journal.txt"
		cp "$stdout_f" "$dir/expected/stdout.txt"
		echo "$rc" > "$dir/expected/exit"
		echo "  UPDATED expected files"
		PASS=$((PASS + 1))
		rm -f "$state" "$journal" "$stdout_f"
		return
	fi

	local failed=false
	if [[ -f "$dir/expected/journal.txt" ]] && ! diff -u "$dir/expected/journal.txt" "$journal"; then
		echo "  FAIL: journal differs"
		failed=true
	fi
	if [[ -f "$dir/expected/stdout.txt" ]] && ! diff -u "$dir/expected/stdout.txt" "$stdout_f"; then
		echo "  FAIL: stdout differs"
		failed=true
	fi
	if [[ -f "$dir/expected/exit" ]] && [[ "$(cat "$dir/expected/exit")" != "$rc" ]]; then
		echo "  FAIL: exit code ${rc}, expected $(cat "$dir/expected/exit")"
		failed=true
	fi

	if [[ "$failed" == "true" ]]; then
		FAIL=$((FAIL + 1))
		ERRORS="${ERRORS}\n  ${name}"
	else
		echo "  PASS"
		PASS=$((PASS + 1))
	fi

	rm -f "$state" "$journal" "$stdout_f"
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
