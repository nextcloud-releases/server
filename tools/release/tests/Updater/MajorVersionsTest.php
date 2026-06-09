<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests\Updater;

use Nextcloud\ReleaseTools\Updater\MajorVersions;
use PHPUnit\Framework\TestCase;

/**
 * What: adding a new major (with its minimum PHP) to major_versions.json.
 *
 * Why: a first pre-release of a new major must register it once, at the top,
 * without disturbing existing majors; re-running must be a no-op.
 */
final class MajorVersionsTest extends TestCase
{
    public function testAddsNewMajorAtTop(): void
    {
        $out = MajorVersions::ensureMajor(['34' => ['minPHP' => '8.1']], 35, '8.3');
        // PHP casts numeric-string array keys to ints; JSON still encodes them as strings.
        $this->assertSame([35, 34], array_keys($out));
        $this->assertSame(['minPHP' => '8.3'], $out[35]);
    }

    public function testNoOpWhenMajorExists(): void
    {
        $in = ['35' => ['minPHP' => '8.0'], '34' => ['minPHP' => '8.1']];
        $this->assertSame($in, MajorVersions::ensureMajor($in, 35, '8.3'));
    }

    public function testEncodeUsesTabs(): void
    {
        $json = MajorVersions::encode(['35' => ['minPHP' => '8.3']]);
        $this->assertStringContainsString("\n\t\"35\"", $json);
        $this->assertStringNotContainsString("\n    \"", $json);
    }
}
