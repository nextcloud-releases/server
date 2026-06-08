#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Update the updater server with a new Nextcloud release.
# Creates a PR with: releases.json, config.php, and feature file updates.
#
# Usage:
#   update-updater-server.sh <tag> <bz2-sig> <zip-sig> [options]
#
# Options:
#   --deploy <N>              Deploy percentage (auto: .0.0=30%, .0.1=70%, else 100%)
#   --internal-version X.Y.Z.N  Skip version.php fetch, use this internal version
#   --dry-run         Show diff without creating a PR
#   --repo-dir <dir>  Use existing updater_server checkout instead of cloning
#
# Examples:
#   # Patch release
#   update-updater-server.sh v33.0.6 "$(cat bz2.sig)" "$(cat zip.sig)"
#
#   # RC with dry-run
#   update-updater-server.sh v34.0.0rc6 "$BZ2_SIG" "$ZIP_SIG" --dry-run
#
#   # First stable (auto-deploys at 30%)
#   update-updater-server.sh v34.0.0 "$BZ2_SIG" "$ZIP_SIG"
#
#   # Override auto-deploy
#   update-updater-server.sh v34.0.0 "$BZ2_SIG" "$ZIP_SIG" --deploy 50

set -euo pipefail

# ─── Constants ────────────────────────────────────────────────────────────────

UPDATER_REPO="nextcloud-releases/updater_server"
FEATURES_DIR="tests/integration/features"

# ─── Helpers ──────────────────────────────────────────────────────────────────

die()  { echo "::error::$*" >&2; exit 1; }
info() { echo "::notice::$*"; }
warn() { echo "::warning::$*"; }

# Wrap a base64 signature to 64-character lines (matching feature file format)
wrap_sig() { echo "$1" | fold -w 64; }

# Replace old zip signature with new one in feature files.
# Signatures are high-entropy base64, so each 64-char line is unique.
replace_signature() {
	local old_sig="$1"
	local new_sig="$2"
	shift 2
	local files=("$@")

	local old_lines new_lines
	mapfile -t old_lines <<< "$(wrap_sig "$old_sig")"
	mapfile -t new_lines <<< "$(wrap_sig "$new_sig")"

	for i in "${!old_lines[@]}"; do
		[[ -z "${old_lines[$i]}" ]] && continue
		[[ -z "${new_lines[$i]+x}" ]] && continue
		# base64 chars (A-Za-z0-9+/=) are safe in sed with | delimiter
		sed -i "s|${old_lines[$i]}|${new_lines[$i]}|g" "${files[@]}"
	done
}

# ─── Parse arguments ─────────────────────────────────────────────────────────

TAG="${1:?Usage: update-updater-server.sh <tag> <bz2-sig> <zip-sig> [--deploy N] [--dry-run] [--repo-dir dir] [--internal-version X.Y.Z.N]}"
BZ2_SIG="${2:?Missing bz2 signature}"
ZIP_SIG="${3:?Missing zip signature}"
shift 3

DRY_RUN=false
DEPLOY=""
REPO_DIR=""
OVERRIDE_INTERNAL=""

while [[ $# -gt 0 ]]; do
	case "$1" in
		--dry-run)            DRY_RUN=true; shift ;;
		--deploy)             DEPLOY="${2:?--deploy requires a number}"; shift 2 ;;
		--repo-dir)           REPO_DIR="${2:?--repo-dir requires a path}"; shift 2 ;;
		--internal-version)   OVERRIDE_INTERNAL="${2:?--internal-version requires X.Y.Z.N}"; shift 2 ;;
		*) die "Unknown option: $1" ;;
	esac
done

# ─── Parse tag ────────────────────────────────────────────────────────────────

VERSION="${TAG#v}"
MAJOR=$(echo "$VERSION" | grep -oP '^\d+')
MINOR=$(echo "$VERSION" | cut -d. -f2)
PATCH=$(echo "$VERSION" | cut -d. -f3 | grep -oP '^\d+')

# Extract modifier (rc6, beta1, alpha2, etc.) and build display/URL versions
MODIFIER=$(echo "$VERSION" | grep -oiP '(rc|beta|alpha)\d+$' || true)

