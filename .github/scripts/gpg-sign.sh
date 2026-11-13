#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Write detached, ASCII-armored GPG signatures (.asc) for the release archives,
# like the release script's makesignatures.php.
#
# Usage: gpg-sign.sh <output-dir> <version>
# Signs with the default secret key of GNUPGHOME, or GPG_KEY_ID when set.

set -euo pipefail

OUTPUT="${1:?Usage: gpg-sign.sh <output-dir> <version>}"
VERSION="${2:?Missing version}"

ARGS=(--batch --yes --armor --detach-sign)
if [ -n "${GPG_KEY_ID:-}" ]; then
  ARGS+=(--local-user "$GPG_KEY_ID")
fi

for file in "nextcloud-${VERSION}.tar.bz2" "nextcloud-${VERSION}.zip"; do
  [ -f "$OUTPUT/$file" ] || continue
  gpg "${ARGS[@]}" --output "$OUTPUT/${file}.asc" "$OUTPUT/$file"
  echo "Signed ${file}"
done
