#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Audit milestone consistency across all release repositories.
# Reports orphaned milestones (should be closed), missing milestones,
# naming issues, and missing due dates.
#
# Usage:
#   audit-milestones.sh <config.json> <tag-only.json>
#
# Examples:
#   audit-milestones.sh stable33.json tag-only.json
#
# The script determines expected state from the latest stable release tags
# on nextcloud-releases/server. It only checks milestones for the major
# version that corresponds to the given config file.
#
# Exit codes:
#   0 - no issues found
#   1 - issues found (details in output and step summary)

set -euo pipefail

CONFIG="${1:?Usage: audit-milestones.sh <config.json> <tag-only.json>}"
TAG_ONLY="${2:?Missing tag-only.json path}"

# Determine which major version this config covers
CONFIG_BASENAME=$(basename "$CONFIG" .json)
if [[ "$CONFIG_BASENAME" == "master" ]]; then
	# For master.json, find the highest major version tag
	MAJOR=$(gh api repos/nextcloud-releases/server/git/refs/tags \
		--paginate --jq '.[].ref | sub("refs/tags/v"; "")' \
		| grep -E '^[0-9]+\.' | cut -d. -f1 | sort -n | tail -1)
	# Next major (the one being developed on master)
	MAJOR=$((MAJOR + 1))
else
	MAJOR="${CONFIG_BASENAME#stable}"
fi

# Build repo list (same logic as update-milestones.sh)
REPOS=$(
	jq -r '
		if type == "array" and (.[0] | type) == "object" then
			.[].repo
		elif type == "array" then
			.[]
		else
			empty
		end
	' "$CONFIG" "$TAG_ONLY" | sort -u
)

REPO_COUNT=$(echo "$REPOS" | wc -l)

# Find the latest stable release for this major version
LATEST_STABLE=$(gh api repos/nextcloud-releases/server/git/refs/tags \
	--paginate --jq '.[].ref | sub("refs/tags/"; "")' \
	| grep -E "^v${MAJOR}\.[0-9]+\.[0-9]+$" | sort -V | tail -1 || true)

if [[ -z "$LATEST_STABLE" ]]; then
	echo "No stable release found for major version ${MAJOR}. Skipping audit."
	exit 0
fi

LATEST_VERSION="${LATEST_STABLE#v}"
LATEST_PATCH=$(echo "$LATEST_VERSION" | cut -d. -f3)
LATEST_MINOR=$(echo "$LATEST_VERSION" | cut -d. -f2)

# Expected state: the released milestone should be closed, and two open
# patch milestones should exist (next and upcoming).
NEXT_PATCH=$((LATEST_PATCH + 1))
UPCOMING_PATCH=$((LATEST_PATCH + 2))

RELEASED_MILESTONE="Nextcloud ${MAJOR}.${LATEST_MINOR}.${LATEST_PATCH}"
NEXT_MILESTONE="Nextcloud ${MAJOR}.${LATEST_MINOR}.${NEXT_PATCH}"
UPCOMING_MILESTONE="Nextcloud ${MAJOR}.${LATEST_MINOR}.${UPCOMING_PATCH}"

# For .0.0, also check the short-form "Nextcloud XX" name
RELEASED_MILESTONE_ALT=""
if [[ "$LATEST_PATCH" -eq 0 && "$LATEST_MINOR" -eq 0 ]]; then
	RELEASED_MILESTONE_ALT="Nextcloud ${MAJOR}"
fi

echo "=== Milestone audit for Nextcloud ${MAJOR} ==="
echo "  Latest stable release: ${LATEST_STABLE}"
echo "  Expected closed: ${RELEASED_MILESTONE}${RELEASED_MILESTONE_ALT:+ (or ${RELEASED_MILESTONE_ALT})}"
echo "  Expected open:   ${NEXT_MILESTONE}, ${UPCOMING_MILESTONE}"
echo "  Repos to check:  ${REPO_COUNT}"
echo ""

# Summary tracking
SUMMARY_FILE=$(mktemp)
ISSUES_FILE=$(mktemp)
echo "| Repository | Status | Details |" > "$SUMMARY_FILE"
echo "|---|---|---|" >> "$SUMMARY_FILE"

TOTAL_OK=0
TOTAL_WARN=0

warn_issue() {
	local repo="$1" msg="$2"
	echo "::warning::${repo}: ${msg}"
	echo "- **${repo}**: ${msg}" >> "$ISSUES_FILE"
}

