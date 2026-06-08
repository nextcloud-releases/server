#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Manage milestones across all release repositories after a stable release
# or first beta of a new major version.
#
# Usage:
#   update-milestones.sh <tag> <config.json> <tag-only.json> [options]
#
# Options:
#   --dry-run              Preview changes without applying them
#   --due-date YYYY-MM-DD  Set due date on newly created milestones
#
# Examples:
#   update-milestones.sh v33.0.4 stable33.json tag-only.json
#   update-milestones.sh v33.0.4 stable33.json tag-only.json --due-date 2026-07-23
#   update-milestones.sh v35.0.0beta1 master.json tag-only.json
#   update-milestones.sh v33.0.4 stable33.json tag-only.json --dry-run

set -euo pipefail

# Allow tests to inject a mock gh; defaults to the real CLI.
GH="${GH:-gh}"

# Two modes:
#  - Stable release (e.g. v33.0.4): close released milestone, create next patch, move open issues
#  - First beta (e.g. v35.0.0beta1): only create the new major milestone, no close/move

TAG="${1:?Usage: update-milestones.sh <tag> <config.json> <tag-only.json> [options]}"
CONFIG="${2:?Missing config.json path}"
TAG_ONLY="${3:?Missing tag-only.json path}"
shift 3

DRY_RUN=false
DUE_DATE=""
while [[ $# -gt 0 ]]; do
	case "$1" in
		--dry-run)  DRY_RUN=true ;;
		--due-date) DUE_DATE="${2:?--due-date requires a YYYY-MM-DD value}"; shift ;;
		*)          echo "Unknown option: $1"; exit 1 ;;
	esac
	shift
done

