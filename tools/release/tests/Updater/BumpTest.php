<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests\Updater;

use Nextcloud\ReleaseTools\Updater\Bump;
use PHPUnit\Framework\TestCase;

/**
 * What: Bump's guard against applying a release with no entry to replace.
 *
 * Why: a patch / first-stable run needs an existing entry for the major;
 * without one (e.g. re-running an already-applied release) the old values are
 * empty and the feature-file substitution would corrupt the """ doc-string
 * delimiters. The bash script die'd here, so we must too. Found via a live
 * dry-run that mangled every signature block.
 */
final class BumpTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/updater';
    private const FAKE = 'sig';

    public function testThrowsWhenNoEntryToReplaceForPatch(): void
    {
        $work = $this->freshBase();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No existing entry found for major 35');
        try {
            // Major 35 has no stable entry in the base fixture -> must abort.
            (new Bump($work))->run('v35.0.6', self::FAKE, self::FAKE, '35.0.6.1');
        } finally {
            $this->removeTree($work);
        }
    }

    public function testPatchLeavesDocStringDelimitersIntact(): void
    {
        $work = $this->freshBase();
        // 33.0.6 has a stable predecessor (33.0.5) -> valid patch.
        (new Bump($work))->run('v33.0.6', self::FAKE, self::FAKE, '33.0.6.1');
        $beta = (string) file_get_contents("{$work}/tests/integration/features/beta.feature");
        $this->assertStringContainsString("    \"\"\"\n", $beta, 'doc-string delimiters preserved');
        $this->assertStringNotContainsString('""sig', $beta);
        $this->removeTree($work);
    }

    private function freshBase(): string
    {
        $work = sys_get_temp_dir() . '/bumptest-' . bin2hex(random_bytes(4));
        // Create the root explicitly: the copy loop only mkdir's subdirs, so a
        // root-level file yielded before any directory (iterator order is
        // filesystem-dependent) would otherwise copy into a missing parent.
        mkdir($work, 0o777, true);
        $src = self::FIXTURES . '/base';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($it as $item) {
            $target = $work . '/' . substr($item->getPathname(), strlen($src) + 1);
            $item->isDir() ? @mkdir($target, 0o777, true) : copy($item->getPathname(), $target);
        }
        return $work;
    }

    private function removeTree(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
