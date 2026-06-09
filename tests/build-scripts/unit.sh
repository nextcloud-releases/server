#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Unit tests for the pure helpers extracted from the heavy build scripts
# (package.sh, sign-release.sh, generate-metadata.sh). Each script can be
# sourced without running its main body, so we call the helpers directly -
# no sudo, tar/zip, occ or database needed.
#
# Usage: bash unit.sh

# The single-quoted `php -r` snippets below use PHP variables ($m, $argv),
# not shell parameters - no shell expansion is wanted.
# shellcheck disable=SC2016
set -uo pipefail

TEST_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(cd "$TEST_DIR/../.." && pwd)"
SCRIPTS="$REPO_DIR/.github/scripts"

PASS=0
FAIL=0
ok() { echo "  ok: $1"; PASS=$((PASS + 1)); }
ko() { echo "  FAIL: $1"; FAIL=$((FAIL + 1)); }
eq() { if [[ "$2" == "$3" ]]; then ok "$1"; else ko "$1 (got '$2', want '$3')"; fi; }

echo "--- package.sh: generate_checksums ---"
(
	set +e
	# shellcheck source=/dev/null
	source "$SCRIPTS/package.sh"
	work=$(mktemp -d)
	echo "archive-bz2" > "$work/nextcloud-9.9.9.tar.bz2"
	echo "archive-zip" > "$work/nextcloud-9.9.9.zip"
	generate_checksums "$work" "9.9.9"
	rc=0
	for ext in sha256 sha512 md5; do
		for base in tar.bz2 zip; do
			f="$work/nextcloud-9.9.9.${base}.${ext}"
			[[ -f "$f" ]] || { echo "  FAIL: missing $f"; rc=1; }
		done
	done
	# the recorded sums must verify
	( cd "$work" && sha256sum -c nextcloud-9.9.9.tar.bz2.sha256 >/dev/null 2>&1 ) || { echo "  FAIL: sha256 does not verify"; rc=1; }
	[[ $rc -eq 0 ]] && echo "  ok: checksums written and verify" || true
	exit $rc
) && PASS=$((PASS + 1)) || FAIL=$((FAIL + 1))

echo "--- sign-release.sh: default_cert ---"
(
	set +e
	# shellcheck source=/dev/null
	source "$SCRIPTS/sign-release.sh"
	a=$(default_cert "/tmp/nc")
	b=$(default_cert "/tmp/nc" "/custom/my.crt")
	[[ "$a" == "/tmp/nc/resources/codesigning/core.crt" ]] || { echo "  FAIL: default path wrong: $a"; exit 1; }
	[[ "$b" == "/custom/my.crt" ]] || { echo "  FAIL: explicit cert wrong: $b"; exit 1; }
	echo "  ok: cert defaulting"
) && PASS=$((PASS + 1)) || FAIL=$((FAIL + 1))

echo "--- generate-metadata.sh: wrap_metadata ---"
(
	set +e
	# shellcheck source=/dev/null
	source "$SCRIPTS/generate-metadata.sh"
	work=$(mktemp -d)
	echo '{"migrations":["a","b"]}' > "$work/raw.json"
	export METADATA_FILE="$work/raw.json"
	export OUTPUT_FILE="$work/out.metadata"
	export BUILD_START=1000
	export BUILD_DURATION=42
	wrap_metadata >/dev/null
	init=$(php -r '$m=json_decode(file_get_contents($argv[1]),true); echo $m["build"]["initiated"];' "$work/out.metadata")
	dur=$(php -r '$m=json_decode(file_get_contents($argv[1]),true); echo $m["build"]["duration"];' "$work/out.metadata")
	mig=$(php -r '$m=json_decode(file_get_contents($argv[1]),true); echo count($m["migrations"]);' "$work/out.metadata")
	[[ "$init" == "1000" ]] || { echo "  FAIL: build.initiated=$init"; exit 1; }
	[[ "$dur" == "42" ]] || { echo "  FAIL: build.duration=$dur"; exit 1; }
	[[ "$mig" == "2" ]] || { echo "  FAIL: migrations preserved=$mig"; exit 1; }
	echo "  ok: build stamps added, payload preserved"
) && PASS=$((PASS + 1)) || FAIL=$((FAIL + 1))

echo ""
echo "=== Unit results: ${PASS} passed, ${FAIL} failed ==="
[[ $FAIL -eq 0 ]]
