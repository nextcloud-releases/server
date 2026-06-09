<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\MilestoneUpdater;
use Nextcloud\ReleaseTools\Tests\Support\FakeGitHubApi;
use Nextcloud\ReleaseTools\Tests\Support\Journal;
use Nextcloud\ReleaseTools\Tests\Support\MatchesSnapshots;
use Nextcloud\ReleaseTools\Version;
use PHPUnit\Framework\TestCase;

/**
 * What: golden-file snapshots of the full milestone-update journal per release
 * scenario (the PHP successor to the old tests/milestone-scripts snapshot
 * harness).
 *
 * Why: alongside the focused assertions in MilestoneUpdaterTest, these pin the
 * entire mutation sequence as a reviewable artifact - the same "scenario ->
 * expected" feel as the bash harness, so a behaviour change shows up as a
 * readable snapshot diff. Update with UPDATE_SNAPSHOTS=1.
 */
final class MilestoneSnapshotTest extends TestCase
{
    use MatchesSnapshots;

    private const SERVER = 'nextcloud/server';
    private const ACTIVITY = 'nextcloud/activity';

    private function snapshot(string $description, FakeGitHubApi $api): string
    {
        return Journal::snapshot($description, Journal::MILESTONE_LEGEND, $api->journal);
    }

    public function testPatchRelease(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::SERVER, 10, 'Nextcloud 33.0.4', 'open', 2);
        $api->seedMilestone(self::SERVER, 11, 'Nextcloud 33.0.5');
        $api->seedIssue(self::SERVER, 100, 10);
        $api->seedIssue(self::SERVER, 101, 10);

        (new MilestoneUpdater($api))->run(Version::fromTag('v33.0.4'), [self::SERVER]);
        $this->assertMatchesSnapshot('milestones/patch-release', $this->snapshot('Patch release v33.0.4: move open issues to 33.0.5, close 33.0.4, open 33.0.6', $api));
    }

    public function testFirstStable(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::SERVER, 20, 'Nextcloud 34');
        (new MilestoneUpdater($api))->run(Version::fromTag('v34.0.0'), [self::SERVER]);
        $this->assertMatchesSnapshot('milestones/first-stable', $this->snapshot('First stable v34.0.0: close the Nextcloud 34 milestone, open 34.0.1 and 34.0.2', $api));
    }

    public function testFirstBeta(): void
    {
        $api = new FakeGitHubApi();
        (new MilestoneUpdater($api))->run(Version::fromTag('v35.0.0beta1'), [self::SERVER, self::ACTIVITY]);
        $this->assertMatchesSnapshot('milestones/first-beta', $this->snapshot('First beta v35.0.0beta1: open the next major milestone Nextcloud 36 in each repo', $api));
    }

    public function testMissingNext(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::SERVER, 10, 'Nextcloud 33.0.4', 'open', 1);
        $api->seedIssue(self::SERVER, 100, 10);
        (new MilestoneUpdater($api))->run(Version::fromTag('v33.0.4'), [self::SERVER]);
        $this->assertMatchesSnapshot('milestones/missing-next', $this->snapshot('Patch v33.0.4 when 33.0.5 is missing: create it first, then move/close/open 33.0.6', $api));
    }

    public function testDueDates(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::SERVER, 10, 'Nextcloud 33.0.4');
        $api->seedMilestone(self::SERVER, 11, 'Nextcloud 33.0.5', 'open', 0, '2026-06-25T00:00:00Z');
        $api->seedMilestone(self::SERVER, 12, 'Nextcloud 33.0.6');
        (new MilestoneUpdater($api))->run(
            Version::fromTag('v33.0.4'),
            [self::SERVER],
            '2026-07-02T00:00:00Z',
            '2026-08-27T00:00:00Z',
        );
        $this->assertMatchesSnapshot('milestones/due-dates', $this->snapshot('Patch v33.0.4 with due dates: set 33.0.5 and 33.0.6 due dates, close 33.0.4', $api));
    }

    public function testMultiRepo(): void
    {
        $api = new FakeGitHubApi();
        foreach ([self::SERVER, self::ACTIVITY] as $i => $repo) {
            $base = ($i + 1) * 10;
            $api->seedMilestone($repo, $base, 'Nextcloud 33.0.4', 'open', 1);
            $api->seedMilestone($repo, $base + 1, 'Nextcloud 33.0.5');
            $api->seedIssue($repo, 500 + $i, $base);
        }
        (new MilestoneUpdater($api))->run(Version::fromTag('v33.0.4'), [self::SERVER, self::ACTIVITY]);
        $this->assertMatchesSnapshot('milestones/multi-repo', $this->snapshot('Patch v33.0.4 across two repos', $api));
    }

    public function testPrereleaseNoop(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::SERVER, 10, 'Nextcloud 33.0.2');
        (new MilestoneUpdater($api))->run(Version::fromTag('v33.0.2rc1'), [self::SERVER]);
        $this->assertMatchesSnapshot('milestones/prerelease-noop', $this->snapshot('Non-first-beta pre-release v33.0.2rc1: nothing happens', $api));
    }
}
