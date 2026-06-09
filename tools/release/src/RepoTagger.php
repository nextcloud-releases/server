<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

use Nextcloud\ReleaseTools\GitHub\GitHubApi;

/**
 * Creates a release tag on a repo at the tip of a branch.
 *
 * Branch resolution: prefer the release branch (stableXX/master), fall back to
 * the repo's default branch. The tag is a lightweight ref (same as the old
 * `gh release create` produced) created via the git-refs API - no clone/push.
 * Existing tags are left alone unless --force, and never moved on the server
 * repos (see TagPolicy).
 */
final class RepoTagger
{
    public function __construct(
        private readonly GitHubApi $api,
        private readonly bool $dryRun = false,
    ) {
    }

    public function tag(string $repo, string $preferredBranch, string $tag, bool $force): TagResult
    {
        $branch = $preferredBranch;
        $sha = $this->api->branchSha($repo, $branch);
        if ($sha === null) {
            $branch = $this->api->defaultBranch($repo) ?? '';
            $sha = $branch !== '' ? $this->api->branchSha($repo, $branch) : null;
        }
        if ($sha === null) {
            return new TagResult($repo, $branch, 'FAILED', 'no branch found');
        }

        $action = TagPolicy::decide($repo, $this->api->tagSha($repo, $tag) !== null, $force);

        if ($action === TagPolicy::ACTION_SKIP) {
            return new TagResult($repo, $branch, 'SKIPPED', 'already tagged');
        }

        if ($this->dryRun) {
            return new TagResult($repo, $branch, 'OK', "would {$action} {$tag} @ {$sha}");
        }

        if ($action === TagPolicy::ACTION_RECREATE) {
            $this->api->updateTag($repo, $tag, $sha, true);
        } else {
            $this->api->createTag($repo, $tag, $sha);
        }
        return new TagResult($repo, $branch, 'OK', "{$action}d {$tag}");
    }
}