# Convert due date to ISO 8601 format expected by the GitHub API
DUE_ON=""
if [[ -n "$DUE_DATE" ]]; then
	if [[ ! "$DUE_DATE" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
		echo "::error::Invalid date format '${DUE_DATE}', expected YYYY-MM-DD"
		exit 1
	fi
	DUE_ON="${DUE_DATE}T00:00:00Z"
fi

# VERSION: full semver without leading "v"; MAJOR/MINOR: numeric components
# PATCH: third component with pre-release suffixes (alpha/beta/rc…) stripped
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
# stableXX.json contains objects with .repo keys; tag-only.json has plain strings.
# The jq handles both formats so we can feed both files in a single pipeline.
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
	# --paginate: busy repos (e.g. nextcloud/server) have >100 milestones, so the
	# target can be on a later page; without it the lookup silently misses them.
	"$GH" api "repos/${repo}/milestones?state=all&per_page=100" --paginate \
		--jq ".[] | select(.title == \"${title}\") | .number" 2>/dev/null || true
}

# Close a milestone
close_milestone() {
	local repo="$1" number="$2" title="$3"
	if $DRY_RUN; then
		echo "  $(dry_run_prefix)Would close milestone '${title}' (#${number})"
		return 0
	fi
	if "$GH" api "repos/${repo}/milestones/${number}" -X PATCH -f state=closed --silent 2>/dev/null; then
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
	if "$GH" api "repos/${repo}/milestones" -X POST "${args[@]}" --silent 2>/dev/null; then
		echo "  Created milestone '${title}'"
		return 0
	else
		warn "Failed to create milestone '${title}' in ${repo}"
		return 1
	fi
}

# Move all open issues from one milestone to another.
# Called BEFORE closing the source milestone so no issues get orphaned.
move_issues() {
	local repo="$1" from_number="$2" to_number="$3" to_title="$4"
	local moved=0
	local page=1

	# First collect every open issue number, paginating in batches of 100.
	# We gather the full list before moving any issue: moving an issue removes
	# it from the source milestone, which would shift later pages and cause the
	# page counter to skip issues (and could loop forever if a move failed).
	local all_issues=""
	while true; do
		local issues
		issues=$("$GH" api "repos/${repo}/issues?milestone=${from_number}&state=open&per_page=100&page=${page}" \
			--jq '.[].number' 2>/dev/null) || break

		if [[ -z "$issues" ]]; then
			break
		fi

		all_issues+="${issues}"$'\n'
		page=$((page + 1))
	done

	# Now move each gathered issue. Progress goes to stderr; only the final
	# count is written to stdout so the caller can capture it via $(move_issues ...).
	while IFS= read -r issue_number; do
		[[ -z "$issue_number" ]] && continue
		if $DRY_RUN; then
			echo "  $(dry_run_prefix)Would move #${issue_number} → '${to_title}'" >&2
		else
			if "$GH" api "repos/${repo}/issues/${issue_number}" -X PATCH \
				-F "milestone=${to_number}" --silent 2>/dev/null; then
				echo "  Moved #${issue_number} → '${to_title}'" >&2
			else
				warn "Failed to move ${repo}#${issue_number}" >&2
			fi
		fi
		moved=$((moved + 1))
	done <<< "$all_issues"

	echo "$moved"
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
			create_milestone "$repo" "$NEXT_MAJOR_MILESTONE" "$DUE_ON"
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

	# Naming convention: initial major releases (v34.0.0) may use short form "Nextcloud 34",
	# while patch releases always use full form "Nextcloud 34.0.1". Try both for .0.0.
	if [[ "$PATCH" -eq 0 && "$MINOR" -eq 0 ]]; then
		CURRENT_MILESTONES=("Nextcloud ${MAJOR}.${MINOR}.${PATCH}" "Nextcloud ${MAJOR}")
	else
		CURRENT_MILESTONES=("Nextcloud ${MAJOR}.${MINOR}.${PATCH}")
	fi
	NEXT_MILESTONE="Nextcloud ${MAJOR}.${MINOR}.${NEXT_PATCH}"

	# We always keep two open patch milestones. When closing 33.0.4:
	#   - 33.0.5 becomes the current open milestone (issues move here)
	#   - 33.0.6 is created as the next upcoming milestone
	UPCOMING_PATCH=$((PATCH + 2))
	UPCOMING_MILESTONE="Nextcloud ${MAJOR}.${MINOR}.${UPCOMING_PATCH}"

	echo "Stable release detected (${TAG})."
	echo "  Close: ${CURRENT_MILESTONES[*]}"
	echo "  Move issues to: ${NEXT_MILESTONE}"
	echo "  Create: ${UPCOMING_MILESTONE}${DUE_ON:+ (due: ${DUE_DATE})}"
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
			# Ensure the next milestone exists (should already from previous release,
			# but create it if missing so issue moves don't fail)
			next_number=$(find_milestone "$repo" "$NEXT_MILESTONE")
			if [[ -z "$next_number" ]]; then
				create_milestone "$repo" "$NEXT_MILESTONE"
				next_number=$(find_milestone "$repo" "$NEXT_MILESTONE")
				repo_created="$NEXT_MILESTONE"
				TOTAL_CREATED=$((TOTAL_CREATED + 1))
			fi

			# Move open issues before closing
			if [[ -n "$next_number" ]]; then
				repo_moved=$(move_issues "$repo" "$current_number" "$next_number" "$NEXT_MILESTONE")
				TOTAL_MOVED=$((TOTAL_MOVED + repo_moved))
			fi

			# Close current milestone
			close_milestone "$repo" "$current_number" "$current_title"
			repo_closed="$current_title"
			TOTAL_CLOSED=$((TOTAL_CLOSED + 1))

			# Create the upcoming milestone (two ahead) so there are always
			# two open patch milestones: the next release and the one after
			upcoming_number=$(find_milestone "$repo" "$UPCOMING_MILESTONE")
			if [[ -z "$upcoming_number" ]]; then
				create_milestone "$repo" "$UPCOMING_MILESTONE" "$DUE_ON"
				if [[ "$repo_created" == "-" ]]; then
					repo_created="$UPCOMING_MILESTONE"
				else
					repo_created="${repo_created}, ${UPCOMING_MILESTONE}"
				fi
				TOTAL_CREATED=$((TOTAL_CREATED + 1))
			else
				echo "  Milestone '${UPCOMING_MILESTONE}' already exists (#${upcoming_number})"
			fi
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
			echo "> **Dry run** - no changes were made"
			echo ""
		fi
		cat "$SUMMARY_FILE"
		echo ""
		echo "**Totals:** ${TOTAL_CLOSED} closed, ${TOTAL_CREATED} created, ${TOTAL_MOVED} issues moved"
	} >> "$GITHUB_STEP_SUMMARY"
fi

rm -f "$SUMMARY_FILE"
