#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Snapshot test runner for update-updater-server.sh.
# Runs each scenario against base fixtures and compares output to expected files.
#
# Usage:
#   test-updater-script.sh [--update]    # --update regenerates expected files
#
# Each scenario is a directory under tests/updater-script/scenarios/ containing:
#   args.env   - TAG, INTERNAL_VERSION, and optional DEPLOY
#   expected/  - expected output files (config/, tests/)

set -euo pipefail

TEST_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(cd "$TEST_DIR/../.." && pwd)"
BASE_DIR="$TEST_DIR/base"
SCENARIOS_DIR="$TEST_DIR/scenarios"
SCRIPT="$REPO_DIR/.github/scripts/update-updater-server.sh"

UPDATE_MODE=false
[[ "${1:-}" == "--update" ]] && UPDATE_MODE=true

# Deterministic fake signatures: exactly 344 chars (RSA-2048 base64 output)
# 5 lines × 64 chars + 1 line × 24 chars = 344 total
# Generate deterministic 344-char fake sigs (matching RSA-2048 base64 length)
FAKE_BZ2=$(python3 -c "s='TestBZ2Sig'; print((s + '0' * (64 - len(s))) * 5 + s + '0' * (24 - len(s) - 2) + '==')")
FAKE_ZIP=$(python3 -c "s='TestZIPSig'; print((s + '0' * (64 - len(s))) * 5 + s + '0' * (24 - len(s) - 2) + '==')")

PASS=0
FAIL=0
ERRORS=""

run_scenario() {
	local scenario_dir="$1"
	local name
	name=$(basename "$scenario_dir")

	echo "--- ${name} ---"

	# Read scenario args
	local TAG INTERNAL_VERSION DEPLOY
	DEPLOY=""
	# shellcheck source=/dev/null
	source "$scenario_dir/args.env"

	# Copy base fixtures to temp dir
	local work_dir
	work_dir=$(mktemp -d)
	cp -r "$BASE_DIR"/* "$work_dir/"

	# Apply scenario-specific overrides on top of base (if any)
	if [[ -d "$scenario_dir/override" ]]; then
		cp -r "$scenario_dir/override/"* "$work_dir/"
		# Regenerate config.php from overridden JSON
		(cd "$work_dir" && make config/config.php > /dev/null 2>&1)
	fi

	# Initialize as git repo (script uses git diff)
	(cd "$work_dir" && git init -q \
		&& git config user.email "test@test" && git config user.name "test" \
		&& git add -A && git commit -q -m "base" --no-gpg-sign)

	# Build script args
	local args=("$TAG" "$FAKE_BZ2" "$FAKE_ZIP" --dry-run --repo-dir "$work_dir" --internal-version "$INTERNAL_VERSION")
	[[ -n "$DEPLOY" ]] && args+=(--deploy "$DEPLOY")

	# Run the script (suppress info output, capture errors)
	if ! bash "$SCRIPT" "${args[@]}" > /dev/null 2>&1; then
		echo "  FAIL: script exited non-zero"
		FAIL=$((FAIL + 1))
		ERRORS="${ERRORS}\n  ${name}: script exited non-zero"
		rm -rf "$work_dir"
		return
	fi

	# Regenerate config.php
	if ! (cd "$work_dir" && make config/config.php > /dev/null 2>&1); then
		echo "  FAIL: make config/config.php failed"
		FAIL=$((FAIL + 1))
		ERRORS="${ERRORS}\n  ${name}: make config/config.php failed"
		rm -rf "$work_dir"
		return
	fi

	if [[ "$UPDATE_MODE" == "true" ]]; then
		# Update expected files from actual output
		rm -rf "$scenario_dir/expected"
		mkdir -p "$scenario_dir/expected/config" "$scenario_dir/expected/tests/integration/features"
		cp "$work_dir/config/releases.json" "$scenario_dir/expected/config/"
		cp "$work_dir/config/config.php" "$scenario_dir/expected/config/"
		cp "$work_dir/config/major_versions.json" "$scenario_dir/expected/config/"
		cp "$work_dir/tests/integration/features/"*.feature "$scenario_dir/expected/tests/integration/features/"
		echo "  UPDATED expected files"
		PASS=$((PASS + 1))
	else
		# Compare against expected output
		local failed=false

		# Compare each expected file
		for expected_file in $(find "$scenario_dir/expected" -type f | sort); do
			local rel_path="${expected_file#"$scenario_dir/expected/"}"
			local actual_file="$work_dir/$rel_path"

			if [[ ! -f "$actual_file" ]]; then
				echo "  FAIL: missing $rel_path"
				failed=true
				continue
			fi

			if ! diff -q "$expected_file" "$actual_file" > /dev/null 2>&1; then
				echo "  FAIL: $rel_path differs"
				diff -u "$expected_file" "$actual_file" | head -30
				failed=true
			fi
		done

		if [[ "$failed" == "true" ]]; then
			FAIL=$((FAIL + 1))
			ERRORS="${ERRORS}\n  ${name}: output differs from expected"
		else
			echo "  PASS"
			PASS=$((PASS + 1))
		fi
	fi

	rm -rf "$work_dir"
}

# Run all scenarios
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
