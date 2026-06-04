#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Rewrite version.php with release metadata.
# Usage: update-version-php.sh <nextcloud-dir> <channel> <branch>
#
# Example: update-version-php.sh /tmp/nextcloud stable stable34

set -e

NC="${1:?Usage: update-version-php.sh <nextcloud-dir> <channel> <branch>}"
export CHANNEL="${2:?Missing channel (stable/beta)}"
export BRANCH="${3:?Missing branch (stable34/master)}"

COMMIT_HASH=$(cat "$NC/release-commit-hash")
rm -f "$NC/release-commit-hash"
export BUILD_STRING="$(date -u +%Y-%m-%dT%H:%M:%S+00:00) ${COMMIT_HASH}"

# Preserves OC_Version and OC_VersionString from the repo.
# Sets channel, build timestamp, edition, and vendor.
php << 'EOPHP'
<?php
$nc = getenv('NC') ?: '/tmp/nextcloud';
$file = $nc . '/version.php';
require($file);
$channel = getenv('CHANNEL');
$branch = getenv('BRANCH');
$build = getenv('BUILD_STRING');
$content = "<?php \n";
$content .= '$OC_Version = array(' . implode(',', $OC_Version) . ')' . ";\n";
$content .= '$OC_VersionString = \'' . $OC_VersionString . "';\n";
if ($branch !== 'master') {
    $content .= '$OC_Edition = \'' . "';\n";
}
$content .= '$OC_Channel = \'' . $channel . "';\n";
if (isset($OC_VersionCanBeUpgradedFrom)) {
    $content .= '$OC_VersionCanBeUpgradedFrom = ' . var_export($OC_VersionCanBeUpgradedFrom, true) . ";\n";
}
$content .= '$OC_Build = \'' . $build . "';\n";
$content .= '$vendor = \'nextcloud\';' . "\n";
file_put_contents($file, $content);
echo "version.php updated: channel=$channel build=$build\n";
EOPHP
