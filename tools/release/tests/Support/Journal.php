<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests\Support;

/**
 * Renders FakeGitHubApi's tab-separated mutation journal into plain English,
 * so the committed .snap files read as a description of what a release does
 * rather than as terse tab columns. The raw journal stays the source of truth
 * for the assertion-based tests; this is only for the snapshots.
 */
final class Journal
{
    /** @param list<string> $lines */
    public static function render(array $lines): string
    {
        if ($lines === []) {
            return '(no changes)';
        }
        return implode("\n", array_map(self::describe(...), $lines));
    }

    public static function describe(string $line): string
    {
        $p = explode("\t", $line);
        return match ($p[0]) {
            'create' => sprintf(
                'create milestone "%s" in %s%s',
                $p[2],
                $p[1],
                ($p[3] ?? 'due=-') === 'due=-' ? '' : ' (due ' . substr($p[3], 4) . ')',
            ),
            'close'  => sprintf('close milestone #%s in %s', $p[2], $p[1]),
            'setdue' => sprintf('set due date of milestone #%s to %s in %s', $p[2], $p[3], $p[1]),
            'move'   => sprintf('move issue #%s to milestone #%s in %s', $p[2], $p[3], $p[1]),
            'tag'    => sprintf('tag %s as %s (at %s)', $p[1], $p[2], $p[3]),
            'retag'  => sprintf('re-tag %s as %s (at %s, %s)', $p[1], $p[2], $p[3], $p[4]),
            default  => $line,
        };
    }
}
