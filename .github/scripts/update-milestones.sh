#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Manage milestones across all release repositories after a stable release
# or first beta of a new major version.
#
# Usage:
#   update-milestones.sh <tag> <config.json> <tag-only.json> [--dry-run]
#
# Examples:
#   update-milestones.sh v33.0.4 stable33.json tag-only.json
#   update-milestones.sh v35.0.0beta1 master.json tag-only.json
#   update-milestones.sh v33.0.4 stable33.json tag-only.json --dry-run

set -euo pipefail

TAG="${1:?Usage: update-milestones.sh <tag> <config.json> <tag-only.json> [--dry-run]}"
CONFIG="${2:?Missing config.json path}"
TAG_ONLY="${3:?Missing tag-only.json path}"
DRY_RUN=false
if [[ "${4:-}" == "--dry-run" ]]; then
	DRY_RUN=true
fi

VERSION="${TAG#v}"
MAJOR=$(echo "$VERSION" | cut -d. -f1)
MINOR=$(echo "$VERSION" | cut -d. -f2)
PATCH=$(echo "$VERSION" | sed 's/\(alpha\|beta\|rc\).*//' | cut -d. -f3)

IS_FIRST_BETA=false
if [[ "$TAG" =~ \.0\.0beta1$ ]]; then
	IS_FIRST_BETA=true
fi

IS_PRERELEASE=false
if [[ "$TAG" =~ (alpha|beta|rc) ]]; then
	IS_PRERELEASE=true
fi

# Build repo list from config + tag-only
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

# Summary tracking
SUMMARY_FILE=$(mktemp)
echo "| Repository | Closed | Created | Issues moved |" > "$SUMMARY_FILE"
echo "|---|---|---|---|" >> "$SUMMARY_FILE"

TOTAL_CLOSED=0
TOTAL_CREATED=0
TOTAL_MOVED=0

log() {
	echo "::notice::$*"
}

warn() {
	echo "::warning::$*"
}

dry_run_prefix() {
	if $DRY_RUN; then
		echo "[dry-run] "
	fi
}

# Find a milestone by title in a repo, return its number (or empty)
find_milestone() {
	local repo="$1" title="$2"
	gh api "repos/${repo}/milestones?state=all&per_page=100" \
		--jq ".[] | select(.title == \"${title}\") | .number" 2>/dev/null || true
}

# Close a milestone
close_milestone() {
	local repo="$1" number="$2" title="$3"
	if $DRY_RUN; then
		echo "  $(dry_run_prefix)Would close milestone '${title}' (#${number})"
		return 0
	fi
	if gh api "repos/${repo}/milestones/${number}" -X PATCH -f state=closed --silent 2>/dev/null; then
		echo "  Closed milestone '${title}' (#${number})"
		return 0
	else
		warn "Failed to close milestone '${title}' in ${repo}"
		return 1
	fi
}

# Create a milestone
create_milestone() {
	local repo="$1" title="$2" due_on="${3:-}"
	if $DRY_RUN; then
		echo "  $(dry_run_prefix)Would create milestone '${title}' (due: ${due_on:-none})"
		return 0
	fi
	local args=(-f "title=${title}")
	if [[ -n "$due_on" ]]; then
		args+=(-f "due_on=${due_on}")
	fi
	if gh api "repos/${repo}/milestones" -X POST "${args[@]}" --silent 2>/dev/null; then
		echo "  Created milestone '${title}'"
		return 0
	else
		warn "Failed to create milestone '${title}' in ${repo}"
		return 1
	fi
}

# Move all open issues from one milestone to another
move_issues() {
	local repo="$1" from_number="$2" to_number="$3" from_title="$4" to_title="$5"
	local moved=0
	local page=1

	while true; do
		local issues
		issues=$(gh api "repos/${repo}/issues?milestone=${from_number}&state=open&per_page=100&page=${page}" \
			--jq '.[].number' 2>/dev/null) || break

		if [[ -z "$issues" ]]; then
			break
		fi

		while IFS= read -r issue_number; do
			if $DRY_RUN; then
				echo "  $(dry_run_prefix)Would move #${issue_number} → '${to_title}'"
			else
				if gh api "repos/${repo}/issues/${issue_number}" -X PATCH \
					-F "milestone=${to_number}" --silent 2>/dev/null; then
					echo "  Moved #${issue_number} → '${to_title}'"
				else
					warn "Failed to move ${repo}#${issue_number}"
				fi
			fi
			moved=$((moved + 1))
		done <<< "$issues"

		page=$((page + 1))
	done

	echo "$moved"
}

# Compute a due date 4 weeks from now in ISO 8601
due_date_4_weeks() {
	date -u -d "+4 weeks" "+%Y-%m-%dT00:00:00Z" 2>/dev/null \
		|| date -u -v+4w "+%Y-%m-%dT00:00:00Z" 2>/dev/null \
		|| echo ""
}

