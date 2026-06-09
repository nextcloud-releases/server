<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests\Support;

/**
 * Builds the .snap body for the milestone/tagger snapshot tests: the raw
 * tab-separated mutation journal (the standard, stable format the assertion
 * tests also use), preceded by comment lines that say which scenario produced
 * it and what the columns mean - so a .snap file is understandable on its own.
 */
final class Journal
{
    public const MILESTONE_LEGEND =
        'columns (tab): <action> <repo> <args> -- '
        . 'create: title, due=<date|-> | setdue: #ms, date | move: #issue, #ms | close: #ms';

    public const TAG_LEGEND =
        'tags (tab): <action> <repo> <tag> <sha> -- tag | retag adds force=<bool>';

    /** @param list<string> $lines raw journal lines */
    public static function snapshot(string $description, string $legend, array $lines): string
    {
        $body = $lines === [] ? '(no changes)' : implode("\n", $lines);
        return "# {$description}\n# {$legend}\n{$body}";
    }
}
