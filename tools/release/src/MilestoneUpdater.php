<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

use Nextcloud\ReleaseTools\GitHub\GitHubApi;
use Nextcloud\ReleaseTools\GitHub\Milestone;

/**
 * Applies the milestone changes for a release across repositories.
 *
 *  - First beta (vN.0.0beta1): create the next major milestone "Nextcloud N+1".
 *  - Stable (vX.Y.Z): close X.Y.Z, move its open issues to X.Y.(Z+1), and make
 *    sure two patch milestones stay open (X.Y.(Z+1) and X.Y.(Z+2)). Optional
 *    due dates are applied to those two whether they are created now or already
 *    exist.
 *  - Stable that ends its series ($seriesFinal): close X.Y.Z and roll nothing
 *    forward, since no further patch is coming.
 *  - Any other pre-release: no-op.
 *
 * Reads always hit the API; writes are skipped in dry-run (logged as "Would ...").
 */
final class MilestoneUpdater
{
    public int $closed = 0;
    public int $created = 0;
    public int $moved = 0;
    /** @var list<string> */
    public array $log = [];

    public function __construct(
        private readonly GitHubApi $api,
        private readonly bool $dryRun = false,
    ) {
    }

    /** @param list<string> $repos */
    public function run(Version $version, array $repos, ?string $nextDueOn = null, ?string $upcomingDueOn = null, bool $seriesFinal = false): void
    {
        if ($version->isFirstBeta) {
            $this->runFirstBeta($version, $repos);
            return;
        }
        if (!$version->isPrerelease) {
            $this->runStable($version, $repos, $nextDueOn, $upcomingDueOn, $seriesFinal);
            return;
        }
        $this->log[] = 'Pre-release (not first beta): nothing to do.';
    }

    /** @param list<string> $repos */
    private function runFirstBeta(Version $version, array $repos): void
    {
        $title = MilestonePlan::firstBetaMilestone($version);
        $this->log[] = "First beta: creating '{$title}' where missing.";
        foreach ($repos as $repo) {
            if ($this->find($repo, $title) !== null) {
                $this->log[] = "  {$repo}: '{$title}' already exists";
                continue;
            }
            $this->create($repo, $title, null);
        }
    }

    /** @param list<string> $repos */
    private function runStable(Version $version, array $repos, ?string $nextDueOn, ?string $upcomingDueOn, bool $seriesFinal): void
    {
        $candidates = MilestonePlan::currentMilestones($version);
        if ($seriesFinal) {
            $this->runFinalRelease($version, $repos, $candidates);
            return;
        }
        $next = MilestonePlan::nextMilestone($version);
        $upcoming = MilestonePlan::upcomingMilestone($version);

        $this->log[] = sprintf(
            "Stable release: close [%s], move issues to %s, keep %s open.",
            implode(', ', $candidates),
            $next,
            $upcoming,
        );

        foreach ($repos as $repo) {
            $current = $this->findFirst($repo, $candidates);
            if ($current === null) {
                $this->log[] = "  {$repo}: no milestone " . implode('/', $candidates) . ", skipping";
                continue;
            }

            // Ensure the next milestone exists and carries its due date.
            $nextNumber = $this->ensure($repo, $next, $nextDueOn);
            // Move all open issues before closing the current milestone.
            $this->moveIssues($repo, $current->number, $nextNumber, $next);
            // Close the released milestone.
            $this->close($repo, $current);
            // Ensure the upcoming milestone exists and carries its due date.
            $this->ensure($repo, $upcoming, $upcomingDueOn);
        }
    }

