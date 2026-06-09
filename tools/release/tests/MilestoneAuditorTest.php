<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\Tests\Support\FakeGitHubApi;
use Nextcloud\ReleaseTools\MilestoneAuditor;
use Nextcloud\ReleaseTools\Version;
use PHPUnit\Framework\TestCase;

/**
 * What: the read-only audit verdicts for a major version given its latest
 * stable release.
 *
 * Why: ports the audit-milestones.sh scenarios so the consistency rules
 * (released-should-be-closed, next/upcoming present and open, upcoming has a
 * due date, no orphaned patch milestones) stay enforced after the migration.
 * The auditor must never mutate - only report.
 */
final class MilestoneAuditorTest extends TestCase
{
    private const REPO = 'nextcloud/server';

    private function audit(FakeGitHubApi $api): array
    {
        $warnings = (new MilestoneAuditor($api))->audit(Version::fromTag('v33.0.4'), [self::REPO]);
        $this->assertSame([], $api->journal, 'audit must be read-only');
        return $warnings;
    }

    public function testHealthyStateHasNoWarnings(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 4, 'Nextcloud 33.0.4', 'closed');
        $api->seedMilestone(self::REPO, 5, 'Nextcloud 33.0.5', 'open');
        $api->seedMilestone(self::REPO, 6, 'Nextcloud 33.0.6', 'open', 0, '2026-08-01T00:00:00Z');
        $this->assertSame([], $this->audit($api));
    }

    public function testReleasedStillOpenWarns(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 4, 'Nextcloud 33.0.4', 'open', 1);
        $api->seedMilestone(self::REPO, 5, 'Nextcloud 33.0.5', 'open');
        $api->seedMilestone(self::REPO, 6, 'Nextcloud 33.0.6', 'open', 0, '2026-08-01T00:00:00Z');
        $w = $this->audit($api);
        $this->assertContains(self::REPO . ": 'Nextcloud 33.0.4' still open (1 open issues) - should be closed", $w);
    }

    public function testMissingNextWarns(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 4, 'Nextcloud 33.0.4', 'closed');
        $api->seedMilestone(self::REPO, 6, 'Nextcloud 33.0.6', 'open', 0, '2026-08-01T00:00:00Z');
        $this->assertContains(self::REPO . ": missing milestone 'Nextcloud 33.0.5'", $this->audit($api));
    }

    public function testUpcomingWithoutDueWarns(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 4, 'Nextcloud 33.0.4', 'closed');
        $api->seedMilestone(self::REPO, 5, 'Nextcloud 33.0.5', 'open');
        $api->seedMilestone(self::REPO, 6, 'Nextcloud 33.0.6', 'open'); // no due
        $this->assertContains(self::REPO . ": 'Nextcloud 33.0.6' has no due date", $this->audit($api));
    }

    public function testOrphanWarns(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::REPO, 3, 'Nextcloud 33.0.3', 'open', 2); // older, still open
        $api->seedMilestone(self::REPO, 4, 'Nextcloud 33.0.4', 'closed');
        $api->seedMilestone(self::REPO, 5, 'Nextcloud 33.0.5', 'open');
        $api->seedMilestone(self::REPO, 6, 'Nextcloud 33.0.6', 'open', 0, '2026-08-01T00:00:00Z');
        $this->assertContains(self::REPO . ": orphan 'Nextcloud 33.0.3' still open (2 open issues) - version already released", $this->audit($api));
    }

    public function testNoMilestonesWarns(): void
    {
        $api = new FakeGitHubApi();
        $this->assertContains(self::REPO . ': no milestones found for Nextcloud 33', $this->audit($api));
    }
}
