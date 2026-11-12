#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Remove what an app's .nextcloudignore lists, with gitignore semantics, the
# same way the release script does. Needs the app's .git, so run it before
# .git is removed.
#
# Usage: apply-nextcloudignore.sh <app-dir>

set -euo pipefail

cd "${1:-.}"
APP=$(basename "$(pwd)")

# Kept even when .nextcloudignore lists them, so a release records what it was
# built from.
KEEP='^(composer\.(json|lock)|package(-lock)?\.json)$'

if [ ! -f .nextcloudignore ]; then
  echo "${APP} has no .nextcloudignore"
  exit 0
fi

# -c tracked, -o untracked (composer output), -i only the ignored ones.
git ls-files -cioz --exclude-from=.nextcloudignore \
  | { grep -zEv "$KEEP" || true; } \
  | xargs -0 -r rm -rf --
find . -type d -empty -delete
