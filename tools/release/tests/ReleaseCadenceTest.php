<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\ReleaseCadence;
use PHPUnit\Framework\TestCase;

/**
 * What: the maintenance round cadence, four weeks on and five when four would
 * land twice in one month.
 *
 * Why: the schedule was worked out by hand and went in a week early once
 * already. The five-week stretch is the part that matters: a plain 28-day chain
 * drifts backwards through the calendar and eventually puts two rounds in the
 * same month, which has never happened for a scheduled round.
 */
final class ReleaseCadenceTest extends TestCase
{
    public function testFourWeeksOn(): void
    {
        // The real October and November 2026 rounds.
        $this->assertSame('2026-11-12', ReleaseCadence::next('2026-10-15'));
        $this->assertSame('2026-12-10', ReleaseCadence::next('2026-11-12'));
    }

    public function testStretchesToFiveWeeksRatherThanDoubleUpInAMonth(): void
    {
        // 2027-04-01 + 28d is 2027-04-29, still April, so the round moves to May.
        $this->assertSame('2027-05-06', ReleaseCadence::next('2027-04-01'));
    }

    public function testEveryRoundStaysOnTheSameWeekday(): void
    {
        $date = '2026-10-15'; // a Thursday
        for ($i = 0; $i < 24; $i++) {
            $date = ReleaseCadence::next($date);
            $this->assertSame('Thu', (new \DateTimeImmutable($date))->format('D'), "round {$date}");
        }
    }

    public function testNeverPutsTwoRoundsInOneMonth(): void
    {
        $rounds = ReleaseCadence::following('2026-10-15', 36);
        $months = array_map(static fn (string $d) => substr($d, 0, 7), $rounds);
        $this->assertSame($months, array_values(array_unique($months)));
    }

    public function testNeverSkipsAMonth(): void
    {
        $rounds = ReleaseCadence::following('2026-10-15', 36);
        $months = array_map(static fn (string $d) => substr($d, 0, 7), $rounds);
        $expected = [];
        $cursor = new \DateTimeImmutable('2026-11-01');
        foreach ($months as $_) {
            $expected[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }
        $this->assertSame($expected, $months);
    }

    public function testFollowingReturnsTheNextRoundsInOrder(): void
    {
        $this->assertSame(
            ['2026-11-12', '2026-12-10'],
            ReleaseCadence::following('2026-10-15', 2),
        );
    }

    public function testFollowingStopsAtTheEndOfTheMaintenanceWindow(): void
    {
        // 32 left maintenance on 2026-09-27, so nothing after 32.0.15 fits.
        $this->assertSame([], ReleaseCadence::following('2026-09-10', 2, '2026-09-27'));
        // 34 leaves on 2027-06-09: the June round fits, July does not.
        $this->assertSame(['2027-06-03'], ReleaseCadence::following('2027-05-06', 2, '2027-06-09'));
    }

    public function testRejectsAMalformedDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReleaseCadence::next('15-10-2026');
    }
}
