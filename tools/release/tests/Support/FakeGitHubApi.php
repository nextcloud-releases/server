<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests\Support;

use Nextcloud\ReleaseTools\GitHub\GitHubApi;
use Nextcloud\ReleaseTools\GitHub\Milestone;

/**
 * In-memory GitHubApi for tests. Seeded with milestones/issues/tags, it records
 * every mutating call to a journal so tests can assert the exact side effects -
 * the PHP equivalent of the old fake-gh.sh + journal.
 */
final class FakeGitHubApi implements GitHubApi
{
    /** @var array<string, array<int, Milestone>> repo => number => milestone */
    private array $milestones = [];
    /** @var array<string, array<int, int>> repo => issue number => milestone number */
    private array $issues = [];
    /** @var array<string, array<string, string>> repo => tag => sha */
    private array $tags = [];
    /** @var array<string, string> repo => default branch */
    private array $defaultBranches = [];
    /** @var array<string, array<string, string>> repo => branch => sha */
    private array $branches = [];

    /** @var list<array<string, mixed>> ordered mutation log, one structured entry per call */
    public array $journal = [];

    private int $nextNumber = 1;

    public function seedMilestone(string $repo, int $number, string $title, string $state = 'open', int $openIssues = 0, ?string $dueOn = null): void
    {
        $this->milestones[$repo][$number] = new Milestone($number, $title, $state, $openIssues, $dueOn);
        $this->nextNumber = max($this->nextNumber, $number + 1);
    }

    public function seedIssue(string $repo, int $number, int $milestoneNumber): void
    {
        $this->issues[$repo][$number] = $milestoneNumber;
    }

    public function seedTag(string $repo, string $tag, string $sha = 'sha-0000'): void
    {
        $this->tags[$repo][$tag] = $sha;
    }

    public function seedBranch(string $repo, string $branch, string $sha, bool $default = false): void
    {
        $this->branches[$repo][$branch] = $sha;
        if ($default) {
            $this->defaultBranches[$repo] = $branch;
        }
    }

    public function listMilestones(string $repo): array
    {
        return array_values($this->milestones[$repo] ?? []);
    }

    public function createMilestone(string $repo, string $title, ?string $dueOn): int
    {
        $number = $this->nextNumber++;
        $this->milestones[$repo][$number] = new Milestone($number, $title, 'open', 0, $dueOn);
        $this->journal[] = ['action' => 'create', 'repo' => $repo, 'title' => $title, 'due' => $dueOn];
        return $number;
    }

    public function closeMilestone(string $repo, int $number): void
    {
        $m = $this->milestones[$repo][$number];
        $this->milestones[$repo][$number] = new Milestone($m->number, $m->title, 'closed', $m->openIssues, $m->dueOn);
        $this->journal[] = ['action' => 'close', 'repo' => $repo, 'milestone' => $number];
    }

    public function setMilestoneDue(string $repo, int $number, string $dueOn): void
    {
        $m = $this->milestones[$repo][$number];
        $this->milestones[$repo][$number] = new Milestone($m->number, $m->title, $m->state, $m->openIssues, $dueOn);
        $this->journal[] = ['action' => 'setdue', 'repo' => $repo, 'milestone' => $number, 'due' => $dueOn];
    }

    public function openIssueNumbers(string $repo, int $milestoneNumber): array
    {
        $out = [];
        foreach ($this->issues[$repo] ?? [] as $issue => $ms) {
            if ($ms === $milestoneNumber) {
                $out[] = $issue;
            }
        }
        sort($out);
        return $out;
    }

    public function moveIssue(string $repo, int $issueNumber, int $milestoneNumber): void
    {
        $this->issues[$repo][$issueNumber] = $milestoneNumber;
        $this->journal[] = ['action' => 'move', 'repo' => $repo, 'issue' => $issueNumber, 'milestone' => $milestoneNumber];
    }

    public function listTagNames(string $repo): array
    {
        return array_keys($this->tags[$repo] ?? []);
    }

    public function branchSha(string $repo, string $branch): ?string
    {
        return $this->branches[$repo][$branch] ?? null;
    }

    public function defaultBranch(string $repo): ?string
    {
        return $this->defaultBranches[$repo] ?? null;
    }

    public function tagSha(string $repo, string $tag): ?string
    {
        return $this->tags[$repo][$tag] ?? null;
    }

    public function createTag(string $repo, string $tag, string $sha): void
    {
        $this->tags[$repo][$tag] = $sha;
        $this->journal[] = ['action' => 'tag', 'repo' => $repo, 'tag' => $tag, 'sha' => $sha];
    }

    public function updateTag(string $repo, string $tag, string $sha, bool $force): void
    {
        $this->tags[$repo][$tag] = $sha;
        $this->journal[] = ['action' => 'retag', 'repo' => $repo, 'tag' => $tag, 'sha' => $sha, 'force' => $force];
    }
}