if [[ -n "$MODIFIER" ]]; then
	# RC/beta/alpha release
	# RC: uppercase, no space (RC5). beta/alpha: lowercase, space before number (beta 5).
	if [[ "$MODIFIER" =~ ^[Rr][Cc] ]]; then
		MODIFIER_DISPLAY=$(echo "$MODIFIER" | tr '[:lower:]' '[:upper:]')
	else
		MODIFIER_DISPLAY=$(echo "$MODIFIER" | sed -E 's/([a-zA-Z]+)([0-9]+)/\1 \2/')
	fi
	VERSION_STRING="${MAJOR}.${MINOR}.${PATCH} ${MODIFIER_DISPLAY}"
	URL_VERSION="${MAJOR}.${MINOR}.${PATCH}$(echo "$MODIFIER" | tr '[:upper:]' '[:lower:]')"
	URL_DIR="prereleases"
	STABILITY="beta"
elif [[ "$PATCH" -eq 0 && "$MINOR" -eq 0 ]]; then
	# First stable of a new major (e.g., v34.0.0)
	VERSION_STRING="${MAJOR}.${MINOR}.${PATCH}"
	URL_VERSION="${MAJOR}.${MINOR}.${PATCH}"
	URL_DIR="releases"
	STABILITY="stable"
	RELEASE_TYPE="first_stable"
else
	# Regular patch release (e.g., v33.0.6)
	VERSION_STRING="${MAJOR}.${MINOR}.${PATCH}"
	URL_VERSION="${MAJOR}.${MINOR}.${PATCH}"
	URL_DIR="releases"
	STABILITY="stable"
fi

# Determine release type if not already set
if [[ -z "${RELEASE_TYPE:-}" ]]; then
	if [[ -n "$MODIFIER" ]]; then
		RELEASE_TYPE="prerelease"
	else
		RELEASE_TYPE="patch"
	fi
fi

CHANNEL="$STABILITY"
[[ "$RELEASE_TYPE" == "first_stable" ]] && CHANNEL="stable"

# Auto-calculate deploy percentage if not explicitly set.
# Pattern: X.0.0 = 30%, X.0.1 = 70%, X.0.2+ = 100%
if [[ -z "$DEPLOY" ]]; then
	if [[ "$RELEASE_TYPE" == "first_stable" ]]; then
		DEPLOY=30
	elif [[ "$STABILITY" == "stable" && "$PATCH" -eq 1 && "$MINOR" -eq 0 ]]; then
		DEPLOY=70
	else
		DEPLOY=100
	fi
	info "Auto-calculated deploy: ${DEPLOY}%"
fi

info "Release: ${VERSION_STRING} (type=${RELEASE_TYPE}, stability=${STABILITY}, deploy=${DEPLOY}%)"

# ─── Fetch internal version from version.php ──────────────────────────────────

if [[ -n "$OVERRIDE_INTERNAL" ]]; then
	INTERNAL_VERSION="$OVERRIDE_INTERNAL"
	VERSION_PHP=""
	info "Using provided internal version: ${INTERNAL_VERSION}"
else
	info "Fetching internal version from nextcloud/server@${TAG}..."

	VERSION_PHP=$(curl -sf "https://raw.githubusercontent.com/nextcloud/server/refs/tags/${TAG}/version.php" || true)
	if [[ -z "$VERSION_PHP" ]]; then
		# Tag might not exist yet, try the branch
		BRANCH=$([[ "$STABILITY" == "beta" && "$PATCH" -eq 0 ]] && echo "master" || echo "stable${MAJOR}")
		VERSION_PHP=$(curl -sf "https://raw.githubusercontent.com/nextcloud/server/refs/heads/${BRANCH}/version.php" || true)
	fi
	[[ -z "$VERSION_PHP" ]] && die "Could not fetch version.php from nextcloud/server"

	# $OC_Version is a literal PHP variable name, not a shell expansion
	# shellcheck disable=SC2016
	INTERNAL_VERSION=$(echo "$VERSION_PHP" | grep -oP '\$OC_Version\s*=\s*\[\K[0-9, ]+' | tr -d ' ' | tr ',' '.')
	[[ -z "$INTERNAL_VERSION" ]] && die "Could not parse OC_Version from version.php"

	info "Internal version: ${INTERNAL_VERSION}"
