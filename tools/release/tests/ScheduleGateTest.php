<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\MajorLifecycle;
use Nextcloud\ReleaseTools\ReleaseSchedule;
use Nextcloud\ReleaseTools\ScheduleGate;
use Nextcloud\ReleaseTools\Tests\Support\FakeGitHubApi;
use Nextcloud\ReleaseTools\Version;
use PHPUnit\Framework\TestCase;

/**
 * What: the pre-release check that the release schedule is ready.
 *
 * Why: eight stable releases (v32.0.13 to v34.0.3) shipped and then failed the
 * milestone update, because release-schedule.json had no date for their next
 * patch. The gate runs before anything is tagged, so the same releases now
 * stop before they start. It must still let the last release of a series
 * through (v32.0.15 has no 32.0.16 to schedule) and never block pre-releases.
 */
final class ScheduleGateTest extends TestCase
{
    private const SERVER = 'nextcloud/server';

    /** @param array<string, string> $entries */
    private function schedule(array $entries): ReleaseSchedule
    {
        $path = tempnam(sys_get_temp_dir(), 'sched') . '.json';
        file_put_contents($path, json_encode($entries));
        try {
            return ReleaseSchedule::load($path);
        } finally {
            @unlink($path);
        }
    }

    /** "Nextcloud 34" released 2026-06-09, so 34.0.3 in August is mid-series. */
    private function api(): FakeGitHubApi
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::SERVER, 1, 'Nextcloud 34', 'closed', 0, '2026-06-09T00:00:00Z');
        $api->seedMilestone(self::SERVER, 2, 'Nextcloud 34.0.3', 'open', 0, '2026-08-20T00:00:00Z');
        $api->seedMilestone(self::SERVER, 3, 'Nextcloud 32', 'closed', 0, '2025-09-27T00:00:00Z');
        $api->seedMilestone(self::SERVER, 4, 'Nextcloud 32.0.15', 'open', 0, '2026-09-10T00:00:00Z');
        return $api;
    }

    /** @return list<string> */
    private function problems(FakeGitHubApi $api, string $tag, ReleaseSchedule $schedule): array
    {
        return (new ScheduleGate($api, new MajorLifecycle($api)))->problems(Version::fromTag($tag), $schedule);
    }

    public function testReadyScheduleLetsTheReleaseThrough(): void
    {
        $this->assertSame([], $this->problems($this->api(), 'v34.0.3', $this->schedule(['Nextcloud 34.0.4' => '2026-09-17'])));
    }

    public function testMissingNextDateBlocksAMidSeriesRelease(): void
    {
        $problems = $this->problems($this->api(), 'v34.0.3', $this->schedule([]));

        $this->assertCount(1, $problems);
        $this->assertStringContainsString("No due date for 'Nextcloud 34.0.4'", $problems[0]);
    }

    public function testOpenSchedulePullRequestBlocksEvenWithADate(): void
    {
        $api = $this->api();
        $api->seedPullRequest(ScheduleGate::REPO, ScheduleGate::BRANCH, 136);

        $problems = $this->problems($api, 'v34.0.3', $this->schedule(['Nextcloud 34.0.4' => '2026-09-17']));

        $this->assertSame(
            ['The release schedule pull request is still open: merge it first. https://github.com/nextcloud-releases/server/pull/136'],
            $problems,
        );
    }

    public function testOpenPullRequestOnAnotherBranchDoesNotBlock(): void
    {
        $api = $this->api();
        $api->seedPullRequest(ScheduleGate::REPO, 'fix/something-else', 137);

        $this->assertSame([], $this->problems($api, 'v34.0.3', $this->schedule(['Nextcloud 34.0.4' => '2026-09-17'])));
    }

    public function testLastReleaseOfASeriesNeedsNoNextDate(): void
    {
        $this->assertSame([], $this->problems($this->api(), 'v32.0.15', $this->schedule([])));
    }

    public function testBothProblemsAreReportedTogether(): void
    {
        $api = $this->api();
        $api->seedPullRequest(ScheduleGate::REPO, ScheduleGate::BRANCH, 136);

        $this->assertCount(2, $this->problems($api, 'v34.0.3', $this->schedule([])));
    }

    public function testPreReleasesAreNeverBlocked(): void
    {
        $api = $this->api();
        $api->seedPullRequest(ScheduleGate::REPO, ScheduleGate::BRANCH, 136);

        $this->assertSame([], $this->problems($api, 'v34.0.4rc1', $this->schedule([])));
    }
}