while IFS= read -r repo; do
	echo "Checking ${repo}..."
	repo_issues=()

	# Fetch all open milestones for this repo (only Nextcloud XX ones)
	all_milestones=$(gh api "repos/${repo}/milestones?state=all&per_page=100" \
		--jq '.[] | select(.title | startswith("Nextcloud '"${MAJOR}"'")) | "\(.title)\t\(.state)\t\(.open_issues)\t\(.due_on // "none")"' \
		2>/dev/null || true)

	if [[ -z "$all_milestones" ]]; then
		repo_issues+=("No milestones found for Nextcloud ${MAJOR}")
		warn_issue "$repo" "No milestones found for Nextcloud ${MAJOR}"
	else
		# Check: released milestone should be closed
		released_found=false
		while IFS=$'\t' read -r title state open_issues due_on; do
			if [[ "$title" == "$RELEASED_MILESTONE" ]] || [[ -n "$RELEASED_MILESTONE_ALT" && "$title" == "$RELEASED_MILESTONE_ALT" ]]; then
				released_found=true
				if [[ "$state" == "open" ]]; then
					repo_issues+=("'${title}' still open (${open_issues} open issues) - should be closed")
					warn_issue "$repo" "'${title}' still open (${open_issues} open issues) - should be closed"
				fi
			fi
		done <<< "$all_milestones"

		# Check: next milestone should exist and be open
		next_found=false
		while IFS=$'\t' read -r title state open_issues due_on; do
			if [[ "$title" == "$NEXT_MILESTONE" ]]; then
				next_found=true
				if [[ "$state" != "open" ]]; then
					repo_issues+=("'${title}' is ${state} - should be open")
					warn_issue "$repo" "'${title}' is ${state} - should be open"
				fi
			fi
		done <<< "$all_milestones"
		if ! $next_found; then
			repo_issues+=("Missing milestone '${NEXT_MILESTONE}'")
			warn_issue "$repo" "Missing milestone '${NEXT_MILESTONE}'"
		fi

		# Check: upcoming milestone should exist and be open
		upcoming_found=false
		while IFS=$'\t' read -r title state open_issues due_on; do
			if [[ "$title" == "$UPCOMING_MILESTONE" ]]; then
				upcoming_found=true
				if [[ "$state" != "open" ]]; then
					repo_issues+=("'${title}' is ${state} - should be open")
					warn_issue "$repo" "'${title}' is ${state} - should be open"
				fi
				if [[ "$due_on" == "none" ]]; then
					repo_issues+=("'${title}' has no due date")
					warn_issue "$repo" "'${title}' has no due date"
				fi
			fi
		done <<< "$all_milestones"
		if ! $upcoming_found; then
			repo_issues+=("Missing milestone '${UPCOMING_MILESTONE}'")
			warn_issue "$repo" "Missing milestone '${UPCOMING_MILESTONE}'"
		fi

		# Check: orphans - any open milestones for patches older than the released one
		while IFS=$'\t' read -r title state open_issues due_on; do
			if [[ "$state" != "open" ]]; then
				continue
			fi
			# Extract patch number from title like "Nextcloud 33.0.4"
			if [[ "$title" =~ ^Nextcloud\ ${MAJOR}\.${LATEST_MINOR}\.([0-9]+)$ ]]; then
				ms_patch="${BASH_REMATCH[1]}"
				if [[ "$ms_patch" -le "$LATEST_PATCH" ]]; then
					repo_issues+=("Orphan: '${title}' still open (${open_issues} open issues) - version already released")
					warn_issue "$repo" "Orphan: '${title}' still open (${open_issues} open issues) - version already released"
				fi
			fi
			# Check short-form "Nextcloud XX" if the .0.0 is already released
			if [[ "$LATEST_PATCH" -ge 0 && "$title" == "Nextcloud ${MAJOR}" && "$state" == "open" ]]; then
				# Only flag if it's not the alt name we already checked above
				if [[ -z "$RELEASED_MILESTONE_ALT" || "$LATEST_PATCH" -gt 0 ]]; then
					repo_issues+=("Orphan: '${title}' still open (${open_issues} open issues) - major already released")
					warn_issue "$repo" "Orphan: '${title}' still open (${open_issues} open issues) - major already released"
				fi
			fi
		done <<< "$all_milestones"

		# Check: naming issues - milestones that look like Nextcloud XX but with
		# wrong casing or spacing
		while IFS=$'\t' read -r title state open_issues due_on; do
			if [[ "$title" =~ ^[Nn]extcloud\ *${MAJOR} ]] && [[ ! "$title" =~ ^Nextcloud\ ${MAJOR} ]]; then
				repo_issues+=("Naming issue: '${title}' - expected 'Nextcloud ${MAJOR}...'")
				warn_issue "$repo" "Naming issue: '${title}' - expected 'Nextcloud ${MAJOR}...'"
			fi
		done <<< "$all_milestones"
	fi

	# Record result
	if [[ ${#repo_issues[@]} -eq 0 ]]; then
		echo "| ${repo} | :white_check_mark: | OK |" >> "$SUMMARY_FILE"
		TOTAL_OK=$((TOTAL_OK + 1))
	else
		detail=$(IFS='; '; echo "${repo_issues[*]}")
		echo "| ${repo} | :warning: | ${detail} |" >> "$SUMMARY_FILE"
		TOTAL_WARN=$((TOTAL_WARN + 1))
	fi
done <<< "$REPOS"

# Print summary
echo ""
echo "=== Audit summary ==="
echo "  OK: ${TOTAL_OK} repos"
echo "  Issues: ${TOTAL_WARN} repos"

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
	{
		echo "## Milestone audit for Nextcloud ${MAJOR}"
		echo ""
		echo "Latest stable release: \`${LATEST_STABLE}\`"
		echo "Expected open milestones: \`${NEXT_MILESTONE}\`, \`${UPCOMING_MILESTONE}\`"
		echo ""
		if [[ -s "$ISSUES_FILE" ]]; then
			echo "### Issues found"
			echo ""
			cat "$ISSUES_FILE"
			echo ""
		fi
		cat "$SUMMARY_FILE"
		echo ""
		echo "**${TOTAL_OK}** OK, **${TOTAL_WARN}** with issues"
	} >> "$GITHUB_STEP_SUMMARY"
fi

rm -f "$SUMMARY_FILE" "$ISSUES_FILE"

if [[ "$TOTAL_WARN" -gt 0 ]]; then
	exit 1
fi