fi

# ─── Fetch minPHP (needed for new major entries) ─────────────────────────────

fetch_min_php() {
	# Try versioncheck.php directly
	local versioncheck
	versioncheck=$(curl -sf "https://raw.githubusercontent.com/nextcloud/server/refs/tags/${TAG}/lib/versioncheck.php" || true)
	if [[ -z "$versioncheck" ]]; then
		local branch
		branch=$([[ "$STABILITY" == "beta" && "$PATCH" -eq 0 ]] && echo "master" || echo "stable${MAJOR}")
		versioncheck=$(curl -sf "https://raw.githubusercontent.com/nextcloud/server/refs/heads/${branch}/lib/versioncheck.php" || true)
	fi

	if [[ -n "$versioncheck" ]]; then
		local min_id
		min_id=$(echo "$versioncheck" | grep -oP 'PHP_VERSION_ID < \K\d+' | head -1)
		if [[ -n "$min_id" ]]; then
			local min_minor=$(( (min_id / 100) % 100 ))
			echo "8.${min_minor}"
			return
		fi
	fi
	echo "8.1"
}

# ─── Set up working directory ─────────────────────────────────────────────────

if [[ -n "$REPO_DIR" ]]; then
	WORK_DIR="$REPO_DIR"
	info "Using existing checkout: ${WORK_DIR}"
else
	WORK_DIR=$(mktemp -d)
	trap 'rm -rf "$WORK_DIR"' EXIT
	info "Cloning ${UPDATER_REPO} to ${WORK_DIR}..."
	gh repo clone "$UPDATER_REPO" "$WORK_DIR" -- --depth=1
fi

cd "$WORK_DIR"

# ─── Read old state from releases.json ────────────────────────────────────────

RELEASES_JSON="config/releases.json"
MAJOR_VERSIONS_JSON="config/major_versions.json"

# Find the entry this release replaces, based on major version + stability.
# Patch/first_stable: look for the stable entry of this major
# Prerelease: look for the beta entry (RC/beta/alpha) of this major
find_old_key() {
	local major="$1"
	local type="$2"

	if [[ "$type" == "prerelease" ]]; then
		# Find existing beta/RC/alpha entry for this major
		jq -r --arg m "$major" \
			'to_entries[] | select(.key | test("^" + $m + "\\.") and test("[Rr][Cc]|[Bb]eta|[Aa]lpha")) | .key' \
			"$RELEASES_JSON" | tail -1
	elif [[ "$type" == "first_stable" ]]; then
		# First stable replaces the last RC/beta
		jq -r --arg m "$major" \
			'to_entries[] | select(.key | test("^" + $m + "\\.") and test("[Rr][Cc]|[Bb]eta|[Aa]lpha")) | .key' \
			"$RELEASES_JSON" | tail -1
	else
		# Patch release replaces the current stable entry for this major
		jq -r --arg m "$major" \
			'to_entries[] | select(.key | test("^" + $m + "\\.") and (test("[Rr][Cc]|[Bb]eta|[Aa]lpha") | not) and (test("Enterprise") | not)) | .key' \
			"$RELEASES_JSON" | tail -1
	fi
}

OLD_KEY=$(find_old_key "$MAJOR" "$RELEASE_TYPE")

if [[ -z "$OLD_KEY" ]]; then
	if [[ "$RELEASE_TYPE" == "prerelease" ]]; then
		# First pre-release of a new major — no old entry to replace
		RELEASE_TYPE="first_prerelease"
		info "First pre-release for major ${MAJOR} — will add new entry"
	else
		die "No existing entry found for major ${MAJOR} (type=${RELEASE_TYPE}) in releases.json"
	fi
fi

# Extract old values (empty for first_prerelease)
OLD_INTERNAL=""
OLD_ZIP_SIG=""
OLD_URL_VERSION=""
OLD_VERSION_STRING=""

