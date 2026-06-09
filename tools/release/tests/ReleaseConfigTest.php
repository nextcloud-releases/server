<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\ReleaseConfig;
use PHPUnit\Framework\TestCase;

/**
 * What: config-file + tag-list helpers - repo list building, deriving the major
 * a config covers, and finding the latest stable release.
 *
 * Why: these reproduce the selection logic the workflows and audit relied on
 * (object vs string config shapes, master = highest major + 1, picking the
 * newest stable tag, "no stable yet" -> null so the audit can skip).
 */
final class ReleaseConfigTest extends TestCase
{
    public function testReposMergesFilesSortedUnique(): void
    {
        $config = $this->tmp('[{"id":"server","repo":"nextcloud/server"},{"id":"activity","repo":"nextcloud/activity"}]');
        $tagOnly = $this->tmp('["nextcloud/activity","nextcloud/updater"]');

        $this->assertSame(
            ['nextcloud/activity', 'nextcloud/server', 'nextcloud/updater'],
            ReleaseConfig::repos($config, $tagOnly),
        );
    }

    public function testMajorFromStableConfig(): void
    {
        $this->assertSame(34, ReleaseConfig::majorFromConfigBasename('stable34', []));
    }

    public function testMajorFromMasterIsHighestPlusOne(): void
    {
        $tags = ['v32.0.5', 'v33.0.4', 'v34.0.0', 'v34.0.0rc1'];
        $this->assertSame(35, ReleaseConfig::majorFromConfigBasename('master', $tags));
    }

    public function testLatestStablePicksNewest(): void
    {
        $tags = ['v33.0.0', 'v33.0.4', 'v33.0.10', 'v33.0.2', 'v34.0.0'];
        $v = ReleaseConfig::latestStable(33, $tags);
        $this->assertNotNull($v);
        $this->assertSame([33, 0, 10], [$v->major, $v->minor, $v->patch]);
    }

    public function testLatestStableIgnoresPrereleasesAndOtherMajors(): void
    {
        $tags = ['v33.0.4rc1', 'v34.0.0'];
        $this->assertNull(ReleaseConfig::latestStable(33, $tags));
    }

    private function tmp(string $json): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($path, $json);
        return $path;
    }
}
