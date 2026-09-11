<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\MajorLifecycle;
use Nextcloud\ReleaseTools\ScheduleRefresh;
use Nextcloud\ReleaseTools\Tests\Support\FakeGitHubApi;
use PHPUnit\Framework\TestCase;

/**
 * What: bringing release-schedule.json up to date for every maintained major
 * without being told which release is happening.
 *
 * Why: the previous version took the release tag, so it only ran when a
 * candidate fired and covered one major per run. Deriving the state from the
 * repositories makes it idempotent and lets one run cover every major, which
 * is also what removes the shared-branch handling from the workflow.
 *
 * Dates below are the real ones: majors 33 and 34 due 2026-02-18 and
 * 2026-06-09, so their windows close in February and June 2027, and 32 due
 * 2025-09-27 with its window closing 2026-09-27.
 */
final class ScheduleRefreshTest extends TestCase
{
    private const MILESTONES = 'nextcloud/server';
    private const TAGS = 'nextcloud-releases/server';

    private function api(): FakeGitHubApi
    {
        $api = new FakeGitHubApi();
        // Major milestones: what the maintenance window is measured from.
        $api->seedMilestone(self::MILESTONES, 1, 'Nextcloud 31', 'closed', 0, '2025-02-25T00:00:00Z');
        $api->seedMilestone(self::MILESTONES, 2, 'Nextcloud 32', 'closed', 0, '2025-09-27T00:00:00Z');
        $api->seedMilestone(self::MILESTONES, 3, 'Nextcloud 33', 'closed', 0, '2026-02-18T00:00:00Z');
        $api->seedMilestone(self::MILESTONES, 4, 'Nextcloud 34', 'closed', 0, '2026-06-09T00:00:00Z');
        // Patch milestones carry the round dates.
        $api->seedMilestone(self::MILESTONES, 10, 'Nextcloud 33.0.10', 'open', 0, '2026-10-15T00:00:00Z');
        $api->seedMilestone(self::MILESTONES, 11, 'Nextcloud 34.0.5', 'open', 0, '2026-10-15T00:00:00Z');
        $api->seedMilestone(self::MILESTONES, 12, 'Nextcloud 32.0.15', 'closed', 0, '2026-09-10T00:00:00Z');
        return $api;
    }

    private function refresh(FakeGitHubApi $api): ScheduleRefresh
    {
        return new ScheduleRefresh($api, new MajorLifecycle($api));
    }

    public function testCandidateTagsPutTheRoundInFlight(): void
    {
        // The October round is at rc: 33.0.10 and 34.0.5 have not shipped, but
        // the schedule must already look past them.
        $api = $this->api();
        foreach (['v33.0.9', 'v33.0.10rc1', 'v34.0.4', 'v34.0.5rc1', 'v32.0.15'] as $t) {
            $api->seedTag(self::TAGS, $t);
        }

        $updated = $this->refresh($api)->apply([
            'Nextcloud 33.0.10' => '2026-10-15',
            'Nextcloud 33.0.11' => '2026-11-12',
            'Nextcloud 34.0.5' => '2026-10-15',
            'Nextcloud 34.0.6' => '2026-11-12',
        ], 2, '2026-10-08');

        $this->assertSame([
            'Nextcloud 33.0.11' => '2026-11-12',
            'Nextcloud 33.0.12' => '2026-12-10',
            'Nextcloud 34.0.6' => '2026-11-12',
            'Nextcloud 34.0.7' => '2026-12-10',
        ], $updated);
    }

    public function testIsIdempotent(): void
    {
        $api = $this->api();
        foreach (['v33.0.9', 'v33.0.10rc1', 'v34.0.4', 'v34.0.5rc1'] as $t) {
            $api->seedTag(self::TAGS, $t);
        }
        $schedule = ['Nextcloud 33.0.10' => '2026-10-15', 'Nextcloud 34.0.5' => '2026-10-15'];

        $once = $this->refresh($api)->apply($schedule, 2, '2026-10-08');
        $twice = $this->refresh($api)->apply($once, 2, '2026-10-08');

        $this->assertSame($once, $twice);
    }

    public function testEolMajorGetsNoEntriesAndItsOwnAreDropped(): void
    {
        // 32's window closed 2026-09-27, so no 32.0.16 may be scheduled, and
        // running after that date drops 32 from the maintained set entirely.
        $api = $this->api();
        foreach (['v32.0.15', 'v33.0.9'] as $t) {
            $api->seedTag(self::TAGS, $t);
        }

        $inWindow = $this->refresh($api)->apply(['Nextcloud 32.0.15' => '2026-09-10'], 2, '2026-09-11');
        $this->assertArrayNotHasKey('Nextcloud 32.0.16', $inWindow);
        $this->assertArrayNotHasKey('Nextcloud 32.0.15', $inWindow, 'the shipped entry is dropped');
    }

    public function testMajorsPastTheirWindowAreNotConsidered(): void
    {
        // 31 left maintenance on 2026-02-25 and must not appear at all.
        $api = $this->api();
        $api->seedTag(self::TAGS, 'v31.0.14');
        $api->seedTag(self::TAGS, 'v33.0.9');

        $refresh = $this->refresh($api);
        $refresh->apply([], 2, '2026-10-08');

        $this->assertSame([], array_filter($refresh->log, static fn (string $l) => str_contains($l, ' 31:')));
    }

    public function testStaleEntriesForAlreadyShippedRoundsAreCleanedUp(): void
    {
        // A skipped round left 33.0.9 behind; it is at or below what shipped.
        $api = $this->api();
        $api->seedTag(self::TAGS, 'v33.0.10');

        $updated = $this->refresh($api)->apply([
            'Nextcloud 33.0.9' => '2026-09-10',
            'Nextcloud 33.0.10' => '2026-10-15',
        ], 2, '2026-10-20');

        $this->assertSame([
            'Nextcloud 33.0.11' => '2026-11-12',
            'Nextcloud 33.0.12' => '2026-12-10',
        ], $updated);
    }

    public function testAMajorWithNothingTaggedIsSkipped(): void
    {
        $api = $this->api();
        $api->seedTag(self::TAGS, 'v33.0.10');
        $refresh = $this->refresh($api);
        $refresh->apply([], 2, '2026-10-20');

        $this->assertContains('  34: nothing tagged yet, skipping', $refresh->log);
    }

    public function testAnUndatedRoundWarnsRatherThanGuessing(): void
    {
        // 34.0.6 is tagged but neither the schedule nor its milestone says when.
        $api = $this->api();
        $api->seedTag(self::TAGS, 'v34.0.6');
        $refresh = $this->refresh($api);
        $refresh->apply([], 2, '2026-11-20');

        $this->assertContains(
            "::warning::34: no date for 'Nextcloud 34.0.6' in the schedule or on its milestone, skipping",
            $refresh->log,
        );
    }

    public function testWarnsWhenNoMajorLooksMaintained(): void
    {
        $api = new FakeGitHubApi();
        $refresh = $this->refresh($api);
        $this->assertSame([], $refresh->apply([], 2, '2026-10-08'));
        $this->assertStringContainsString('No major is inside its maintenance window', $refresh->log[0]);
    }

    public function testIsReadOnlyAgainstGitHub(): void
    {
        $api = $this->api();
        $api->seedTag(self::TAGS, 'v33.0.10rc1');
        $this->refresh($api)->apply([], 2, '2026-10-08');
        $this->assertSame([], $api->journal);
    }
}