if [[ -n "$OLD_KEY" ]]; then
	OLD_INTERNAL=$(jq -r --arg k "$OLD_KEY" '.[$k].internalVersion' "$RELEASES_JSON")

	# Get zip signature (new format with signatures.zip or old format with signature)
	OLD_ZIP_SIG=$(jq -r --arg k "$OLD_KEY" '.[$k].signatures.zip // .[$k].signature // ""' "$RELEASES_JSON")

	# Derive URL version from old key: "34.0.0 RC5" → "34.0.0rc5", "33.0.5" → "33.0.5"
	OLD_VERSION_STRING="$OLD_KEY"
	OLD_URL_VERSION=$(echo "$OLD_KEY" | sed 's/ //g' | tr '[:upper:]' '[:lower:]')

	info "Replacing: ${OLD_KEY} (internal: ${OLD_INTERNAL})"
fi

# ─── Update releases.json ────────────────────────────────────────────────────

info "Updating releases.json..."

# Build the new entry
NEW_ENTRY=$(jq -n \
	--arg iv "$INTERNAL_VERSION" \
	--arg bz2 "$BZ2_SIG" \
	--arg zip "$ZIP_SIG" \
	--argjson deploy "$DEPLOY" \
	'{ internalVersion: $iv, signatures: { bz2: $bz2, zip: $zip } }
	 | if $deploy != 100 then . + { deploy: $deploy } else . end')

if [[ -n "$OLD_KEY" ]]; then
	# Replace old entry with new one
	jq --arg old "$OLD_KEY" --arg new "$VERSION_STRING" --argjson entry "$NEW_ENTRY" \
		'del(.[$old]) | . + {($new): $entry}' \
		"$RELEASES_JSON" > "${RELEASES_JSON}.tmp"
else
	# Add new entry (first prerelease)
	jq --arg new "$VERSION_STRING" --argjson entry "$NEW_ENTRY" \
		'. + {($new): $entry}' \
		"$RELEASES_JSON" > "${RELEASES_JSON}.tmp"
fi

mv "${RELEASES_JSON}.tmp" "$RELEASES_JSON"

# ─── Update major_versions.json (for new majors) ─────────────────────────────

if [[ "$RELEASE_TYPE" == "first_prerelease" ]]; then
	EXISTING_MAJOR=$(jq -r --arg m "$MAJOR" 'has($m) | tostring' "$MAJOR_VERSIONS_JSON")
	if [[ "$EXISTING_MAJOR" != "true" ]]; then
		MIN_PHP=$(fetch_min_php)
		info "Adding major ${MAJOR} to major_versions.json (minPHP: ${MIN_PHP})"
		jq --arg m "$MAJOR" --arg php "$MIN_PHP" \
			'{($m): {minPHP: $php}} + .' \
			"$MAJOR_VERSIONS_JSON" > "${MAJOR_VERSIONS_JSON}.tmp"
		mv "${MAJOR_VERSIONS_JSON}.tmp" "$MAJOR_VERSIONS_JSON"
	fi
fi

# ─── Regenerate config.php ────────────────────────────────────────────────────

info "Regenerating config.php..."
if ! command -v php &>/dev/null; then
	die "PHP is required to regenerate config.php"
fi
make config/config.php

# ─── Update feature files ────────────────────────────────────────────────────

STABLE_FEATURE="${FEATURES_DIR}/stable.feature"
BETA_FEATURE="${FEATURES_DIR}/beta.feature"
LATEST_FEATURE="${FEATURES_DIR}/latest.feature"

info "Updating feature files (type: ${RELEASE_TYPE})..."

update_features_patch() {
	# Patch release: replace version strings and signatures in both channels

	# URLs: nextcloud-33.0.5.zip → nextcloud-33.0.6.zip (etc.)
	sed -i "s|nextcloud-${OLD_URL_VERSION}\.|nextcloud-${URL_VERSION}.|g" \
		"$STABLE_FEATURE" "$BETA_FEATURE"

	# GitHub tag paths: /v33.0.5/ → /v33.0.6/
	sed -i "s|/v${OLD_URL_VERSION}/|/v${URL_VERSION}/|g" \
		"$STABLE_FEATURE" "$BETA_FEATURE"

	# Internal version: 33.0.5.1 → 33.0.6.1
	sed -i "s|${OLD_INTERNAL}|${INTERNAL_VERSION}|g" \
		"$STABLE_FEATURE" "$BETA_FEATURE"

	# latest.feature: update stable version
	sed -i "s|\"${OLD_VERSION_STRING}\"|\"${VERSION_STRING}\"|g" "$LATEST_FEATURE"
	sed -i "s|nextcloud-${OLD_URL_VERSION}\.|nextcloud-${URL_VERSION}.|g" "$LATEST_FEATURE"

	# Replace zip signature in all feature files
	if [[ -n "$OLD_ZIP_SIG" ]]; then
		replace_signature "$OLD_ZIP_SIG" "$ZIP_SIG" "$STABLE_FEATURE" "$BETA_FEATURE"
	fi
}

update_features_prerelease() {
	# RC/beta bump: replace version strings and signatures in beta channel only

	# URLs: nextcloud-34.0.0rc5.zip → nextcloud-34.0.0rc6.zip
	sed -i "s|nextcloud-${OLD_URL_VERSION}\.|nextcloud-${URL_VERSION}.|g" "$BETA_FEATURE"
	sed -i "s|/v${OLD_URL_VERSION}/|/v${URL_VERSION}/|g" "$BETA_FEATURE"

	# Internal version
	sed -i "s|${OLD_INTERNAL}|${INTERNAL_VERSION}|g" "$BETA_FEATURE"

	# Display name: "34.0.0 RC5" → "34.0.0 RC6"
	sed -i "s|${OLD_VERSION_STRING}|${VERSION_STRING}|g" "$BETA_FEATURE"

	# latest.feature: update beta version
	sed -i "s|${OLD_VERSION_STRING}|${VERSION_STRING}|g" "$LATEST_FEATURE"
	sed -i "s|nextcloud-${OLD_URL_VERSION}\.|nextcloud-${URL_VERSION}.|g" "$LATEST_FEATURE"

	# Replace zip signature
	if [[ -n "$OLD_ZIP_SIG" ]]; then
		replace_signature "$OLD_ZIP_SIG" "$ZIP_SIG" "$BETA_FEATURE"
	fi
}

update_features_first_stable() {
	# First stable of new major: convert RC→stable in beta, add new scenarios to stable

	# Beta feature: update the existing RC scenarios to point to stable release
	# URLs: prereleases/nextcloud-34.0.0rc5.zip → releases/nextcloud-34.0.0.zip
	sed -i "s|prereleases/nextcloud-${OLD_URL_VERSION}\.|releases/nextcloud-${URL_VERSION}.|g" "$BETA_FEATURE"
	sed -i "s|/v${OLD_URL_VERSION}/nextcloud-${OLD_URL_VERSION}\.|/v${URL_VERSION}/nextcloud-${URL_VERSION}.|g" "$BETA_FEATURE"
	sed -i "s|/v${OLD_URL_VERSION}/|/v${URL_VERSION}/|g" "$BETA_FEATURE"

	# Internal version
	sed -i "s|${OLD_INTERNAL}|${INTERNAL_VERSION}|g" "$BETA_FEATURE"

	# Display name: "34.0.0 RC5" → "34.0.0"
	sed -i "s|\"${OLD_VERSION_STRING}\"|\"${VERSION_STRING}\"|g" "$BETA_FEATURE"

	# EOL: RC had "EOL is set to \"0\"" — keep that for beta (not EOL'd yet)

	# Replace zip signature in beta
	if [[ -n "$OLD_ZIP_SIG" ]]; then
		replace_signature "$OLD_ZIP_SIG" "$ZIP_SIG" "$BETA_FEATURE"
	fi

	# Find the previous major's latest stable version (for cross-major scenario input)
	PREV_MAJOR=$((MAJOR - 1))
	PREV_STABLE_KEY=$(jq -r --arg m "$PREV_MAJOR" \
		'to_entries[] | select(.key | test("^" + $m + "\\.") and (test("[Rr][Cc]|[Bb]eta|[Aa]lpha") | not) and (test("Enterprise") | not)) | .key' \
		"$RELEASES_JSON" | tail -1)
	PREV_STABLE_INTERNAL=$(jq -r --arg k "$PREV_STABLE_KEY" '.[$k].internalVersion' "$RELEASES_JSON")

	# Get EOL date for this major (empty = not EOL'd)
	EOL_DATE=$(jq -r --arg m "$MAJOR" '.[$m].eol // ""' "$MAJOR_VERSIONS_JSON")
	MIN_PHP=$(jq -r --arg m "$MAJOR" '.[$m].minPHP // "8.1"' "$MAJOR_VERSIONS_JSON")
	PHP_VERSION="${MIN_PHP}.0"

	# Build EOL assertion line
	if [[ -n "$EOL_DATE" ]]; then
		EOL_LINE="And EOL date is \"${EOL_DATE}\""
	else
		EOL_LINE="And EOL is set to \"0\""
	fi

	# Wrap signature for feature file (4-space indent)
	NEW_SIG_BLOCK=$(wrap_sig "$ZIP_SIG" | sed 's/^/    /')

	# Append new scenarios to stable.feature
	cat >> "$STABLE_FEATURE" <<-SCENARIO

  Scenario: Updating Nextcloud latest ${PREV_MAJOR} to ${MAJOR} on the stable channel
    Given There is a release with channel "stable"
    And The received version is "${PREV_STABLE_INTERNAL}"
    And The received PHP version is "${PHP_VERSION}"
    And the installation mtime is "11"
    When The request is sent
    Then The response is non-empty
    And Update to version "${INTERNAL_VERSION}" is available
    And URL to download is "https://download.nextcloud.com/server/releases/nextcloud-${URL_VERSION}.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-${URL_VERSION}.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-${URL_VERSION}.tar.bz2"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v${URL_VERSION}/nextcloud-${URL_VERSION}.zip"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v${URL_VERSION}/nextcloud-${URL_VERSION}.tar.bz2"
    And URL to documentation is "https://docs.nextcloud.com/server/${MAJOR}/admin_manual/maintenance/upgrade.html"
    ${EOL_LINE}
    And The signature is
    """
${NEW_SIG_BLOCK}
    """

  Scenario: Updating Nextcloud ${MAJOR} on the stable channel
    Given There is a release with channel "stable"
    And The received version is "${MAJOR}.0.0.0"
    And The received PHP version is "${PHP_VERSION}"
    And the installation mtime is "11"
    When The request is sent
    Then The response is non-empty
    And Update to version "${INTERNAL_VERSION}" is available
    And URL to download is "https://download.nextcloud.com/server/releases/nextcloud-${URL_VERSION}.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-${URL_VERSION}.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-${URL_VERSION}.tar.bz2"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v${URL_VERSION}/nextcloud-${URL_VERSION}.zip"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v${URL_VERSION}/nextcloud-${URL_VERSION}.tar.bz2"
    And URL to documentation is "https://docs.nextcloud.com/server/${MAJOR}/admin_manual/maintenance/upgrade.html"
    ${EOL_LINE}
    And The signature is
    """
${NEW_SIG_BLOCK}
    """
SCENARIO

	# Update latest.feature
	# Find current latest stable version and replace.
	# head -1: latest.feature may hold several "latest stable" scenarios
	# (e.g. a PHP-version-pinned one); only the first is the primary entry.
	# Without it the var goes multiline and breaks the sed below.
	CURRENT_LATEST_STABLE=$(grep -A4 'latest stable release' "$LATEST_FEATURE" \
		| grep 'Version "' | grep -oP 'Version "\K[^"]+' | head -1)
	CURRENT_LATEST_STABLE_URL=$(echo "$CURRENT_LATEST_STABLE" | sed 's/ //g' | tr '[:upper:]' '[:lower:]')

	if [[ -n "$CURRENT_LATEST_STABLE" ]]; then
		sed -i "/I want to know the latest stable/,/URL to download/ { s|Version \"${CURRENT_LATEST_STABLE}\"|Version \"${VERSION_STRING}\"|; s|nextcloud-${CURRENT_LATEST_STABLE_URL}\.zip|nextcloud-${URL_VERSION}.zip|; }" "$LATEST_FEATURE"
	fi

	# Update beta latest — now points to stable (no more RC)
	sed -i "/I want to know the latest beta/,/URL to download/ { s|\"${OLD_VERSION_STRING}\"|\"${VERSION_STRING}\"|; s|prereleases/nextcloud-${OLD_URL_VERSION}\.zip|releases/nextcloud-${URL_VERSION}.zip|; }" "$LATEST_FEATURE"
}

update_features_first_prerelease() {
	# First pre-release of a new major: add new scenarios to beta.feature

	# Find previous major's latest stable version
	PREV_MAJOR=$((MAJOR - 1))
	PREV_STABLE_KEY=$(jq -r --arg m "$PREV_MAJOR" \
		'to_entries[] | select(.key | test("^" + $m + "\\.") and (test("[Rr][Cc]|[Bb]eta|[Aa]lpha") | not) and (test("Enterprise") | not)) | .key' \
		"$RELEASES_JSON" | tail -1)
	PREV_STABLE_INTERNAL=$(jq -r --arg k "$PREV_STABLE_KEY" '.[$k].internalVersion' "$RELEASES_JSON")

	MIN_PHP=$(jq -r --arg m "$MAJOR" '.[$m].minPHP // "8.1"' "$MAJOR_VERSIONS_JSON")
	PHP_VERSION="${MIN_PHP}.0"

	# Wrap signature for feature file
	NEW_SIG_BLOCK=$(wrap_sig "$ZIP_SIG" | sed 's/^/    /')

	# Append new scenarios to beta.feature
	cat >> "$BETA_FEATURE" <<-SCENARIO

  Scenario: Updating Nextcloud latest ${PREV_MAJOR} to ${MAJOR} on the beta channel
    Given There is a release with channel "beta"
    And The received version is "${PREV_STABLE_INTERNAL}"
    And The received PHP version is "${PHP_VERSION}"
    And the installation mtime is "11"
    When The request is sent
    Then The response is non-empty
    And Update to version "${INTERNAL_VERSION}" is available
    And URL to download is "https://download.nextcloud.com/server/${URL_DIR}/nextcloud-${URL_VERSION}.zip"
    And Download URLS contain "https://download.nextcloud.com/server/${URL_DIR}/nextcloud-${URL_VERSION}.zip"
    And Download URLS contain "https://download.nextcloud.com/server/${URL_DIR}/nextcloud-${URL_VERSION}.tar.bz2"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v${URL_VERSION}/nextcloud-${URL_VERSION}.zip"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v${URL_VERSION}/nextcloud-${URL_VERSION}.tar.bz2"
    And URL to documentation is "https://docs.nextcloud.com/server/${MAJOR}/admin_manual/maintenance/upgrade.html"
    And EOL is set to "0"
    And The signature is
    """
${NEW_SIG_BLOCK}
    """

  Scenario: Updating Nextcloud ${MAJOR} on the beta channel
    Given There is a release with channel "beta"
    And The received version is "${MAJOR}.0.0.0"
    And The received PHP version is "${PHP_VERSION}"
    And the installation mtime is "11"
    When The request is sent
    Then The response is non-empty
    And Update to version "${INTERNAL_VERSION}" is available
    And URL to download is "https://download.nextcloud.com/server/${URL_DIR}/nextcloud-${URL_VERSION}.zip"
    And Download URLS contain "https://download.nextcloud.com/server/${URL_DIR}/nextcloud-${URL_VERSION}.zip"
    And Download URLS contain "https://download.nextcloud.com/server/${URL_DIR}/nextcloud-${URL_VERSION}.tar.bz2"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v${URL_VERSION}/nextcloud-${URL_VERSION}.zip"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v${URL_VERSION}/nextcloud-${URL_VERSION}.tar.bz2"
    And URL to documentation is "https://docs.nextcloud.com/server/${MAJOR}/admin_manual/maintenance/upgrade.html"
    And EOL is set to "0"
    And The signature is
    """
${NEW_SIG_BLOCK}
    """
SCENARIO

	# Update latest.feature — beta now points to this pre-release
	# head -1: see CURRENT_LATEST_STABLE note — guard against multiline.
	CURRENT_LATEST_BETA=$(grep -A4 'latest beta release' "$LATEST_FEATURE" \
		| grep 'Version "' | grep -oP 'Version "\K[^"]+' | head -1)
	CURRENT_LATEST_BETA_URL=$(echo "$CURRENT_LATEST_BETA" | sed 's/ //g' | tr '[:upper:]' '[:lower:]')

	if [[ -n "$CURRENT_LATEST_BETA" ]]; then
		sed -i "/I want to know the latest beta/,/URL to download/ { s|Version \"${CURRENT_LATEST_BETA}\"|Version \"${VERSION_STRING}\"|; s|nextcloud-${CURRENT_LATEST_BETA_URL}\.zip|nextcloud-${URL_VERSION}.zip|; }" "$LATEST_FEATURE"
		# Fix prereleases vs releases path
		sed -i "/I want to know the latest beta/,/URL to download/ { s|server/releases/nextcloud-${URL_VERSION}|server/${URL_DIR}/nextcloud-${URL_VERSION}|; }" "$LATEST_FEATURE"
	fi
}

# Dispatch to the right update function
case "$RELEASE_TYPE" in
	patch)            update_features_patch ;;
	prerelease)       update_features_prerelease ;;
	first_stable)     update_features_first_stable ;;
	first_prerelease) update_features_first_prerelease ;;
