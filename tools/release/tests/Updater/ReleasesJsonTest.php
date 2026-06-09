<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests\Updater;

use Nextcloud\ReleaseTools\Updater\ReleasePlan;
use Nextcloud\ReleaseTools\Updater\ReleasesJson;
use PHPUnit\Framework\TestCase;

/**
 * What: the releases.json edits - which entry a release replaces, the new entry
 * shape, applying the replace/append, and tab-indented encoding.
 *
 * Why: replaces the jq surgery in update-updater-server.sh. Getting the
 * old-entry lookup or the deploy/signatures shape wrong would corrupt the
 * updater server config; the tab encoding keeps diffs clean (the bug #89 fixed).
 */
final class ReleasesJsonTest extends TestCase
{
    private function sample(): array
    {
        // insertion order matters: findOldKey returns the last match
        return [
            '33.0.4' => ['internalVersion' => '33.0.4.1'],
            '33.0.5' => ['internalVersion' => '33.0.5.1'],
            '34.0.0 RC4' => ['internalVersion' => '34.0.0.6'],
            '34.0.0 RC5' => ['internalVersion' => '34.0.0.7'],
        ];
    }

    public function testFindOldKeyPatchPicksLatestStableOfMajor(): void
    {
        $this->assertSame('33.0.5', ReleasesJson::findOldKey($this->sample(), 33, ReleasePlan::TYPE_PATCH));
    }

    public function testFindOldKeyPrereleasePicksLatestRc(): void
    {
        $this->assertSame('34.0.0 RC5', ReleasesJson::findOldKey($this->sample(), 34, ReleasePlan::TYPE_PRERELEASE));
    }

    public function testFindOldKeyFirstStableReplacesLastRc(): void
    {
        $this->assertSame('34.0.0 RC5', ReleasesJson::findOldKey($this->sample(), 34, ReleasePlan::TYPE_FIRST_STABLE));
    }

    public function testFindOldKeyNoneForNewMajor(): void
    {
        $this->assertNull(ReleasesJson::findOldKey($this->sample(), 35, ReleasePlan::TYPE_PRERELEASE));
    }

    public function testFindOldKeyIgnoresEnterprise(): void
    {
        $releases = ['34.0.0' => [], '34.0.0 Enterprise' => []];
        $this->assertSame('34.0.0', ReleasesJson::findOldKey($releases, 34, ReleasePlan::TYPE_PATCH));
    }

    public function testNewEntryOmitsDeployAt100(): void
    {
        $e = ReleasesJson::newEntry('33.0.6.1', 'BZ2', 'ZIP', 100);
        $this->assertSame(['internalVersion' => '33.0.6.1', 'signatures' => ['bz2' => 'BZ2', 'zip' => 'ZIP']], $e);
    }

    public function testNewEntryIncludesDeployBelow100(): void
    {
        $e = ReleasesJson::newEntry('34.0.0.7', 'BZ2', 'ZIP', 30);
        $this->assertSame(30, $e['deploy']);
    }

    public function testApplyReplacesOldKey(): void
    {
        $out = ReleasesJson::apply($this->sample(), '33.0.5', '33.0.6', ['internalVersion' => '33.0.6.1']);
        $this->assertArrayNotHasKey('33.0.5', $out);
        $this->assertArrayHasKey('33.0.6', $out);
    }

    public function testApplyAppendsWhenNoOldKey(): void
    {
        $out = ReleasesJson::apply($this->sample(), null, '35.0.0 beta 1', ['internalVersion' => '35.0.0.1']);
        $this->assertArrayHasKey('35.0.0 beta 1', $out);
        $this->assertCount(5, $out);
    }

    public function testEncodeUsesTabsAndTrailingNewline(): void
    {
        $json = ReleasesJson::encode(['33.0.6' => ['internalVersion' => '33.0.6.1']]);
        $this->assertStringContainsString("\n\t\"33.0.6\"", $json, 'top-level keys indented with a tab');
        $this->assertStringNotContainsString("\n    \"", $json, 'no 4-space indentation');
        $this->assertStringEndsWith("\n", $json);
    }
}
