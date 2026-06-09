<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests\Updater;

use Nextcloud\ReleaseTools\Updater\ReleasePlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What: how a tag becomes the updater-server release facts (display version,
 * URL pieces, channel, release type, deploy %).
 *
 * Why: this is the fiddly bit of update-updater-server.sh - RC vs beta/alpha
 * display formatting, the prereleases/releases split, and the X.0.0=30 /
 * X.0.1=70 / else=100 deploy rule. Easy to get wrong in bash, pinned here.
 */
final class ReleasePlanTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string, string, int}> */
    public static function tags(): iterable
    {
        // tag => versionString, urlVersion, urlDir, stability, releaseType, deploy
        yield 'patch'        => ['v33.0.6', '33.0.6', '33.0.6', 'releases', 'stable', ReleasePlan::TYPE_PATCH, 100];
        yield 'first stable' => ['v34.0.0', '34.0.0', '34.0.0', 'releases', 'stable', ReleasePlan::TYPE_FIRST_STABLE, 30];
        yield 'x.0.1 -> 70'  => ['v34.0.1', '34.0.1', '34.0.1', 'releases', 'stable', ReleasePlan::TYPE_PATCH, 70];
        yield 'x.0.2 -> 100' => ['v34.0.2', '34.0.2', '34.0.2', 'releases', 'stable', ReleasePlan::TYPE_PATCH, 100];
        yield 'minor bump'   => ['v34.1.0', '34.1.0', '34.1.0', 'releases', 'stable', ReleasePlan::TYPE_PATCH, 100];
        yield 'rc'           => ['v34.0.0rc5', '34.0.0 RC5', '34.0.0rc5', 'prereleases', 'beta', ReleasePlan::TYPE_PRERELEASE, 100];
        yield 'beta'         => ['v35.0.0beta1', '35.0.0 beta 1', '35.0.0beta1', 'prereleases', 'beta', ReleasePlan::TYPE_PRERELEASE, 100];
        yield 'alpha'        => ['v35.0.0alpha2', '35.0.0 alpha 2', '35.0.0alpha2', 'prereleases', 'beta', ReleasePlan::TYPE_PRERELEASE, 100];
    }

    #[DataProvider('tags')]
    public function testFromTag(string $tag, string $versionString, string $urlVersion, string $urlDir, string $stability, string $type, int $deploy): void
    {
        $p = ReleasePlan::fromTag($tag);
        $this->assertSame($versionString, $p->versionString);
        $this->assertSame($urlVersion, $p->urlVersion);
        $this->assertSame($urlDir, $p->urlDir);
        $this->assertSame($stability, $p->stability);
        $this->assertSame($stability, $p->channel);
        $this->assertSame($type, $p->releaseType);
        $this->assertSame($deploy, $p->deploy);
    }

    public function testComponentsParsed(): void
    {
        $p = ReleasePlan::fromTag('v34.0.0rc5');
        $this->assertSame([34, 0, 0], [$p->major, $p->minor, $p->patch]);
    }

    public function testDeployOverride(): void
    {
        $this->assertSame(50, ReleasePlan::fromTag('v34.0.0', 50)->deploy);
    }
}
