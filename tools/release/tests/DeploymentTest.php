<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\Deployment;
use Nextcloud\ReleaseTools\Tests\Support\FakeGitHubApi;
use PHPUnit\Framework\TestCase;

/**
 * What: whether servers have been offered a version, read from updater_server
 * production.
 *
 * Why: a forced redo moves tags and deletes the GitHub release. That is fine
 * until production lists the version (or a later one that replaced it), and
 * never after. The shape below is the real one: one key per maintained line,
 * e.g. "35.0.3" on 2026-11-12.
 */
final class DeploymentTest extends TestCase
{
    private function deployment(?array $releases): Deployment
    {
        $api = new FakeGitHubApi();
        if ($releases !== null) {
            $api->seedFile(Deployment::REPO, Deployment::BRANCH, Deployment::FILE, (string) json_encode($releases));
        }
        return new Deployment($api);
    }

    private function production(): Deployment
    {
        return $this->deployment([
            '33.0.11' => ['internalVersion' => '33.0.11.2'],
            '34.0.6' => ['internalVersion' => '34.0.6.2'],
            '35.0.3' => ['internalVersion' => '35.0.3.2'],
        ]);
    }

    public function testListedVersionBlocks(): void
    {
        $this->assertSame('35.0.3', $this->production()->blockingEntry('35.0.3'));
    }

    public function testLaterVersionOfTheSameMajorBlocks(): void
    {
        // 35.0.3 replaced it, so servers were offered 35.0.2 before.
        $this->assertSame('35.0.3', $this->production()->blockingEntry('35.0.2'));
        $this->assertSame('35.0.3', $this->production()->blockingEntry('35.0.3rc2'));
    }

    public function testNextVersionIsFree(): void
    {
        $this->assertNull($this->production()->blockingEntry('35.0.4'));
        $this->assertNull($this->production()->blockingEntry('35.0.4rc1'));
    }

    public function testOtherMajorsDoNotCount(): void
    {
        $this->assertNull($this->production()->blockingEntry('36.0.0beta1'));
        // Two-digit patch levels compare as numbers, not strings.
        $this->assertNull($this->deployment(['35.0.9' => []])->blockingEntry('35.0.10'));
    }

    public function testUnreadableProductionIsNotTakenAsFree(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->deployment(null)->blockingEntry('35.0.4');
    }
}
