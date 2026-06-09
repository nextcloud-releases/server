<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\AuditExpectation;
use Nextcloud\ReleaseTools\Version;
use PHPUnit\Framework\TestCase;

/**
 * What: expected milestone names for a major + the orphan rule.
 *
 * Why: encodes what 'consistent' means for the audit (released closed, two
 * patch milestones open, older open patches are orphans).
 */
final class AuditExpectationTest extends TestCase
{
    public function testExpectedMilestonesForLatestStable(): void
    {
        $e = new AuditExpectation(Version::fromTag('v33.0.4'));
        $this->assertSame(['Nextcloud 33.0.4'], $e->releasedMilestones());
        $this->assertSame('Nextcloud 33.0.5', $e->nextMilestone());
        $this->assertSame('Nextcloud 33.0.6', $e->upcomingMilestone());
    }

    public function testOrphanDetection(): void
    {
        $e = new AuditExpectation(Version::fromTag('v33.0.4'));
        $this->assertTrue($e->isOrphan(0, 3), 'older patch on same minor is an orphan');
        $this->assertTrue($e->isOrphan(0, 4), 'the released patch is an orphan if still open');
        $this->assertFalse($e->isOrphan(0, 5), 'the next patch is not an orphan');
        $this->assertFalse($e->isOrphan(1, 1), 'a different minor is not an orphan');
    }
}
