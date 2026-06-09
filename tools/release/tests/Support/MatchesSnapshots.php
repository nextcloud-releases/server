<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests\Support;

/**
 * Tiny golden-file snapshot helper. A test produces some data and compares it to
 * a committed file under `tests/snapshots/`. Snapshots are stored as
 * pretty-printed JSON (`<name>.snap.json`) so they are self-describing and diff
 * cleanly - the keys name every field, no separate legend needed. Missing
 * snapshots are written on first run; re-run with `UPDATE_SNAPSHOTS=1` to
 * regenerate after an intended change.
 */
trait MatchesSnapshots
{
    /** Compare structured data to a committed JSON snapshot. */
    protected function assertMatchesJsonSnapshot(string $name, mixed $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->compareOrWrite(__DIR__ . '/../snapshots/' . $name . '.snap.json', $json . "\n", $name);
    }

    private function compareOrWrite(string $file, string $actual, string $name): void
    {
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
