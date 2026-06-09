<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

use Nextcloud\ReleaseTools\GitHub\GitHubApi;

/**
 * Read-only consistency check of the milestones for a major version, given its
 * latest stable release. Reports (never changes) the issues audit-milestones.sh
 * looked for: a still-open released milestone, missing/closed next or upcoming,
 * an upcoming milestone without a due date, and orphaned patch milestones.
 */
final class MilestoneAuditor
{
    public function __construct(
        private readonly GitHubApi $api,
    ) {
    }

    /**
     * @param list<string> $repos
     * @return list<string> warnings, one per problem (empty = all good)
     */
    public function audit(Version $latestStable, array $repos): array
    {
        $expected = new AuditExpectation($latestStable);
        $major = $latestStable->major;
        $released = $expected->releasedMilestones();
        $next = $expected->nextMilestone();
        $upcoming = $expected->upcomingMilestone();

        $warnings = [];
        foreach ($repos as $repo) {
            $mine = array_values(array_filter(
                $this->api->listMilestones($repo),
                static fn ($m) => str_starts_with($m->title, "Nextcloud {$major}"),
            ));
            if ($mine === []) {
                $warnings[] = "{$repo}: no milestones found for Nextcloud {$major}";
                continue;
            }

            $byTitle = [];
            foreach ($mine as $m) {
                $byTitle[$m->title] = $m;
            }

            // Released milestone(s) should be closed.
            foreach ($released as $title) {
                $m = $byTitle[$title] ?? null;
                if ($m !== null && $m->state === 'open') {
                    $warnings[] = "{$repo}: '{$title}' still open ({$m->openIssues} open issues) - should be closed";
                }
            }

            // Next milestone should exist and be open.
            $n = $byTitle[$next] ?? null;
            if ($n === null) {
                $warnings[] = "{$repo}: missing milestone '{$next}'";
            } elseif ($n->state !== 'open') {
                $warnings[] = "{$repo}: '{$next}' is {$n->state} - should be open";
            }

            // Upcoming milestone should exist, be open, and have a due date.
            $u = $byTitle[$upcoming] ?? null;
            if ($u === null) {
                $warnings[] = "{$repo}: missing milestone '{$upcoming}'";
            } else {
                if ($u->state !== 'open') {
                    $warnings[] = "{$repo}: '{$upcoming}' is {$u->state} - should be open";
                }
                if ($u->dueOn === null) {
                    $warnings[] = "{$repo}: '{$upcoming}' has no due date";
                }
            }

            // Orphans: open patch milestones at or below the released version.
            foreach ($mine as $m) {
                if ($m->state !== 'open') {
                    continue;
                }
                if (preg_match("/^Nextcloud {$major}\\.(\\d+)\\.(\\d+)$/", $m->title, $mt) === 1
                    && $expected->isOrphan((int) $mt[1], (int) $mt[2])) {
                    $warnings[] = "{$repo}: orphan '{$m->title}' still open ({$m->openIssues} open issues) - version already released";
                }
            }
        }
        return $warnings;
    }
}
