#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Stateful mock of the `gh` CLI for milestone-script tests.
#
# Injected into update-milestones.sh / audit-milestones.sh via the GH env var.
# Serves canned GitHub API responses from a mutable JSON state file and records
# every mutating call (close / create / move) to a journal so tests can assert
# on the exact sequence of side effects.
#
# Environment:
#   GH_STATE    Path to the mutable state JSON (the runner seeds it from a fixture).
#   GH_JOURNAL  Path to the append-only mutation log (tab-separated lines).
#
# State JSON shape:
#   {
#     "milestones": { "<repo>": [ {number,title,state,open_issues,due_on}, ... ] },
#     "issues":     { "<repo>": [ {number,milestone,state}, ... ] },
#     "tags":       [ "v33.0.0", "v33.0.4", ... ]
#   }
#
# Only the `gh api` surface used by the scripts is implemented; anything else
# is a hard error so unhandled calls fail loudly rather than silently passing.

# The single-quoted strings below are jq programs using jq's own --arg
# variables ($r, $n, …), not shell parameters - so no shell expansion is wanted.
# shellcheck disable=SC2016
set -euo pipefail

STATE="${GH_STATE:?fake-gh requires GH_STATE}"
JOURNAL="${GH_JOURNAL:?fake-gh requires GH_JOURNAL}"

# Atomically replace the state file with the jq transform read from stdin args.
write_state() {
	local tmp
	tmp=$(mktemp)
	jq "$@" "$STATE" > "$tmp"
	mv "$tmp" "$STATE"
}

# Read one query-string parameter (e.g. page=2) from a foo?a=1&b=2 string.
query_param() {
	local name="$1" qs="$2"
	# Strip everything up to the path; keep only the query part.
	[[ "$qs" == *\?* ]] || { echo ""; return; }
	qs="${qs#*\?}"
	local kv
	for kv in ${qs//&/ }; do
		if [[ "$kv" == "${name}="* ]]; then
			echo "${kv#*=}"
			return
		fi
	done
	echo ""
}

[[ "${1:-}" == "api" ]] || { echo "fake-gh: only 'api' is supported, got: $*" >&2; exit 1; }
shift

ENDPOINT=""
METHOD="GET"
JQ=""
declare -A FIELDS=()
while [[ $# -gt 0 ]]; do
	case "$1" in
		-X)         METHOD="$2"; shift 2 ;;
		--jq)       JQ="$2"; shift 2 ;;
		-f|-F)      FIELDS["${2%%=*}"]="${2#*=}"; shift 2 ;;
		--silent)   shift ;;
		--paginate) shift ;;
		-*)         echo "fake-gh: unknown flag: $1" >&2; exit 1 ;;
		*)          ENDPOINT="$1"; shift ;;
	esac
done

PATH_ONLY="${ENDPOINT%%\?*}"

# Emit a JSON body, applying the caller's --jq filter exactly like real gh does.
emit() {
	if [[ -n "$JQ" ]]; then
		jq -r "$JQ"
	else
		cat
	fi
}

# --- Route by endpoint + method ---

# Tags listing (audit determines expected state from these).
if [[ "$PATH_ONLY" == "repos/nextcloud-releases/server/git/refs/tags" ]]; then
	jq -c '[.tags[] | {ref: ("refs/tags/" + .)}]' "$STATE" | emit
	exit 0
fi

# Close / update a milestone.
if [[ "$METHOD" == "PATCH" && "$PATH_ONLY" =~ ^repos/(.+)/milestones/([0-9]+)$ ]]; then
	repo="${BASH_REMATCH[1]}"
	number="${BASH_REMATCH[2]}"
	state="${FIELDS[state]:-}"
	write_state --arg r "$repo" --argjson n "$number" --arg s "$state" \
		'(.milestones[$r][]? | select(.number == $n) | .state) = $s'
	if [[ "$state" == "closed" ]]; then
		printf 'close\t%s\t%s\n' "$repo" "$number" >> "$JOURNAL"
	fi
	exit 0
fi

# Create a milestone.
if [[ "$METHOD" == "POST" && "$PATH_ONLY" =~ ^repos/(.+)/milestones$ ]]; then
	repo="${BASH_REMATCH[1]}"
	title="${FIELDS[title]:-}"
	due="${FIELDS[due_on]:-}"
	number=$(jq '[.milestones[]?[]?.number] | (max // 0) + 1' "$STATE")
	write_state --arg r "$repo" --arg t "$title" --argjson n "$number" --arg due "$due" \
		'.milestones[$r] = ((.milestones[$r] // []) + [{
			number: $n, title: $t, state: "open", open_issues: 0,
			due_on: (if $due == "" then null else $due end)
		}])'
	printf 'create\t%s\t%s\tdue=%s\n' "$repo" "$title" "${due:--}" >> "$JOURNAL"
	exit 0
fi

# List milestones for a repo.
if [[ "$METHOD" == "GET" && "$PATH_ONLY" =~ ^repos/(.+)/milestones$ ]]; then
	repo="${BASH_REMATCH[1]}"
	jq -c --arg r "$repo" '.milestones[$r] // []' "$STATE" | emit
	exit 0
fi

# Move an issue (change its milestone).
if [[ "$METHOD" == "PATCH" && "$PATH_ONLY" =~ ^repos/(.+)/issues/([0-9]+)$ ]]; then
	repo="${BASH_REMATCH[1]}"
	number="${BASH_REMATCH[2]}"
	milestone="${FIELDS[milestone]:-}"
	write_state --arg r "$repo" --argjson i "$number" --argjson m "$milestone" \
		'(.issues[$r][]? | select(.number == $i) | .milestone) = $m'
	printf 'move\t%s\t%s\t%s\n' "$repo" "$number" "$milestone" >> "$JOURNAL"
	exit 0
fi

# List open issues in a milestone, one page of 100 at a time.
if [[ "$METHOD" == "GET" && "$PATH_ONLY" =~ ^repos/(.+)/issues$ ]]; then
	repo="${BASH_REMATCH[1]}"
	milestone=$(query_param milestone "$ENDPOINT")
	page=$(query_param page "$ENDPOINT")
	[[ -n "$page" ]] || page=1
	start=$(( (page - 1) * 100 ))
	jq -c --arg r "$repo" --argjson m "${milestone:-0}" --argjson start "$start" '
		[.issues[$r][]? | select(.milestone == $m and .state == "open")]
		| .[$start : $start + 100]
	' "$STATE" | emit
	exit 0
fi

echo "fake-gh: unhandled $METHOD $ENDPOINT" >&2
exit 1
