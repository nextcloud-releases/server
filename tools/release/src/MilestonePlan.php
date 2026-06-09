<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

/**
 * Computes the milestone names a release acts on.
 *
 * The invariant: two open patch milestones are always kept. A stable release
 * vX.Y.Z closes its own milestone, moves issues to X.Y.(Z+1), and creates
 * X.Y.(Z+2). The first beta of a major opens the NEXT major (N+1) - the
 * "Nextcloud N" milestone already exists from the previous cycle.
 */
final class MilestonePlan
{
    public static function name(int $major, ?int $minor = null, ?int $patch = null): string
    {
        if ($minor === null) {
            return "Nextcloud {$major}";
        }
        return "Nextcloud {$major}.{$minor}.{$patch}";
    }

    /** First beta of major N creates the milestone for N+1. */
    public static function firstBetaMilestone(Version $version): string
    {
        return self::name($version->major + 1);
    }

    /**
     * The milestone name(s) to close for a stable release. Initial releases
     * (.0.0) may use the short "Nextcloud N" form, so both are candidates.
     *
     * @return list<string>
     */
    public static function currentMilestones(Version $version): array
    {
        $full = self::name($version->major, $version->minor, $version->patch);
        if ($version->patch === 0 && $version->minor === 0) {
            return [$full, self::name($version->major)];
        }
        return [$full];
    }

    /** The next patch milestone (issues move here). */
    public static function nextMilestone(Version $version): string
    {
        return self::name($version->major, $version->minor, $version->patch + 1);
    }

    /** The upcoming patch milestone (created so two stay open). */
    public static function upcomingMilestone(Version $version): string
    {
        return self::name($version->major, $version->minor, $version->patch + 2);
    }
}
