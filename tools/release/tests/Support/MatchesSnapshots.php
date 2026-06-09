<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests\Support;

/**
 * Tiny golden-file snapshot helper. A test produces a string (e.g. the journal
 * of GitHub mutations or a rendered file) and compares it to a committed
 * `tests/snapshots/<name>.snap`. Missing snapshots are written on first run;
 * re-run with `UPDATE_SNAPSHOTS=1` to regenerate after an intended change.
 */
trait MatchesSnapshots
{
    protected function assertMatchesSnapshot(string $name, string $actual): void
    {
        $file = __DIR__ . '/../snapshots/' . $name . '.snap';
        $actual = rtrim($actual, "\n") . "\n";

        if (getenv('UPDATE_SNAPSHOTS') === '1' || !is_file($file)) {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0o777, true);
            }
            file_put_contents($file, $actual);
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertSame(
            file_get_contents($file),
            $actual,
            "Snapshot '{$name}' differs. Re-run with UPDATE_SNAPSHOTS=1 if the change is intended.",
        );
    }
}
