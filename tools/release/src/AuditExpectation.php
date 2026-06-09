<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

/**
 * The expected milestone state for a major, derived from its latest stable
 * release - the rules audit-milestones.sh checks against.
 *
 * Given the latest stable vX.Y.Z: "Nextcloud X.Y.Z" should be closed, and
 * "X.Y.(Z+1)" and "X.Y.(Z+2)" should be open (the upcoming one with a due
 * date). Any open patch milestone at or below Z is an orphan.
 */
final class AuditExpectation
{
    public function __construct(
        public readonly Version $latestStable,
    ) {
    }

    /** @return list<string> milestone name(s) that should be closed */
    public function releasedMilestones(): array
    {
        return MilestonePlan::currentMilestones($this->latestStable);
    }

    public function nextMilestone(): string
    {
        return MilestonePlan::nextMilestone($this->latestStable);
    }

    public function upcomingMilestone(): string
    {
        return MilestonePlan::upcomingMilestone($this->latestStable);
    }

    /**
     * Whether an open milestone "Nextcloud X.Y.P" is an orphan (its version is
     * already released, so it should have been closed).
     */
    public function isOrphan(int $minor, int $patch): bool
    {
        return $minor === $this->latestStable->minor
            && $patch <= $this->latestStable->patch;
    }
}
