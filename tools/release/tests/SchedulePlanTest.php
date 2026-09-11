<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\SchedulePlan;
use Nextcloud\ReleaseTools\Version;
use PHPUnit\Framework\TestCase;

/**
 * What: the change to release-schedule.json a release implies, proposed on the
 * rc so the release team has a week to check it against the wiki.
 *
 * Why: v32.0.15 aborted the milestones step because nobody had added a date for
 * the next round. The plan keeps two future milestones dated and drops the one
 * shipping now, without ever re-dating an entry a human already set: the wiki
 * is the authority and the cadence only fills gaps.
 */
final class SchedulePlanTest extends TestCase
{
    /** The schedule as it stands for the October 2026 round. */
    private const OCTOBER = [
        'Nextcloud 33.0.10' => '2026-10-15',
        'Nextcloud 33.0.11' => '2026-11-12',
        'Nextcloud 34.0.5' => '2026-10-15',
        'Nextcloud 34.0.6' => '2026-11-12',
    ];

    public function testDatesTheFollowingRoundAndDropsTheShippingOne(): void
    {
        $plan = SchedulePlan::forRelease(
            Version::fromTag('v33.0.10rc1'),
            self::OCTOBER,
            '2026-10-15',
            '2027-02-18',
        );

        // 33.0.11 is already dated, so only 33.0.12 is missing.
        $this->assertSame(['Nextcloud 33.0.12' => '2026-12-10'], $plan->add);
        $this->assertSame(['Nextcloud 33.0.10'], $plan->remove);
    }

    public function testAppliedScheduleKeepsTwoRoundsPerMajor(): void
    {
        $schedule = self::OCTOBER;
        foreach (['v33.0.10rc1', 'v34.0.5rc1'] as $tag) {
            $schedule = SchedulePlan::forRelease(Version::fromTag($tag), $schedule, '2026-10-15', '2027-06-09')
                ->applyTo($schedule);
        }

        $this->assertSame([
            'Nextcloud 33.0.11' => '2026-11-12',
            'Nextcloud 33.0.12' => '2026-12-10',
            'Nextcloud 34.0.6' => '2026-11-12',
            'Nextcloud 34.0.7' => '2026-12-10',
        ], $schedule);
    }

    public function testNeverRedatesAnEntryAHumanAlreadySet(): void
    {
        // The wiki moved the round a week later than the cadence would pick.
        // Keep 2026-11-19 and chain the next round from it, not from 11-12.
        $plan = SchedulePlan::forRelease(
            Version::fromTag('v33.0.10rc1'),
            ['Nextcloud 33.0.10' => '2026-10-15', 'Nextcloud 33.0.11' => '2026-11-19'],
            '2026-10-15',
            '2027-02-18',
        );
        $this->assertSame(['Nextcloud 33.0.12' => '2026-12-17'], $plan->add);
    }

    public function testFillsBothRoundsWhenNeitherIsScheduled(): void
    {
        $plan = SchedulePlan::forRelease(
            Version::fromTag('v33.0.10rc1'),
            ['Nextcloud 33.0.10' => '2026-10-15'],
            '2026-10-15',
            '2027-02-18',
        );
        $this->assertSame([
            'Nextcloud 33.0.11' => '2026-11-12',
            'Nextcloud 33.0.12' => '2026-12-10',
        ], $plan->add);
    }

    public function testSchedulesNothingPastEndOfLife(): void
    {
        // 32 left maintenance on 2026-09-27, so 32.0.16 must never be scheduled.
        $plan = SchedulePlan::forRelease(
            Version::fromTag('v32.0.15rc1'),
            ['Nextcloud 32.0.15' => '2026-09-10'],
            '2026-09-10',
            '2026-09-27',
        );
        $this->assertSame([], $plan->add);
        $this->assertSame(['Nextcloud 32.0.15'], $plan->remove);
    }

    public function testStopsAtTheWindowEvenMidChain(): void
    {
        // 34 leaves on 2027-06-09: 34.0.13 on 2027-06-03 fits, 34.0.14 does not.
        $plan = SchedulePlan::forRelease(
            Version::fromTag('v34.0.12rc1'),
            ['Nextcloud 34.0.12' => '2027-05-06'],
            '2027-05-06',
            '2027-06-09',
        );
        $this->assertSame(['Nextcloud 34.0.13' => '2027-06-03'], $plan->add);
    }

    public function testNothingToDoWhenBothRoundsAreDatedAndNothingIsShipping(): void
    {
        $plan = SchedulePlan::forRelease(
            Version::fromTag('v33.0.10'),
            ['Nextcloud 33.0.11' => '2026-11-12', 'Nextcloud 33.0.12' => '2026-12-10'],
            '2026-10-15',
        );
        $this->assertTrue($plan->isEmpty());
    }

    public function testAnUnknownWindowSchedulesNormally(): void
    {
        // No window end (the major's release date could not be read): keep
        // scheduling rather than silently stopping a live series.
        $plan = SchedulePlan::forRelease(
            Version::fromTag('v33.0.10'),
            ['Nextcloud 33.0.10' => '2026-10-15'],
            '2026-10-15',
            null,
        );
        $this->assertSame([
            'Nextcloud 33.0.11' => '2026-11-12',
            'Nextcloud 33.0.12' => '2026-12-10',
        ], $plan->add);
    }

    public function testAnEolMajorEmptiesItsEntriesEntirely(): void
    {
        // 32.0.15 was the last of the series: drop its entry, add nothing.
        $plan = SchedulePlan::forRelease(
            Version::fromTag('v32.0.15rc1'),
            ['Nextcloud 32.0.15' => '2026-09-10'],
            '2026-09-10',
            '2026-09-27',
        );
        $this->assertSame([], $plan->applyTo(['Nextcloud 32.0.15' => '2026-09-10']));
    }

    public function testOrdersByVersionSoNineSortsBeforeTen(): void
    {
        $plan = SchedulePlan::forRelease(
            Version::fromTag('v33.0.9'),
            ['Nextcloud 33.0.9' => '2026-09-10', 'Nextcloud 34.0.5' => '2026-10-15'],
            '2026-09-10',
        );
        $this->assertSame(
            ['Nextcloud 33.0.10', 'Nextcloud 33.0.11', 'Nextcloud 34.0.5'],
            array_keys($plan->applyTo([
                'Nextcloud 33.0.9' => '2026-09-10',
                'Nextcloud 34.0.5' => '2026-10-15',
            ])),
        );
    }

    public function testInitialStableSchedulesItsFirstPatches(): void
    {
        // v35.0.0rc4: once 35.0.0 ships, 35.0.1 and 35.0.2 are the next rounds.
        $plan = SchedulePlan::forRelease(
            Version::fromTag('v35.0.0rc4'),
            [],
            '2026-09-17',
            '2027-09-17',
        );
        $this->assertSame([
            'Nextcloud 35.0.1' => '2026-10-15',
            'Nextcloud 35.0.2' => '2026-11-12',
        ], $plan->add);
        $this->assertSame([], $plan->remove);
    }
}
