<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\GitHub\FakeGitHubApi;
use Nextcloud\ReleaseTools\MilestoneUpdater;
use Nextcloud\ReleaseTools\Version;
use PHPUnit\Framework\TestCase;

/**
 * What: end-to-end behaviour of the milestone updater against an in-memory
 * GitHub, asserting the exact sequence of mutations (the "journal").
 *
 * Why: this replaces the bash + fake-gh snapshot scenarios. It pins every
 * release shape we rely on - patch release, initial .0.0, first beta, missing
 * next, all-exist, due dates (create vs update), pre-release no-op, large issue
 * sets, and dry-run - and guards the bugs that bit the bash version
 * (issues left behind, first-beta off-by-one, dates not applied to existing
 * milestones, mutations during dry-run).
 */
final class MilestoneUpdaterTest extends TestCase
{
    private const REPO = 'nextcloud/server';

    public function testPatchReleaseMovesIssuesClosesAndCreatesUpcoming(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 10, 'Nextcloud 33.0.4', 'open', 2);
        $api->seedMilestone(self::REPO, 11, 'Nextcloud 33.0.5');
        $api->seedIssue(self::REPO, 100, 10);
        $api->seedIssue(self::REPO, 101, 10);

        $u = new MilestoneUpdater($api);
        $u->run(Version::fromTag('v33.0.4'), [self::REPO]);

        $this->assertSame([
            "move\t" . self::REPO . "\t100\t11",
            "move\t" . self::REPO . "\t101\t11",
            "close\t" . self::REPO . "\t10",
            "create\t" . self::REPO . "\tNextcloud 33.0.6\tdue=-",
        ], $api->journal);
        $this->assertSame([1, 1, 2], [$u->created, $u->closed, $u->moved]);
    }

    public function testInitialReleaseClosesShortNameAndCreatesNext(): void
    {
        $api = new FakeGitHubApi();
        // Only the short "Nextcloud 34" milestone exists; .0.1/.0.2 missing.
        $api->seedMilestone(self::REPO, 20, 'Nextcloud 34');

        $u = new MilestoneUpdater($api);
        $u->run(Version::fromTag('v34.0.0'), [self::REPO]);

        $this->assertSame([
            "create\t" . self::REPO . "\tNextcloud 34.0.1\tdue=-",
            "close\t" . self::REPO . "\t20",
            "create\t" . self::REPO . "\tNextcloud 34.0.2\tdue=-",
        ], $api->journal);
    }

    public function testFirstBetaCreatesNextMajorOnly(): void
    {
        $api = new FakeGitHubApi();
        $u = new MilestoneUpdater($api);
        $u->run(Version::fromTag('v35.0.0beta1'), [self::REPO]);

        // First beta of 35 opens 36.
        $this->assertSame(["create\t" . self::REPO . "\tNextcloud 36\tdue=-"], $api->journal);
    }

    public function testFirstBetaIdempotent(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 30, 'Nextcloud 36');
        $u = new MilestoneUpdater($api);
        $u->run(Version::fromTag('v35.0.0beta1'), [self::REPO]);
        $this->assertSame([], $api->journal);
    }

    public function testMissingNextIsCreatedBeforeMoving(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 10, 'Nextcloud 33.0.4', 'open', 1);
        $api->seedIssue(self::REPO, 100, 10);

        $u = new MilestoneUpdater($api);
        $u->run(Version::fromTag('v33.0.4'), [self::REPO]);

        $this->assertSame([
            "create\t" . self::REPO . "\tNextcloud 33.0.5\tdue=-",
            "move\t" . self::REPO . "\t100\t11",
            "close\t" . self::REPO . "\t10",
            "create\t" . self::REPO . "\tNextcloud 33.0.6\tdue=-",
        ], $api->journal);
    }

    public function testExistingUpcomingIsNotRecreated(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 10, 'Nextcloud 33.0.4', 'open', 1);
        $api->seedMilestone(self::REPO, 11, 'Nextcloud 33.0.5');
        $api->seedMilestone(self::REPO, 12, 'Nextcloud 33.0.6');
        $api->seedIssue(self::REPO, 100, 10);

        $u = new MilestoneUpdater($api);
        $u->run(Version::fromTag('v33.0.4'), [self::REPO]);

        $this->assertSame([
            "move\t" . self::REPO . "\t100\t11",
            "close\t" . self::REPO . "\t10",
        ], $api->journal);
    }

    public function testDueDatesCreateAndUpdate(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 10, 'Nextcloud 33.0.4');
        $api->seedMilestone(self::REPO, 11, 'Nextcloud 33.0.5'); // exists, no due -> setdue

        $u = new MilestoneUpdater($api);
        $u->run(Version::fromTag('v33.0.4'), [self::REPO], '2026-07-02T00:00:00Z', '2026-08-27T00:00:00Z');

        $this->assertSame([
            "setdue\t" . self::REPO . "\t11\t2026-07-02T00:00:00Z",
            "close\t" . self::REPO . "\t10",
            "create\t" . self::REPO . "\tNextcloud 33.0.6\tdue=2026-08-27T00:00:00Z",
        ], $api->journal);
    }

    public function testDueDatesCorrectExistingMilestones(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 10, 'Nextcloud 33.0.4');
        $api->seedMilestone(self::REPO, 11, 'Nextcloud 33.0.5', 'open', 0, '2026-06-25T00:00:00Z');
        $api->seedMilestone(self::REPO, 12, 'Nextcloud 33.0.6'); // no due

        $u = new MilestoneUpdater($api);
        $u->run(Version::fromTag('v33.0.4'), [self::REPO], '2026-07-02T00:00:00Z', '2026-08-27T00:00:00Z');

        $this->assertSame([
            "setdue\t" . self::REPO . "\t11\t2026-07-02T00:00:00Z",
            "close\t" . self::REPO . "\t10",
            "setdue\t" . self::REPO . "\t12\t2026-08-27T00:00:00Z",
        ], $api->journal);
    }

    public function testNonFirstBetaPrereleaseIsNoop(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 10, 'Nextcloud 33.0.2');
        $u = new MilestoneUpdater($api);
        $u->run(Version::fromTag('v33.0.2rc1'), [self::REPO]);
        $this->assertSame([], $api->journal);
    }

    public function testMovesAllIssuesNoneLeftBehind(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 10, 'Nextcloud 33.0.4', 'open', 150);
        $api->seedMilestone(self::REPO, 11, 'Nextcloud 33.0.5');
        for ($i = 1; $i <= 150; $i++) {
            $api->seedIssue(self::REPO, $i, 10);
        }

        $u = new MilestoneUpdater($api);
        $u->run(Version::fromTag('v33.0.4'), [self::REPO]);

        $this->assertSame(150, $u->moved);
        $this->assertSame(150, substr_count(implode("\n", $api->journal), "move\t"));
    }

    public function testDryRunChangesNothing(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 10, 'Nextcloud 33.0.4', 'open', 1);
        $api->seedMilestone(self::REPO, 11, 'Nextcloud 33.0.5');
        $api->seedIssue(self::REPO, 100, 10);

        $u = new MilestoneUpdater($api, dryRun: true);
        $u->run(Version::fromTag('v33.0.4'), [self::REPO]);

        $this->assertSame([], $api->journal, 'dry-run must not mutate');
        $this->assertNotEmpty($u->log);
        $this->assertStringContainsString('would', strtolower(implode("\n", $u->log)));
    }
}
