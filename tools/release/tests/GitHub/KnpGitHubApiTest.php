<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests\GitHub;

use Github\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Nextcloud\ReleaseTools\GitHub\KnpGitHubApi;
use PHPUnit\Framework\TestCase;

/**
 * What: the one piece of reading the adapter does on its own - turning GitHub's
 * paginated milestone listing into the numbers the updater moves.
 *
 * Why: everything else in KnpGitHubApi is a one-line call the services cover
 * against FakeGitHubApi, but this method used to drop pull requests, so the
 * whole 35.0.1 backport queue stayed behind in a closed milestone. The fake
 * cannot catch that: it never sees a `pull_request` key. This can, against a
 * canned HTTP response.
 */
final class KnpGitHubApiTest extends TestCase
{
    private const REPO = 'nextcloud/server';

    public function testMilestoneContentsIncludePullRequests(): void
    {
        $api = $this->apiReturning([
            $this->page([
                ['number' => 63953],
                ['number' => 64303, 'pull_request' => ['url' => 'https://api.github.com/repos/nextcloud/server/pulls/64303']],
                ['number' => 54563],
            ]),
        ]);

        $this->assertSame([63953, 64303, 54563], $api->openIssueNumbers(self::REPO, 360));
    }

    public function testMilestoneContentsSpanAllPages(): void
    {
        $api = $this->apiReturning([
            $this->page([['number' => 1], ['number' => 2]], next: 'https://api.github.com/repositories/1/issues?milestone=360&page=2'),
            $this->page([['number' => 3]]),
        ]);

        $this->assertSame([1, 2, 3], $api->openIssueNumbers(self::REPO, 360));
    }

    public function testOpenPullRequestsAreFilteredByOwnerQualifiedHead(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([$this->page([['number' => 136]])]));
        $stack->push(Middleware::history($history));
        $api = new KnpGitHubApi(Client::createWithHttpClient(new GuzzleClient(['handler' => $stack])));

        $this->assertSame([136], $api->openPullRequestNumbers('nextcloud-releases/server', 'chore/release-schedule'));

        // A bare branch name is silently ignored by GitHub, which would then
        // list every open pull request and block every release.
        parse_str($history[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame('nextcloud-releases:chore/release-schedule', $query['head']);
        $this->assertSame('open', $query['state']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function page(array $rows, ?string $next = null): Response
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($next !== null) {
            $headers['Link'] = "<{$next}>; rel=\"next\"";
        }
        return new Response(200, $headers, (string) json_encode($rows));
    }

    /** @param list<Response> $responses */
    private function apiReturning(array $responses): KnpGitHubApi
    {
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]);
        return new KnpGitHubApi(Client::createWithHttpClient($guzzle));
    }
}
