<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\RepoTagger;
use Nextcloud\ReleaseTools\Tests\Support\FakeGitHubApi;
use Nextcloud\ReleaseTools\Tests\Support\Journal;
use Nextcloud\ReleaseTools\Tests\Support\MatchesSnapshots;
use PHPUnit\Framework\TestCase;

/**
 * What: golden-file snapshots of the tagger's per-repo decisions and the tags
 * it writes, across a representative mix of repositories.
 *
 * Why: pins the whole tag run (status + chosen branch + the create/recreate/skip
 * journal) as one reviewable artifact, covering create, skip-existing,
 * force-recreate, server-repo immutability, default-branch fallback and
 * no-branch failure together. Update with UPDATE_SNAPSHOTS=1.
 */
final class TaggerSnapshotTest extends TestCase
{
    use MatchesSnapshots;

    private function render(array $results, FakeGitHubApi $api): string
    {
        $lines = ['Results:'];
        foreach ($results as $r) {
            $branch = $r->branch !== '' ? " on {$r->branch}" : '';
            $lines[] = sprintf('  %s: %s%s (%s)', $r->repo, $r->status, $branch, $r->detail);
        }
        $lines[] = '';
        $lines[] = 'Tags written:';
        $lines[] = $api->journal === []
            ? '  (none)'
            : '  ' . implode("\n  ", explode("\n", Journal::render($api->journal)));
        return implode("\n", $lines);
    }

    public function testMixedRun(): void
    {
        $api = new FakeGitHubApi();
        // a normal app on the release branch
        $api->seedBranch('nextcloud/activity', 'stable34', 'sha-activity', true);
        // an app where the tag already exists (will skip without force)
        $api->seedBranch('nextcloud/notes', 'stable34', 'sha-notes', true);
        $api->seedTag('nextcloud/notes', 'v34.0.1', 'old-notes');
        // an app with no stable34, only a default branch (fallback)
        $api->seedBranch('nextcloud/photos', 'main', 'sha-photos', true);
        // the server repo, tag exists - must never be recreated even with force
        $api->seedBranch('nextcloud/server', 'stable34', 'sha-server', true);
        $api->seedTag('nextcloud/server', 'v34.0.1', 'old-server');
        // a repo with no branch at all (fails)
        // (nextcloud/ghost: nothing seeded)

        $repos = ['nextcloud/activity', 'nextcloud/notes', 'nextcloud/photos', 'nextcloud/server', 'nextcloud/ghost'];
        $tagger = new RepoTagger($api);
        $results = array_map(
            static fn (string $repo) => $tagger->tag($repo, 'stable34', 'v34.0.1', false),
            $repos,
        );

        $this->assertMatchesSnapshot('tagger/mixed', $this->render($results, $api));
    }

    public function testForceRun(): void
    {
        $api = new FakeGitHubApi();
        $api->seedBranch('nextcloud/activity', 'stable34', 'sha-activity', true);
        $api->seedTag('nextcloud/activity', 'v34.0.1', 'old');
        $api->seedBranch('nextcloud/server', 'stable34', 'sha-server', true);
        $api->seedTag('nextcloud/server', 'v34.0.1', 'old-server');

        $tagger = new RepoTagger($api);
        $results = [
            $tagger->tag('nextcloud/activity', 'stable34', 'v34.0.1', true),
            $tagger->tag('nextcloud/server', 'stable34', 'v34.0.1', true),
        ];
        $this->assertMatchesSnapshot('tagger/force', $this->render($results, $api));
    }

    public function testDryRun(): void
    {
        $api = new FakeGitHubApi();
        $api->seedBranch('nextcloud/activity', 'stable34', 'sha-activity', true);
        $results = [(new RepoTagger($api, dryRun: true))->tag('nextcloud/activity', 'stable34', 'v34.0.1', false)];
        $this->assertMatchesSnapshot('tagger/dry-run', $this->render($results, $api));
    }
}
