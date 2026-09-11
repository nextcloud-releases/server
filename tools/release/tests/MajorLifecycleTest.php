<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\MajorLifecycle;
use Nextcloud\ReleaseTools\Tests\Support\FakeGitHubApi;
use Nextcloud\ReleaseTools\Version;
use PHPUnit\Framework\TestCase;

/**
 * What: deciding whether a release is the last of its major, from the
 * "Nextcloud N" milestone due date plus the 12-month maintenance window.
 *
 * Why: the pipeline has no other way to tell "the series is over" from "the
 * schedule is stale", and it got that wrong on v32.0.15, aborting the whole
 * milestone update. The comparison must be by month, because a maintenance
 * round shifts a week either way: 32 shipped 2025-09-27 and its last release
 * was 32.0.15 on 2026-09-10, 17 days inside a window that a day-precise check
 * would have called still open.
 *
 * Numbers below are the real ones from nextcloud/server.
 */
final class MajorLifecycleTest extends TestCase
{
    private const REPO = 'nextcloud/server';

    /** Seeds the major's own milestone plus the patch milestone being released. */
    private function api(?string $majorDue, ?string $patchDue, int $major = 32, string $patch = 'Nextcloud 32.0.15'): FakeGitHubApi
    {
        $api = new FakeGitHubApi();
        if ($majorDue !== null) {
            $api->seedMilestone(self::REPO, 1, "Nextcloud {$major}", 'closed', 0, $majorDue);
        }
        if ($patchDue !== null) {
            $api->seedMilestone(self::REPO, 2, $patch, 'open', 0, $patchDue);
        }
        return $api;
    }

    public function testEolMonthIsTwelveMonthsAfterTheMajorMilestone(): void
    {
        // "Nextcloud 32" was due 2025-09-27; updater_server records eol 2026-09.
        $l = new MajorLifecycle($this->api('2025-09-27T00:00:00Z', null));
        $this->assertSame('2026-09', $l->eolMonth(32));
    }

    public function testLastReleaseOfTheSeriesIsFinal(): void
    {
        // 32.0.15 on 2026-09-10: same month as the window end, so it is the last.
        $l = new MajorLifecycle($this->api('2025-09-27T00:00:00Z', '2026-09-10T00:00:00Z'));
        $this->assertTrue($l->isFinalRelease(Version::fromTag('v32.0.15')));
    }

    public function testReleaseAMonthBeforeTheWindowEndsIsNotFinal(): void
    {
        // 32.0.14 on 2026-08-13, 45 days before the window end: 32.0.15 followed.
        $l = new MajorLifecycle($this->api('2025-09-27T00:00:00Z', '2026-08-13T00:00:00Z', 32, 'Nextcloud 32.0.14'));
        $this->assertFalse($l->isFinalRelease(Version::fromTag('v32.0.14')));
    }

    public function testReleaseSlippingPastTheWindowIsStillFinal(): void
    {
        // Had 32.0.15 slipped into October, it is still the last release, not
        // the start of a new round: the comparison is >=, not ==.
        $l = new MajorLifecycle($this->api('2025-09-27T00:00:00Z', '2026-10-01T00:00:00Z'));
        $this->assertTrue($l->isFinalRelease(Version::fromTag('v32.0.15')));
    }

    public function testBacktestsTheThirtyOneSeries(): void
    {
        // "Nextcloud 31" was due 2025-02-25, so the window ends 2026-02.
        // 31.0.13 (2026-01-15) was followed by 31.0.14 (2026-02-12), which was
        // the last release: no v31.0.15 tag or milestone exists.
        $notYet = new MajorLifecycle($this->api('2025-02-25T00:00:00Z', '2026-01-15T00:00:00Z', 31, 'Nextcloud 31.0.13'));
        $this->assertFalse($notYet->isFinalRelease(Version::fromTag('v31.0.13')));

        $last = new MajorLifecycle($this->api('2025-02-25T00:00:00Z', '2026-02-12T00:00:00Z', 31, 'Nextcloud 31.0.14'));
        $this->assertTrue($last->isFinalRelease(Version::fromTag('v31.0.14')));
    }

