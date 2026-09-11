<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

use Nextcloud\ReleaseTools\GitHub\GitHubApi;

/**
 * Brings release-schedule.json up to date for every maintained major, working
 * entirely from what the repositories already say: which majors are still
 * inside their 12-month window, and the highest version tagged for each.
 *
 * Nothing has to be told which release is happening. A candidate tag counts as
 * in flight, so at v33.0.10rc1 the schedule is brought past 33.0.10 exactly as
 * it would be once 33.0.10 ships. That makes the result idempotent: running it
 * twice, or a week late, converges on the same file.
 */
final class ScheduleRefresh
{
    /** Tags live here; the milestones MajorLifecycle reads live on nextcloud/server. */
    private const TAG_REPO = 'nextcloud-releases/server';

    /** @var list<string> what happened, one line per major */
    public array $log = [];

    public function __construct(
        private readonly GitHubApi $api,
        private readonly MajorLifecycle $lifecycle,
    ) {
    }

    /**
     * @param array<string, string> $schedule title => YYYY-MM-DD, as committed
     * @param int $depth how many future milestones to keep dated per major
     * @param ?string $asOf which day to judge maintenance windows against
     * @return array<string, string> the schedule as it should be
     */
    public function apply(array $schedule, int $depth = 2, ?string $asOf = null): array
    {
        $majors = $this->lifecycle->maintainedMajors($asOf);
        if ($majors === []) {
            $this->log[] = '::warning::No major is inside its maintenance window; is the "Nextcloud N" milestone missing?';
            return $schedule;
        }

        $tags = $this->api->listTagNames(self::TAG_REPO);
        foreach ($majors as $major) {
            $schedule = $this->applyMajor($major, $tags, $schedule, $depth);
        }
        return $schedule;
    }

    /**
     * @param list<string> $tags
     * @param array<string, string> $schedule
     * @return array<string, string>
     */
    private function applyMajor(int $major, array $tags, array $schedule, int $depth): array
    {
        $latest = ReleaseConfig::latestInFlight($major, $tags);
        if ($latest === null) {
            $this->log[] = "  {$major}: nothing tagged yet, skipping";
            return $schedule;
        }

        $title = MilestonePlan::name($latest->major, $latest->minor, $latest->patch);
        // The round date of the version in flight: its own schedule entry when
        // it has one, else the due date the release team set on its milestone.
        $anchor = $schedule[$title] ?? $this->lifecycle->releaseDate($latest);
        if ($anchor === null) {
            $this->log[] = "::warning::{$major}: no date for '{$title}' in the schedule or on its milestone, skipping";
            return $schedule;
        }

        $windowEnd = $this->lifecycle->windowEnd($major);
        $plan = SchedulePlan::forRelease($latest, $schedule, $anchor, $windowEnd, $depth);

        if ($plan->isEmpty()) {
            $this->log[] = "  {$major}: up to date ({$title} due {$anchor})";
            return $schedule;
        }

        $this->log[] = "  {$major}: {$title} due {$anchor}";
        foreach ($plan->add as $added => $date) {
            $this->log[] = "    + {$added}: {$date}";
        }
        foreach ($plan->remove as $removed) {
            $this->log[] = "    - {$removed} (shipped)";
        }
        if ($plan->add === []) {
            $this->log[] = "    no further rounds, maintenance ends {$windowEnd}";
        }
        return $plan->applyTo($schedule);
    }
}
