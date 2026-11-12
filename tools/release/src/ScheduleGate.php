<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

use Nextcloud\ReleaseTools\GitHub\GitHubApi;

/**
 * Whether release-schedule.json is ready for a stable release, checked before
 * anything is tagged or built. Without it the milestone update fails on release
 * day, after the release has shipped, leaving its milestone open and its issues
 * unmoved (v32.0.13 to v34.0.3, eight releases).
 *
 * Two things block: the schedule pull request is still open, so the committed
 * file is behind what the next releases need; or the next patch milestone has
 * no date and the release is not the last of its series. Pre-releases roll no
 * milestones and are never blocked.
 */
final class ScheduleGate
{
    public const REPO = 'nextcloud-releases/server';
    public const BRANCH = 'chore/release-schedule';

    public function __construct(
        private readonly GitHubApi $api,
        private readonly MajorLifecycle $lifecycle,
    ) {
    }

    /** @return list<string> what blocks the release, empty when it can go ahead */
    public function problems(Version $version, ReleaseSchedule $schedule): array
    {
        if ($version->isPrerelease) {
            return [];
        }

        $problems = [];
        foreach ($this->api->openPullRequestNumbers(self::REPO, self::BRANCH) as $number) {
            $problems[] = sprintf(
                'The release schedule pull request is still open: merge it first. https://github.com/%s/pull/%d',
                self::REPO,
                $number,
            );
        }

        try {
            MilestoneDue::resolve($schedule, $version, $this->lifecycle);
        } catch (\RuntimeException $e) {
            $problems[] = $e->getMessage();
        }

        return $problems;
    }
}