    public function testAnInitialReleaseIsNeverFinal(): void
    {
        // v34.0.0 reads its month off the short "Nextcloud 34" milestone, the
        // very date the window is measured from.
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 1, 'Nextcloud 34', 'open', 0, '2026-06-09T00:00:00Z');
        $this->assertFalse((new MajorLifecycle($api))->isFinalRelease(Version::fromTag('v34.0.0')));
    }

    public function testUnknownReleaseDateIsNeverFinal(): void
    {
        // No "Nextcloud 32" milestone: refuse to end a series on a guess, so
        // the caller falls through to the hard failure on the missing schedule.
        $l = new MajorLifecycle($this->api(null, '2026-09-10T00:00:00Z'));
        $this->assertNull($l->eolMonth(32));
        $this->assertFalse($l->isFinalRelease(Version::fromTag('v32.0.15')));
    }

    public function testMajorMilestoneWithoutADueDateIsNeverFinal(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 1, 'Nextcloud 32', 'closed', 0, null);
        $this->assertNull((new MajorLifecycle($api))->eolMonth(32));
    }

    public function testReleaseMonthFallsBackToTodayWhenTheMilestoneHasNoDate(): void
    {
        // Nothing to read the release month from, so "now" stands in. A re-run
        // can only ever look later than the release, never earlier.
        $l = new MajorLifecycle($this->api('2025-09-27T00:00:00Z', null));
        $this->assertSame(gmdate('Y-m'), $l->releaseMonth(Version::fromTag('v32.0.15')));
    }

    public function testAddMonthsRollsTheYearOver(): void
    {
        $this->assertSame('2026-09', MajorLifecycle::addMonths('2025-09', 12));
        $this->assertSame('2027-12', MajorLifecycle::addMonths('2026-12', 12));
        $this->assertSame('2027-01', MajorLifecycle::addMonths('2026-12', 1));
        $this->assertSame('2026-01', MajorLifecycle::addMonths('2026-01', 0));
    }

    public function testIsReadOnly(): void
    {
        $api = $this->api('2025-09-27T00:00:00Z', '2026-09-10T00:00:00Z');
        (new MajorLifecycle($api))->isFinalRelease(Version::fromTag('v32.0.15'));
        $this->assertSame([], $api->journal);
    }

    public function testMaintainedMajorsExcludesThoseWhoseWindowHasClosed(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 1, 'Nextcloud 31', 'closed', 0, '2025-02-25T00:00:00Z');
        $api->seedMilestone(self::REPO, 2, 'Nextcloud 32', 'closed', 0, '2025-09-27T00:00:00Z');
        $api->seedMilestone(self::REPO, 3, 'Nextcloud 33', 'closed', 0, '2026-02-18T00:00:00Z');
        $api->seedMilestone(self::REPO, 4, 'Nextcloud 34', 'closed', 0, '2026-06-09T00:00:00Z');
        // Patch milestones must not be mistaken for majors.
        $api->seedMilestone(self::REPO, 5, 'Nextcloud 34.0.5', 'open', 0, '2026-10-15T00:00:00Z');

        $l = new MajorLifecycle($api);
        // 31 closed 2026-02-25; 32 closes 2026-09-27, so it is still in window.
        $this->assertSame([32, 33, 34], $l->maintainedMajors('2026-09-11'));
        // A fortnight later 32 has dropped out.
        $this->assertSame([33, 34], $l->maintainedMajors('2026-09-28'));
    }

    public function testMaintainedMajorsIgnoresAMajorWithoutADueDate(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 1, 'Nextcloud 35', 'open', 0, null);
        $api->seedMilestone(self::REPO, 2, 'Nextcloud 34', 'closed', 0, '2026-06-09T00:00:00Z');
        $this->assertSame([34], (new MajorLifecycle($api))->maintainedMajors('2026-09-11'));
    }

    public function testWindowEndIsTwelveMonthsAfterRelease(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 1, 'Nextcloud 34', 'closed', 0, '2026-06-09T00:00:00Z');
        $this->assertSame('2027-06-09', (new MajorLifecycle($api))->windowEnd(34));
    }

    public function testReleaseDateReadsTheVersionsOwnMilestone(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 1, 'Nextcloud 33.0.10', 'open', 0, '2026-10-15T00:00:00Z');
        $this->assertSame(
            '2026-10-15',
            (new MajorLifecycle($api))->releaseDate(Version::fromTag('v33.0.10rc1')),
        );
    }
}
