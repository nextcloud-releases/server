<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\GitHub;

use Github\Client;
use Github\Exception\RuntimeException;
use Github\ResultPager;

/**
 * Real GitHubApi backed by knplabs/github-api. Thin adapter: all behaviour
 * lives in the services (tested against FakeGitHubApi). Paginates list calls so
 * busy repos (>100 milestones/issues) are fully covered. Tags are created as
 * lightweight refs via the git-refs API - no clone/push.
 */
final class KnpGitHubApi implements GitHubApi
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    public static function withToken(string $token): self
    {
        $client = new Client();
        $client->authenticate($token, null, Client::AUTH_ACCESS_TOKEN);
        return new self($client);
    }

    public function listMilestones(string $repo): array
    {
        [$owner, $name] = $this->split($repo);
        $pager = new ResultPager($this->client);
        $rows = $pager->fetchAll($this->client->api('issue')->milestones(), 'all', [$owner, $name, ['state' => 'all', 'per_page' => 100]]);
        return array_map(
            static fn (array $m): Milestone => new Milestone(
                (int) $m['number'],
                (string) $m['title'],
                (string) $m['state'],
                (int) ($m['open_issues'] ?? 0),
                $m['due_on'] ?? null,
            ),
            $rows,
        );
    }

    public function createMilestone(string $repo, string $title, ?string $dueOn): int
    {
        [$owner, $name] = $this->split($repo);
        $params = ['title' => $title];
        if ($dueOn !== null) {
            $params['due_on'] = $dueOn;
        }
        $res = $this->client->api('issue')->milestones()->create($owner, $name, $params);
        return (int) $res['number'];
    }

    public function closeMilestone(string $repo, int $number): void
    {
        [$owner, $name] = $this->split($repo);
        $this->client->api('issue')->milestones()->update($owner, $name, $number, ['state' => 'closed']);
    }

    public function setMilestoneDue(string $repo, int $number, string $dueOn): void
    {
        [$owner, $name] = $this->split($repo);
        $this->client->api('issue')->milestones()->update($owner, $name, $number, ['due_on' => $dueOn]);
    }

    public function openIssueNumbers(string $repo, int $milestoneNumber): array
    {
        [$owner, $name] = $this->split($repo);
        $pager = new ResultPager($this->client);
        $rows = $pager->fetchAll($this->client->api('issue'), 'all', [$owner, $name, ['milestone' => $milestoneNumber, 'state' => 'open', 'per_page' => 100]]);
        $numbers = [];
        foreach ($rows as $row) {
            // The issues endpoint also returns pull requests; only move issues.
            if (isset($row['pull_request'])) {
                continue;
            }
            $numbers[] = (int) $row['number'];
        }
        return $numbers;
    }

    public function moveIssue(string $repo, int $issueNumber, int $milestoneNumber): void
    {
        [$owner, $name] = $this->split($repo);
        $this->client->api('issue')->update($owner, $name, $issueNumber, ['milestone' => $milestoneNumber]);
    }

    public function listTagNames(string $repo): array
    {
        [$owner, $name] = $this->split($repo);
        $pager = new ResultPager($this->client);
        $rows = $pager->fetchAll($this->client->api('repo'), 'tags', [$owner, $name]);
        return array_map(static fn (array $t): string => (string) $t['name'], $rows);
    }

    public function branchSha(string $repo, string $branch): ?string
    {
        return $this->refSha($repo, "heads/{$branch}");
    }

    public function defaultBranch(string $repo): ?string
    {
        [$owner, $name] = $this->split($repo);
        try {
            $info = $this->client->api('repo')->show($owner, $name);
            return $info['default_branch'] ?? null;
        } catch (RuntimeException) {
            return null;
        }
    }

    public function tagSha(string $repo, string $tag): ?string
    {
        return $this->refSha($repo, "tags/{$tag}");
    }

    public function createTag(string $repo, string $tag, string $sha): void
    {
        [$owner, $name] = $this->split($repo);
        $this->client->api('gitData')->references()->create($owner, $name, ['ref' => "refs/tags/{$tag}", 'sha' => $sha]);
    }

    public function updateTag(string $repo, string $tag, string $sha, bool $force): void
    {
        [$owner, $name] = $this->split($repo);
        $this->client->api('gitData')->references()->update($owner, $name, "tags/{$tag}", ['sha' => $sha, 'force' => $force]);
    }

    private function refSha(string $repo, string $ref): ?string
    {
        [$owner, $name] = $this->split($repo);
        try {
            $data = $this->client->api('gitData')->references()->show($owner, $name, $ref);
            return $data['object']['sha'] ?? null;
        } catch (RuntimeException) {
            return null;
        }
    }

    /** @return array{0: string, 1: string} */
    private function split(string $repo): array
    {
        $parts = explode('/', $repo, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("Expected owner/name, got '{$repo}'");
        }
        return [$parts[0], $parts[1]];
    }
}