# --- Main logic ---

if $IS_FIRST_BETA; then
	# First beta: only create next major milestone
	NEXT_MAJOR_MILESTONE="Nextcloud ${MAJOR}"
	echo "First beta detected (${TAG}). Creating milestone '${NEXT_MAJOR_MILESTONE}' across ${REPO_COUNT} repos."

	while IFS= read -r repo; do
		echo "Processing ${repo}..."
		existing=$(find_milestone "$repo" "$NEXT_MAJOR_MILESTONE")
		repo_created="-"
		if [[ -z "$existing" ]]; then
			create_milestone "$repo" "$NEXT_MAJOR_MILESTONE"
			repo_created="$NEXT_MAJOR_MILESTONE"
			TOTAL_CREATED=$((TOTAL_CREATED + 1))
		else
			echo "  Milestone '${NEXT_MAJOR_MILESTONE}' already exists (#${existing})"
		fi
		echo "| ${repo} | - | ${repo_created} | - |" >> "$SUMMARY_FILE"
	done <<< "$REPOS"

elif ! $IS_PRERELEASE; then
	# Stable release: close current, create next, move issues
	NEXT_PATCH=$((PATCH + 1))
	DUE_ON=$(due_date_4_weeks)

	# For v34.0.0, also try "Nextcloud 34" as milestone name (major releases use short form)
	if [[ "$PATCH" -eq 0 && "$MINOR" -eq 0 ]]; then
		CURRENT_MILESTONES=("Nextcloud ${MAJOR}.${MINOR}.${PATCH}" "Nextcloud ${MAJOR}")
	else
		CURRENT_MILESTONES=("Nextcloud ${MAJOR}.${MINOR}.${PATCH}")
	fi
	NEXT_MILESTONE="Nextcloud ${MAJOR}.${MINOR}.${NEXT_PATCH}"

	echo "Stable release detected (${TAG})."
	echo "  Close: ${CURRENT_MILESTONES[*]}"
	echo "  Create: ${NEXT_MILESTONE} (due: ${DUE_ON})"
	echo "  Repos: ${REPO_COUNT}"
	echo ""

	while IFS= read -r repo; do
		echo "Processing ${repo}..."
		repo_closed="-"
		repo_created="-"
		repo_moved=0

		# Find and close current milestone (try each candidate name)
		current_number=""
		current_title=""
		for title in "${CURRENT_MILESTONES[@]}"; do
			current_number=$(find_milestone "$repo" "$title")
			if [[ -n "$current_number" ]]; then
				current_title="$title"
				break
			fi
		done

		if [[ -n "$current_number" ]]; then
			# Create next milestone first (so we can move issues to it)
			next_number=$(find_milestone "$repo" "$NEXT_MILESTONE")
			if [[ -z "$next_number" ]]; then
				create_milestone "$repo" "$NEXT_MILESTONE" "$DUE_ON"
				next_number=$(find_milestone "$repo" "$NEXT_MILESTONE")
				repo_created="$NEXT_MILESTONE"
				TOTAL_CREATED=$((TOTAL_CREATED + 1))
			else
				echo "  Milestone '${NEXT_MILESTONE}' already exists (#${next_number})"
			fi

			# Move open issues before closing
			if [[ -n "$next_number" ]]; then
				repo_moved=$(move_issues "$repo" "$current_number" "$next_number" "$current_title" "$NEXT_MILESTONE")
				TOTAL_MOVED=$((TOTAL_MOVED + repo_moved))
			fi

			# Close current milestone
			close_milestone "$repo" "$current_number" "$current_title"
			repo_closed="$current_title"
			TOTAL_CLOSED=$((TOTAL_CLOSED + 1))
		else
			echo "  No milestone found for ${CURRENT_MILESTONES[*]}, skipping"
		fi

		echo "| ${repo} | ${repo_closed} | ${repo_created} | ${repo_moved} |" >> "$SUMMARY_FILE"
	done <<< "$REPOS"

else
	echo "Tag ${TAG} is a pre-release but not first beta. Nothing to do."
	exit 0
fi

# Print summary
echo ""
echo "=== Summary ==="
echo "  Milestones closed: ${TOTAL_CLOSED}"
echo "  Milestones created: ${TOTAL_CREATED}"
echo "  Issues moved: ${TOTAL_MOVED}"

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
	{
		echo "## Milestone updates for ${TAG}"
		echo ""
		if $DRY_RUN; then
			echo "> **Dry run** — no changes were made"
			echo ""
		fi
		cat "$SUMMARY_FILE"
		echo ""
		echo "**Totals:** ${TOTAL_CLOSED} closed, ${TOTAL_CREATED} created, ${TOTAL_MOVED} issues moved"
	} >> "$GITHUB_STEP_SUMMARY"
fi

rm -f "$SUMMARY_FILE"
