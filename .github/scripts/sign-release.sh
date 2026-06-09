#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Sign core and all apps with occ integrity:sign-*.
# Usage: sign-release.sh <nextcloud-dir> <private-key> [certificate]
#
# If certificate is not provided, uses resources/codesigning/core.crt from the release.

set -e

# Resolve the signing certificate path: the given one, or the release default.
# Args: <nextcloud-dir> [certificate]
default_cert() {
  echo "${2:-$1/resources/codesigning/core.crt}"
}

# When sourced (tests), stop here after defining functions.
[[ "${BASH_SOURCE[0]}" != "${0}" ]] && return 0

NC="${1:?Usage: sign-release.sh <nextcloud-dir> <private-key> [certificate]}"
KEY="${2:?Missing private key path}"
CERT="$(default_cert "$NC" "${3:-}")"

chmod 755 "$NC/occ"
chmod 777 "$NC/config"

echo "Signing core..."
php "$NC/occ" integrity:sign-core \
  --privateKey="$KEY" \
  --certificate="$CERT" \
  --path "$NC"

echo "Signing apps..."
for app_dir in "$NC"/apps/*/; do
  app_name=$(basename "$app_dir")
  echo "  $app_name"
  php "$NC/occ" integrity:sign-app \
    --privateKey="$KEY" \
    --certificate="$CERT" \
    --path="$app_dir"
done

# occ may create config.php during signing
rm -f "$NC/config/config.php"

echo "Signing complete"
