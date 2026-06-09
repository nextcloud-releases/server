<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\MilestoneUpdater;
use Nextcloud\ReleaseTools\Tests\Support\FakeGitHubApi;
use Nextcloud\ReleaseTools\Tests\Support\MatchesSnapshots;
use Nextcloud\ReleaseTools\Version;
use PHPUnit\Framework\TestCase;

/**
 * What: golden-file snapshots of the full milestone-update journal per release
 * scenario (the PHP successor to the old tests/milestone-scripts snapshot
 * harness).
 *
 * Why: alongside the focused assertions in MilestoneUpdaterTest, these pin the
 * entire mutation sequence as a reviewable artifact - the same "scenario ->
 * expected" feel as the bash harness, so a behaviour change shows up as a
 * readable JSON snapshot diff. Each snapshot is {journal[], milestones{}} where
 * every journal entry is a structured action; the file is identified by its id
 * (the snapshot name) and the human scenario lives only in the test. Update with
 * UPDATE_SNAPSHOTS=1.
 */
final class MilestoneSnapshotTest extends TestCase
{
    use MatchesSnapshots;

    private const SERVER = 'nextcloud/server';
    private const ACTIVITY = 'nextcloud/activity';

    /**
     * Build the snapshot data. $scenario is the human description kept at the
     * call site so the test reads clearly; it is intentionally NOT stored in the
     * snapshot - each golden file is identified by its id (the name passed to
     * assertMatchesJsonSnapshot), so rewording a description never churns a file.
     *
     * journal = the mutations that ran; milestones = the resulting state, so a
     * snapshot shows both what changed and that the expected milestones exist
     * afterwards (e.g. updated in place rather than recreated).
     *
     * @return array{journal: list<array<string, mixed>>, milestones: array<string, list<array<string, mixed>>>}
     */
    private function snapshot(string $scenario, FakeGitHubApi $api): array
    {
        unset($scenario); // documents the call site only; see above.
        $milestones = [];
        foreach ($api->milestoneState() as $repo => $list) {
            // openIssues is omitted: the updater drives off the issue->milestone
            // map (openIssueNumbers), not this seeded counter, so it would show
            // a stale value here and only confuse the snapshot.
            $milestones[$repo] = array_map(
                static fn ($m) => [
                    'number' => $m->number,
                    'title' => $m->title,
                    'state' => $m->state,
                    'due' => $m->dueOn,
                ],
                $list,
            );
        }
        return ['journal' => $api->journal, 'milestones' => $milestones];
    }

    public function testPatchRelease(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::SERVER, 10, 'Nextcloud 33.0.4', 'open', 2);
        $api->seedMilestone(self::SERVER, 11, 'Nextcloud 33.0.5');
        $api->seedIssue(self::SERVER, 100, 10);
        $api->seedIssue(self::SERVER, 101, 10);

        (new MilestoneUpdater($api))->run(Version::fromTag('v33.0.4'), [self::SERVER]);
        $this->assertMatchesJsonSnapshot('milestones/patch-release', $this->snapshot('Patch release v33.0.4: move open issues to 33.0.5, close 33.0.4, open 33.0.6', $api));
    }

    public function testFirstStable(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::SERVER, 20, 'Nextcloud 34');
        (new MilestoneUpdater($api))->run(Version::fromTag('v34.0.0'), [self::SERVER]);
        $this->assertMatchesJsonSnapshot('milestones/first-stable', $this->snapshot('First stable v34.0.0: close the Nextcloud 34 milestone, open 34.0.1 and 34.0.2', $api));
    }

    public function testFirstBeta(): void
    {
        $api = new FakeGitHubApi();
        (new MilestoneUpdater($api))->run(Version::fromTag('v35.0.0beta1'), [self::SERVER, self::ACTIVITY]);
        $this->assertMatchesJsonSnapshot('milestones/first-beta', $this->snapshot('First beta v35.0.0beta1: open the next major milestone Nextcloud 36 in each repo', $api));
    }

    public function testMissingNext(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::SERVER, 10, 'Nextcloud 33.0.4', 'open', 1);
        $api->seedIssue(self::SERVER, 100, 10);
        (new MilestoneUpdater($api))->run(Version::fromTag('v33.0.4'), [self::SERVER]);
        $this->assertMatchesJsonSnapshot('milestones/missing-next', $this->snapshot('Patch v33.0.4 when 33.0.5 is missing: create it first, then move/close/open 33.0.6', $api));
    }

    public function testDueDates(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::SERVER, 10, 'Nextcloud 33.0.4');
        $api->seedMilestone(self::SERVER, 11, 'Nextcloud 33.0.5', 'open', 0, '2026-06-25T00:00:00Z');
        $api->seedMilestone(self::SERVER, 12, 'Nextcloud 33.0.6');
        (new MilestoneUpdater($api))->run(
            Version::fromTag('v33.0.4'),
            [self::SERVER],
            '2026-07-02T00:00:00Z',
            '2026-08-27T00:00:00Z',
        );
        $this->assertMatchesJsonSnapshot('milestones/due-dates', $this->snapshot('Patch v33.0.4 with due dates: set 33.0.5 and 33.0.6 due dates, close 33.0.4', $api));
    }

    public function testMultiRepo(): void
    {
        $api = new FakeGitHubApi();
        foreach ([self::SERVER, self::ACTIVITY] as $i => $repo) {
            $base = ($i + 1) * 10;
            $api->seedMilestone($repo, $base, 'Nextcloud 33.0.4', 'open', 1);
            $api->seedMilestone($repo, $base + 1, 'Nextcloud 33.0.5');
            $api->seedIssue($repo, 500 + $i, $base);
        }
        (new MilestoneUpdater($api))->run(Version::fromTag('v33.0.4'), [self::SERVER, self::ACTIVITY]);
        $this->assertMatchesJsonSnapshot('milestones/multi-repo', $this->snapshot('Patch v33.0.4 run over two repos (nextcloud/server and nextcloud/activity): the same flow happens independently in each - move that repo\'s open issue to 33.0.5, close 33.0.4, open 33.0.6 - and milestone numbers are per-repo (server uses 10/11, activity 20/21)', $api));
    }

    public function testPrereleaseNoop(): void
    {
        $api = new FakeGitHubApi();
        $api->seedMilestone(self::SERVER, 10, 'Nextcloud 33.0.2');
        (new MilestoneUpdater($api))->run(Version::fromTag('v33.0.2rc1'), [self::SERVER]);
        $this->assertMatchesJsonSnapshot('milestones/prerelease-noop', $this->snapshot('Ordinary pre-release v33.0.2rc1 (a release candidate that is not a first beta): milestones are only rolled on stable releases and first betas, so this does nothing - no create, close, move or due-date change, and 33.0.2 is left untouched', $api));
    }
}
