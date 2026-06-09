<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\MilestonePlan;
use Nextcloud\ReleaseTools\Version;
use PHPUnit\Framework\TestCase;

/**
 * What: the milestone-name math - current/next/upcoming names and the
 * first-beta -> next-major (N+1) rule.
 *
 * Why: this is exactly where the production bugs lived (first-beta off-by-one,
 * the two-open-patch invariant). Plain assertions, no fixtures.
 */
final class MilestonePlanTest extends TestCase
{
    public function testStablePatchPlan(): void
    {
        $v = Version::fromTag('v33.0.4');
        $this->assertSame(['Nextcloud 33.0.4'], MilestonePlan::currentMilestones($v));
        $this->assertSame('Nextcloud 33.0.5', MilestonePlan::nextMilestone($v));
        $this->assertSame('Nextcloud 33.0.6', MilestonePlan::upcomingMilestone($v));
    }

    public function testInitialReleaseAlsoClosesShortName(): void
    {
        $v = Version::fromTag('v34.0.0');
        $this->assertSame(['Nextcloud 34.0.0', 'Nextcloud 34'], MilestonePlan::currentMilestones($v));
        $this->assertSame('Nextcloud 34.0.1', MilestonePlan::nextMilestone($v));
        $this->assertSame('Nextcloud 34.0.2', MilestonePlan::upcomingMilestone($v));
    }

    public function testFirstBetaCreatesNextMajor(): void
    {
        // The bug that bit us: first beta of N must create N+1, not N.
        $this->assertSame('Nextcloud 35', MilestonePlan::firstBetaMilestone(Version::fromTag('v34.0.0beta1')));
        $this->assertSame('Nextcloud 36', MilestonePlan::firstBetaMilestone(Version::fromTag('v35.0.0beta1')));
    }

    public function testName(): void
    {
        $this->assertSame('Nextcloud 34', MilestonePlan::name(34));
        $this->assertSame('Nextcloud 34.0.2', MilestonePlan::name(34, 0, 2));
    }
}
