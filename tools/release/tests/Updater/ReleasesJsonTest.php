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

    public function testFindPrereleaseForBaseMatchesInFlightRc(): void
    {
        $releases = [
            '34.0.0' => [],
            '34.0.1 RC1' => [],
            '34.0.1 RC2' => [],
        ];
        // The latest RC for the base wins (insertion order).
        $this->assertSame('34.0.1 RC2', ReleasesJson::findPrereleaseForBase($releases, '34.0.1'));
    }

    public function testFindPrereleaseForBaseNullWhenNoRc(): void
    {
        // Direct hotfix: no RC in flight for 33.0.6.
        $this->assertNull(ReleasesJson::findPrereleaseForBase(['33.0.5' => []], '33.0.6'));
    }

    public function testFindPrereleaseForBaseDoesNotMatchPrefixSibling(): void
    {
        // "34.0.10 RC1" must not match base "34.0.1".
        $releases = ['34.0.10 RC1' => []];
        $this->assertNull(ReleasesJson::findPrereleaseForBase($releases, '34.0.1'));
    }

    public function testFindPrereleaseForBaseMatchesBeta(): void
    {
        $this->assertSame('34.0.0 beta 5', ReleasesJson::findPrereleaseForBase(['34.0.0 beta 5' => []], '34.0.0'));
    }

    public function testFindLatestForMajorPrefersInFlightPrerelease(): void
    {
        // A new major's first beta wants the prev major's latest entry, even an RC.
        $releases = ['33.0.2' => [], '33.0.3 RC2' => []];
        $this->assertSame('33.0.3 RC2', ReleasesJson::findLatestForMajor($releases, 33));
    }

    public function testFindLatestForMajorFallsBackToStable(): void
    {
        $this->assertSame('33.0.5', ReleasesJson::findLatestForMajor(['33.0.4' => [], '33.0.5' => []], 33));
    }

    public function testFindLatestForMajorIgnoresEnterpriseAndOtherMajors(): void
    {
        $releases = ['33.0.5' => [], '33.0.5 Enterprise' => [], '34.0.0' => []];
        $this->assertSame('33.0.5', ReleasesJson::findLatestForMajor($releases, 33));
        $this->assertNull(ReleasesJson::findLatestForMajor($releases, 35));
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
