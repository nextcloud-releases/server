<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

/**
 * Validates a YYYY-MM-DD due date and converts it to the ISO 8601 form the
 * GitHub API expects.
 */
final class DueDate
{
    public static function isValid(string $date): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
    }

    /** @throws \InvalidArgumentException on a malformed date */
    public static function toIso(string $date): string
    {
        if (!self::isValid($date)) {
            throw new \InvalidArgumentException("Invalid date '{$date}', expected YYYY-MM-DD");
        }
        return $date . 'T00:00:00Z';
    }
}
