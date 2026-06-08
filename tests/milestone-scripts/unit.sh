#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Unit tests for update-milestones.sh: argument validation, due-date parsing,
# release-type routing, and the repo-list merge. These exercise behaviour that
# happens before (or independently of) the GitHub API, so they inject GH=true
# (a no-op gh) instead of the stateful fake-gh mock.
#
# Usage: bash unit.sh

set -uo pipefail

TEST_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(cd "$TEST_DIR/../.." && pwd)"
CONFIGS="$TEST_DIR/configs"
SCRIPT="$REPO_DIR/.github/scripts/update-milestones.sh"

PASS=0
FAIL=0

# Run the script with a no-op gh. Captures combined output in $OUT, exit in $RC.
run() {
	OUT=$(GH=true bash "$SCRIPT" "$@" 2>&1)
	RC=$?
}

ok() { echo "  ok: $1"; PASS=$((PASS + 1)); }
ko() { echo "  FAIL: $1"; FAIL=$((FAIL + 1)); }

assert_rc() { # desc expected actual
	if [[ "$2" == "$3" ]]; then ok "$1"; else ko "$1 (exit $3, expected $2)"; fi
}
assert_contains() { # desc needle haystack
	if [[ "$3" == *"$2"* ]]; then ok "$1"; else ko "$1 (missing: $2)"; fi
}
assert_rc_nonzero() { # desc actual
	if [[ "$2" -ne 0 ]]; then ok "$1"; else ko "$1 (expected non-zero)"; fi
}

echo "--- argument validation ---"
run
assert_rc_nonzero "missing all positional args fails" "$RC"

run v33.0.4 "$CONFIGS/stable33.json"
assert_rc_nonzero "missing tag-only arg fails" "$RC"

run v33.0.4 "$CONFIGS/stable33.json" "$CONFIGS/tag-only.json" --bogus
assert_rc "unknown flag exits 1" 1 "$RC"
assert_contains "unknown flag reports the option" "Unknown option: --bogus" "$OUT"

echo "--- due-date parsing ---"
run v33.0.4 "$CONFIGS/stable33.json" "$CONFIGS/tag-only.json" --next-due 2026/07/23
assert_rc "bad --next-due format exits 1" 1 "$RC"
assert_contains "bad --next-due reports error" "Invalid --next-due" "$OUT"

run v33.0.4 "$CONFIGS/stable33.json" "$CONFIGS/tag-only.json" --upcoming-due 2026/08/27
assert_rc "bad --upcoming-due format exits 1" 1 "$RC"
assert_contains "bad --upcoming-due reports error" "Invalid --upcoming-due" "$OUT"

run v33.0.4 "$CONFIGS/stable33.json" "$CONFIGS/tag-only.json" --next-due 2026-07-02 --upcoming-due 2026-08-27
assert_rc "valid due dates succeed" 0 "$RC"
assert_contains "next due echoed back" "Move issues to: Nextcloud 33.0.5 (due: 2026-07-02)" "$OUT"
assert_contains "upcoming due echoed back" "Create: Nextcloud 33.0.6 (due: 2026-08-27)" "$OUT"

echo "--- release-type routing ---"
run v35.0.0beta1 "$CONFIGS/stable33.json" "$CONFIGS/tag-only.json"
assert_contains "first beta routes to major-milestone creation" "First beta detected" "$OUT"

run v33.0.4 "$CONFIGS/stable33.json" "$CONFIGS/tag-only.json"
assert_contains "stable routes to close/create/move" "Stable release detected" "$OUT"

run v33.0.2rc1 "$CONFIGS/stable33.json" "$CONFIGS/tag-only.json"
assert_rc "non-first-beta pre-release is a no-op (exit 0)" 0 "$RC"
assert_contains "non-first-beta pre-release does nothing" "Nothing to do" "$OUT"

echo "--- repo-list merge (object config + string tag-only, deduped) ---"
run v33.0.4 "$CONFIGS/unit-config.json" "$CONFIGS/unit-tagonly.json"
assert_contains "merges both config formats into a sorted unique union of 3" "Repos: 3" "$OUT"

echo ""
echo "=== Unit results: ${PASS} passed, ${FAIL} failed ==="
[[ $FAIL -eq 0 ]]
