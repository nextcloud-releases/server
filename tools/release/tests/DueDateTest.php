<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\DueDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What: YYYY-MM-DD validation + ISO conversion.
 *
 * Why: a malformed date must fail fast (the bash exited 1); valid dates must
 * become the exact `...T00:00:00Z` the GitHub API expects.
 */
final class DueDateTest extends TestCase
{
    public function testValidConvertsToIso(): void
    {
        $this->assertSame('2026-07-02T00:00:00Z', DueDate::toIso('2026-07-02'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'slashes'    => ['2026/07/02'];
        yield 'short'      => ['2026-7-2'];
        yield 'empty'      => [''];
        yield 'words'      => ['next thursday'];
    }

    #[DataProvider('invalid')]
    public function testInvalidThrows(string $date): void
    {
        $this->assertFalse(DueDate::isValid($date));
        $this->expectException(\InvalidArgumentException::class);
        DueDate::toIso($date);
    }
}
