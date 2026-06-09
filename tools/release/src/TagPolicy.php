<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

/**
 * Decides what tagging a repo should do.
 *
 * Release tags are immutable on the server repos, so those are never
 * re-tagged even with --force. Elsewhere: create when missing, recreate only
 * when --force is given, otherwise skip.
 */
final class TagPolicy
{
    public const ACTION_CREATE = 'create';
    public const ACTION_RECREATE = 'recreate';
    public const ACTION_SKIP = 'skip';

    /** Repos whose existing tags must never be moved. */
    public const PROTECTED_REPOS = [
        'nextcloud/server',
        'nextcloud-releases/server',
    ];

    public static function isProtected(string $repo): bool
    {
        return in_array($repo, self::PROTECTED_REPOS, true);
    }

    /**
     * @return self::ACTION_* what to do given whether the tag already exists
     *                        and whether --force was requested
     */
    public static function decide(string $repo, bool $tagExists, bool $force): string
    {
        if (!$tagExists) {
            return self::ACTION_CREATE;
        }
        if ($force && !self::isProtected($repo)) {
            return self::ACTION_RECREATE;
        }
        return self::ACTION_SKIP;
    }
}