    /**
     * The last release of a series: close the released milestone and roll
     * nothing forward, as there is no further patch to schedule.
     *
     * Open issues stay attached to the closed milestone and are reported per
     * repo instead of being moved. Where an EOL major's leftovers belong is a
     * judgement call (drop the milestone, carry them to the next major, or
     * close them), so the tool reports and changes nothing.
     *
     * @param list<string> $repos
     * @param list<string> $candidates
     */
    private function runFinalRelease(Version $version, array $repos, array $candidates): void
    {
        $this->log[] = sprintf(
            'Stable release: %d.%d.%d ends the %d series, closing [%s] with no successor.',
            $version->major,
            $version->minor,
            $version->patch,
            $version->major,
            implode(', ', $candidates),
        );

        foreach ($repos as $repo) {
            $current = $this->findFirst($repo, $candidates);
            if ($current === null) {
                $this->log[] = "  {$repo}: no milestone " . implode('/', $candidates) . ", skipping";
                continue;
            }
            $open = $this->api->openIssueNumbers($repo, $current->number);
            if ($open !== []) {
                // A workflow annotation rather than an indented log line, so it
                // surfaces in the run summary: these need triaging by hand.
                $this->log[] = sprintf(
                    "::warning::%s: %d open issue(s) left in '%s', %d is EOL and has no successor milestone",
                    $repo,
                    count($open),
                    $current->title,
                    $version->major,
                );
            }
            $this->close($repo, $current);
        }
    }

    private function find(string $repo, string $title): ?Milestone
    {
        foreach ($this->api->listMilestones($repo) as $m) {
            if ($m->title === $title) {
                return $m;
            }
        }
        return null;
    }

    /** @param list<string> $titles */
    private function findFirst(string $repo, array $titles): ?Milestone
    {
        foreach ($titles as $title) {
            $m = $this->find($repo, $title);
            if ($m !== null) {
                return $m;
            }
        }
        return null;
    }

    /** Create $title if missing, else set its due date; returns its number (0 in dry-run when it would be created). */
    private function ensure(string $repo, string $title, ?string $dueOn): int
    {
        $existing = $this->find($repo, $title);
        if ($existing === null) {
            return $this->create($repo, $title, $dueOn);
        }
        if ($dueOn !== null && $existing->dueOn !== $dueOn) {
            if ($this->dryRun) {
                $this->log[] = "  {$repo}: would set due of '{$title}' to {$dueOn}";
            } else {
                $this->api->setMilestoneDue($repo, $existing->number, $dueOn);
                $this->log[] = "  {$repo}: set due of '{$title}' to {$dueOn}";
            }
        }
        return $existing->number;
    }

    private function create(string $repo, string $title, ?string $dueOn): int
    {
        $this->created++;
        if ($this->dryRun) {
            $this->log[] = "  {$repo}: would create '{$title}'" . ($dueOn ? " (due {$dueOn})" : '');
            return 0;
        }
        $number = $this->api->createMilestone($repo, $title, $dueOn);
        $this->log[] = "  {$repo}: created '{$title}'" . ($dueOn ? " (due {$dueOn})" : '');
        return $number;
    }

    private function close(string $repo, Milestone $current): void
    {
        // Already closed (e.g. re-running a shipped release to fix due dates):
        // nothing to do. Don't call the API, count it, or claim we closed it.
        if ($current->state === 'closed') {
            return;
        }
        $this->closed++;
        if ($this->dryRun) {
            $this->log[] = "  {$repo}: would close '{$current->title}'";
            return;
        }
        $this->api->closeMilestone($repo, $current->number);
        $this->log[] = "  {$repo}: closed '{$current->title}'";
    }

    private function moveIssues(string $repo, int $from, int $to, string $toTitle): void
    {
        // Gather all open issue numbers up front, then move them - moving while
        // paginating would shift later pages and drop issues.
        $issues = $this->api->openIssueNumbers($repo, $from);
        foreach ($issues as $issue) {
            $this->moved++;
            if ($this->dryRun) {
                $this->log[] = "  {$repo}: would move #{$issue} -> '{$toTitle}'";
                continue;
            }
            $this->api->moveIssue($repo, $issue, $to);
            $this->log[] = "  {$repo}: moved #{$issue} -> '{$toTitle}'";
        }
    }
}
