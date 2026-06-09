<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\RepoList;
use PHPUnit\Framework\TestCase;

final class RepoListTest extends TestCase
{
    public function testMergesObjectAndStringFormatsSortedUnique(): void
    {
        $config = [
            ['id' => 'server', 'repo' => 'nextcloud/server'],
            ['id' => 'activity', 'repo' => 'nextcloud/activity'],
        ];
        $tagOnly = ['nextcloud/activity', 'nextcloud/updater'];

        $this->assertSame(
            ['nextcloud/activity', 'nextcloud/server', 'nextcloud/updater'],
            RepoList::merge($config, $tagOnly),
        );
    }

    public function testEmptyTagOnly(): void
    {
        $config = [['repo' => 'nextcloud/server']];
        $this->assertSame(['nextcloud/server'], RepoList::merge($config, []));
    }

    public function testIgnoresEntriesWithoutRepo(): void
    {
        $config = [['id' => 'broken'], ['repo' => 'nextcloud/server']];
        $this->assertSame(['nextcloud/server'], RepoList::merge($config));
    }
}
