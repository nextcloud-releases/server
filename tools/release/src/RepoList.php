<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

/**
 * Builds the repository list from the release config files.
 *
 * Accepts both shapes used in the repo: arrays of objects with a "repo" key
 * (stableXX.json / master.json) and arrays of plain strings (tag-only.json).
 * The result is the sorted, de-duplicated union - matching the jq pipeline in
 * update-milestones.sh / audit-milestones.sh.
 */
final class RepoList
{
    /**
     * @param array<int, mixed> ...$lists decoded JSON arrays
     * @return list<string>
     */
    public static function merge(array ...$lists): array
    {
        $repos = [];
        foreach ($lists as $list) {
            foreach ($list as $item) {
                if (is_array($item) && isset($item['repo']) && is_string($item['repo'])) {
                    $repos[] = $item['repo'];
                } elseif (is_string($item)) {
                    $repos[] = $item;
                }
            }
        }
        $repos = array_values(array_unique($repos));
        sort($repos, SORT_STRING);
        return $repos;
    }
}
