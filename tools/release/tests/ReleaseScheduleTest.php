<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\ReleaseSchedule;
use Nextcloud\ReleaseTools\Version;
use PHPUnit\Framework\TestCase;

/**
 * What: resolving milestone due dates from the committed release schedule.
 *
 * Why: the pipeline sets the next/upcoming due dates automatically from
 * release-schedule.json instead of a manual input. Resolution must fail hard
 * when a stable release has no date (so the schedule cannot silently fall out
 * of date), let explicit overrides win, and ask nothing of pre-releases.
 */
final class ReleaseScheduleTest extends TestCase
{
    private function schedule(array $map): ReleaseSchedule
    {
        $path = tempnam(sys_get_temp_dir(), 'sched') . '.json';
        file_put_contents($path, json_encode($map));
        try {
            return ReleaseSchedule::load($path);
        } finally {
            @unlink($path);
        }
    }

    public function testResolvesNextAndUpcomingFromSchedule(): void
    {
        $s = $this->schedule([
            'Nextcloud 33.0.5' => '2026-07-02',
            'Nextcloud 33.0.6' => '2026-08-27',
        ]);
        $this->assertSame(
            ['next' => '2026-07-02T00:00:00Z', 'upcoming' => '2026-08-27T00:00:00Z'],
            $s->resolve(Version::fromTag('v33.0.4')),
        );
    }

    public function testExplicitOverridesWinOverSchedule(): void
    {
        $s = $this->schedule([
            'Nextcloud 33.0.5' => '2026-07-02',
            'Nextcloud 33.0.6' => '2026-08-27',
        ]);
        $this->assertSame(
            ['next' => '2026-09-09T00:00:00Z', 'upcoming' => '2026-08-27T00:00:00Z'],
            $s->resolve(Version::fromTag('v33.0.4'), '2026-09-09'),
        );
    }

    public function testFailsHardWhenTheNextMilestoneIsMissing(): void
    {
        // 33.0.5 (the next, imminent release) is absent; 33.0.6 present.
        $s = $this->schedule(['Nextcloud 33.0.6' => '2026-08-27']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'Nextcloud 33.0.5'");
        $s->resolve(Version::fromTag('v33.0.4'));
    }

    public function testToleratesMissingUpcoming(): void
    {
        // The milestone after next (33.0.6) may not be scheduled yet: set next,
        // leave upcoming without a due date, do not fail.
        $s = $this->schedule(['Nextcloud 33.0.5' => '2026-07-02']);
        $this->assertSame(
            ['next' => '2026-07-02T00:00:00Z', 'upcoming' => null],
            $s->resolve(Version::fromTag('v33.0.4')),
        );
    }

    public function testNextOverrideCoversAnEmptySchedule(): void
    {
        $s = ReleaseSchedule::load(null);
        $this->assertSame(
            ['next' => '2026-07-02T00:00:00Z', 'upcoming' => null],
            $s->resolve(Version::fromTag('v33.0.4'), '2026-07-02'),
        );
    }

    public function testUpcomingOverrideIsAppliedWhenGiven(): void
    {
        $s = $this->schedule(['Nextcloud 33.0.5' => '2026-07-02']); // 33.0.6 not scheduled
        $this->assertSame(
            ['next' => '2026-07-02T00:00:00Z', 'upcoming' => '2026-08-27T00:00:00Z'],
            $s->resolve(Version::fromTag('v33.0.4'), null, '2026-08-27'),
        );
    }

    public function testPrereleaseNeedsNoDates(): void
    {
        $s = ReleaseSchedule::load(null); // empty schedule
        $this->assertSame(['next' => null, 'upcoming' => null], $s->resolve(Version::fromTag('v33.0.2rc1')));
        $this->assertSame(['next' => null, 'upcoming' => null], $s->resolve(Version::fromTag('v35.0.0beta1')));
    }

    public function testRejectsMalformedDate(): void
    {
        $s = $this->schedule([
            'Nextcloud 33.0.5' => '02-07-2026', // wrong format
            'Nextcloud 33.0.6' => '2026-08-27',
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $s->resolve(Version::fromTag('v33.0.4'));
    }

    public function testRejectsNonObjectJson(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sched') . '.json';
        file_put_contents($path, '"not an object"');
        try {
            $this->expectException(\RuntimeException::class);
            ReleaseSchedule::load($path);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsNonStringValues(): void
    {
        $s = fn () => $this->schedule(['Nextcloud 33.0.5' => 20260702]);
        $this->expectException(\RuntimeException::class);
        $s();
    }
}
