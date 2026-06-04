#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Tag a single repository at a branch HEAD.
# Uses gh release create+delete (fast), falls back to git clone+tag+push.
#
# Usage: tag-repo.sh <repo> <branch> <tag> [--force]
#
# Environment:
#   GH_TOKEN          — GitHub token for gh CLI (required for gh mode)
#   GIT_SSH_COMMAND   — optional SSH command for git fallback
#
# Exit codes: 0 = tagged or skipped, 1 = failed
# Stdout: one of OK|SKIPPED|FAILED followed by details

REPO="${1:?Usage: tag-repo.sh <repo> <branch> <tag> [--force]}"
BRANCH="${2:?Missing branch}"
TAG="${3:?Missing tag}"
FORCE="${4:-}"

# Server repos manage their own releases — never force-retag them
SERVER_REPOS="nextcloud/server nextcloud-releases/server"

# Check if gh CLI is available
GH_AVAILABLE=false
if command -v gh &>/dev/null; then
  GH_AVAILABLE=true
fi

# Check if tag already exists (works with both gh and git)
tag_exists_gh() {
  gh api "repos/$REPO/git/ref/tags/$TAG" &>/dev/null
}

tag_exists_git() {
  git ls-remote --tags "https://github.com/$REPO.git" "$TAG" 2>/dev/null | grep -q "$TAG"
}

# Check tag existence and handle skip/force logic
# Returns: 0 = proceed with tagging, 1 = skip (already tagged), 2 = error
check_existing_tag() {
  local exists=false

  if $GH_AVAILABLE; then
    tag_exists_gh && exists=true
  else
    tag_exists_git && exists=true
  fi

  if ! $exists; then
    return 0
  fi

  # Tag exists — check if this is a server repo (never force)
  for server_repo in $SERVER_REPOS; do
    if [ "$REPO" = "$server_repo" ]; then
      echo "SKIPPED already tagged (server repo, never force)"
      return 1
    fi
  done

  if [ "$FORCE" = "--force" ]; then
    echo "tag exists, force-replacing" >&2
    return 0
  fi

  echo "SKIPPED already tagged"
  return 1
}

tag_via_gh() {
  # Force: delete existing release + tag first
  if [ "$FORCE" = "--force" ]; then
    gh release delete "$TAG" --repo "$REPO" --yes &>/dev/null || true
    gh api -X DELETE "repos/$REPO/git/refs/tags/$TAG" &>/dev/null || true
  fi

  # Create release (creates the tag on the target branch)
  local output
  if ! output=$(gh release create "$TAG" \
    --repo "$REPO" \
    --target "$BRANCH" \
    --title "$TAG" \
    --notes "" \
    2>&1); then
    echo "gh release create failed: $output" >&2
    return 1
  fi

  # Give GitHub a moment to propagate
  sleep 1

  # Delete the release, keep the tag
  gh release delete "$TAG" --repo "$REPO" --yes &>/dev/null || true

  echo "OK tagged via gh"
  return 0
}

tag_via_git() {
  local tmpdir
  tmpdir=$(mktemp -d)
  trap 'rm -rf "$tmpdir"' EXIT

  if ! git clone --depth 1 --branch "$BRANCH" "https://github.com/$REPO.git" "$tmpdir/repo" -q 2>/dev/null; then
    # Try SSH if HTTPS fails
    if ! git clone --depth 1 --branch "$BRANCH" "git@github.com:$REPO.git" "$tmpdir/repo" -q 2>/dev/null; then
      echo "FAILED clone failed"
      return 1
    fi
  fi

  cd "$tmpdir/repo" || return 1

  # Force: delete existing tag on remote
  if [ "$FORCE" = "--force" ]; then
    git push origin ":refs/tags/$TAG" 2>/dev/null || true
  fi

  git tag "$TAG"
  if git push origin "$TAG" 2>/dev/null; then
    echo "OK tagged via git"
    return 0
  else
    echo "FAILED push failed"
    return 1
  fi
}

# Check if tag already exists
check_existing_tag
rc=$?
if [ $rc -eq 1 ]; then
  exit 0
fi

# Try gh first, fall back to git
if $GH_AVAILABLE; then
  if tag_via_gh; then
    exit 0
  fi
  echo "gh failed, falling back to git clone" >&2
fi

if tag_via_git; then
  exit 0
fi

echo "FAILED"
exit 1
