<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\TagPolicy;
use PHPUnit\Framework\TestCase;

/**
 * What: the pure decision table for tagging.
 *
 * Why: the create/skip/recreate choice and the "server repos are immutable"
 * rule are safety-critical (a moved release tag is bad), so they get isolated
 * assertions independent of any API.
 */
final class TagPolicyTest extends TestCase
{
    public function testCreateWhenMissing(): void
    {
        $this->assertSame(TagPolicy::ACTION_CREATE, TagPolicy::decide('nextcloud/activity', false, false));
        $this->assertSame(TagPolicy::ACTION_CREATE, TagPolicy::decide('nextcloud/server', false, true));
    }

    public function testSkipWhenExistsWithoutForce(): void
    {
        $this->assertSame(TagPolicy::ACTION_SKIP, TagPolicy::decide('nextcloud/activity', true, false));
    }

    public function testRecreateOnForceForNormalRepos(): void
    {
        $this->assertSame(TagPolicy::ACTION_RECREATE, TagPolicy::decide('nextcloud/activity', true, true));
    }

    public function testServerReposNeverRecreate(): void
    {
        $this->assertSame(TagPolicy::ACTION_SKIP, TagPolicy::decide('nextcloud/server', true, true));
        $this->assertSame(TagPolicy::ACTION_SKIP, TagPolicy::decide('nextcloud-releases/server', true, true));
        $this->assertTrue(TagPolicy::isProtected('nextcloud/server'));
        $this->assertFalse(TagPolicy::isProtected('nextcloud/activity'));
    }
}
