<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\GitHub;

/**
 * The slice of the GitHub API the release tooling needs. Implemented for real
 * by KnpGitHubApi and in-memory by FakeGitHubApi (for tests). All methods take
 * a "owner/name" repo string.
 */
interface GitHubApi
{
    /** @return list<Milestone> every milestone (any state) */
    public function listMilestones(string $repo): array;

    /** @return int the new milestone number */
    public function createMilestone(string $repo, string $title, ?string $dueOn): int;

    public function closeMilestone(string $repo, int $number): void;

    public function setMilestoneDue(string $repo, int $number, string $dueOn): void;

    /** @return list<int> open issue numbers in a milestone (all pages) */
    public function openIssueNumbers(string $repo, int $milestoneNumber): array;

    public function moveIssue(string $repo, int $issueNumber, int $milestoneNumber): void;

    /** @return list<string> tag names (e.g. "v34.0.1") */
    public function listTagNames(string $repo): array;

    /** Commit SHA at the tip of a branch, or null if the branch is absent. */
    public function branchSha(string $repo, string $branch): ?string;

    /** The repo's default branch, or null if it can't be determined. */
    public function defaultBranch(string $repo): ?string;

    /** SHA the tag points at, or null if the tag does not exist. */
    public function tagSha(string $repo, string $tag): ?string;

    public function createTag(string $repo, string $tag, string $sha): void;

    /** Move an existing tag; $force allows non-fast-forward replacement. */
    public function updateTag(string $repo, string $tag, string $sha, bool $force): void;
}
