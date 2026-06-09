<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\Tests\Support\FakeGitHubApi;
use Nextcloud\ReleaseTools\RepoTagger;
use PHPUnit\Framework\TestCase;

/**
 * What: the tagger's decisions and side effects against an in-memory GitHub.
 *
 * Why: ports tag-repo.sh behaviour - create when missing, skip when present,
 * recreate on --force, NEVER move tags on the server repos, fall back to the
 * default branch when the release branch is absent, and fail cleanly when no
 * branch exists. Also pins dry-run as side-effect-free.
 */
final class RepoTaggerTest extends TestCase
{
    private function api(): FakeGitHubApi
    {
        $api = new FakeGitHubApi();
        $api->seedBranch('nextcloud/activity', 'stable34', 'sha-stable', true);
        return $api;
    }

    public function testCreatesTagWhenMissing(): void
    {
        $api = $this->api();
        $r = (new RepoTagger($api))->tag('nextcloud/activity', 'stable34', 'v34.0.1', false);
        $this->assertSame('OK', $r->status);
        $this->assertSame([
            ['action' => 'tag', 'repo' => 'nextcloud/activity', 'tag' => 'v34.0.1', 'sha' => 'sha-stable'],
        ], $api->journal);
    }

    public function testSkipsWhenTagExistsWithoutForce(): void
    {
        $api = $this->api();
        $api->seedTag('nextcloud/activity', 'v34.0.1', 'old-sha');
        $r = (new RepoTagger($api))->tag('nextcloud/activity', 'stable34', 'v34.0.1', false);
        $this->assertSame('SKIPPED', $r->status);
        $this->assertSame([], $api->journal);
    }

    public function testForceRecreatesTag(): void
    {
        $api = $this->api();
        $api->seedTag('nextcloud/activity', 'v34.0.1', 'old-sha');
        $r = (new RepoTagger($api))->tag('nextcloud/activity', 'stable34', 'v34.0.1', true);
        $this->assertSame('OK', $r->status);
        $this->assertSame([
            ['action' => 'retag', 'repo' => 'nextcloud/activity', 'tag' => 'v34.0.1', 'sha' => 'sha-stable', 'force' => true],
        ], $api->journal);
    }

    public function testServerRepoNeverForceRetagged(): void
    {
        $api = new FakeGitHubApi();
        $api->seedBranch('nextcloud/server', 'stable34', 'sha-x', true);
        $api->seedTag('nextcloud/server', 'v34.0.1', 'old-sha');
        $r = (new RepoTagger($api))->tag('nextcloud/server', 'stable34', 'v34.0.1', true);
        $this->assertSame('SKIPPED', $r->status, 'server tags are immutable even with --force');
        $this->assertSame([], $api->journal);
    }

    public function testFallsBackToDefaultBranch(): void
    {
        $api = new FakeGitHubApi();
        // No stable34; default branch is main.
        $api->seedBranch('nextcloud/notes', 'main', 'sha-main', true);
        $r = (new RepoTagger($api))->tag('nextcloud/notes', 'stable34', 'v34.0.1', false);
        $this->assertSame('OK', $r->status);
        $this->assertSame('main', $r->branch);
        $this->assertSame([
            ['action' => 'tag', 'repo' => 'nextcloud/notes', 'tag' => 'v34.0.1', 'sha' => 'sha-main'],
        ], $api->journal);
    }

    public function testNoBranchFails(): void
    {
        $api = new FakeGitHubApi(); // nothing seeded
        $r = (new RepoTagger($api))->tag('nextcloud/ghost', 'stable34', 'v34.0.1', false);
        $this->assertSame('FAILED', $r->status);
        $this->assertSame([], $api->journal);
    }

    public function testDryRunChangesNothing(): void
    {
        $api = $this->api();
        $r = (new RepoTagger($api, dryRun: true))->tag('nextcloud/activity', 'stable34', 'v34.0.1', false);
        $this->assertSame('OK', $r->status);
        $this->assertSame([], $api->journal);
        $this->assertStringContainsString('would', $r->detail);
    }
}