esac

# ─── Show diff ────────────────────────────────────────────────────────────────

echo ""
echo "=== Changes ==="
git diff --stat
echo ""
git diff

if [[ "$DRY_RUN" == "true" ]]; then
	info "Dry run — no PR created"
	exit 0
fi

# ─── Create PR ────────────────────────────────────────────────────────────────

BRANCH="releases/${VERSION}"
COMMIT_MSG="chore: add ${VERSION_STRING} to the ${CHANNEL} channel"

if [[ "$DEPLOY" -ne 100 ]]; then
	COMMIT_MSG="${COMMIT_MSG} (${DEPLOY}% rollout)"
fi

# Ensure a git identity exists (CI runners have none by default).
# Derive it from the token owner (GH_TOKEN == RELEASE_TOKEN) so commits
# are authored by the bot the token belongs to. Fall back to
# github-actions[bot] if the token isn't a user token (e.g. github.token).
if [[ -z "$(git config user.email)" ]]; then
	BOT_LOGIN=$(gh api user --jq '.login' 2>/dev/null || true)
	if [[ -n "$BOT_LOGIN" ]]; then
		BOT_ID=$(gh api user --jq '.id' 2>/dev/null || true)
		git config user.name "$BOT_LOGIN"
		git config user.email "${BOT_ID}+${BOT_LOGIN}@users.noreply.github.com"
	else
		git config user.name "github-actions[bot]"
		git config user.email "github-actions[bot]@users.noreply.github.com"
	fi
fi

git checkout -b "$BRANCH"
git add config/ tests/
git commit --signoff -m "$COMMIT_MSG"

# Route git's github.com auth through gh (GH_TOKEN == RELEASE_TOKEN);
# the gh-cloned remote has no push credentials on its own.
gh auth setup-git
git push -u origin "$BRANCH"

BODY="Automated PR from the release pipeline.

- **Release:** ${VERSION_STRING}
- **Internal version:** ${INTERNAL_VERSION}
- **Channel:** ${CHANNEL}
- **Deploy:** ${DEPLOY}%

Generated by \`update-updater-server.sh ${TAG}\`."

gh pr create \
	--repo "$UPDATER_REPO" \
	--base master \
	--head "$BRANCH" \
	--title "$COMMIT_MSG" \
	--body "$BODY"

info "PR created successfully"
