<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

/**
 * The maintenance release cadence: a round is a Thursday, at most one per
 * calendar month, four weeks after the previous one, stretched to five when
 * four weeks would put a second round in the same month.
 *
 * Four weeks is shorter than a month, so a plain 28-day chain drifts backwards
 * through the calendar and eventually lands twice in one month; the five-week
 * step is what resyncs it. Stepping in whole weeks keeps every round on the
 * same weekday as the one it is measured from.
 *
 * This is the committed default, not the authority: the wiki release schedule
 * wins wherever it differs, which is why the dates this produces are proposed
 * in a pull request for the release team rather than used directly.
 */
final class ReleaseCadence
{
    private const FOUR_WEEKS = '+28 days';
    private const FIVE_WEEKS = '+35 days';

    /**
     * The round after $date (both YYYY-MM-DD).
     *
     * @throws \InvalidArgumentException on a malformed date
     */
    public static function next(string $date): string
    {
        $from = self::parse($date);
        $next = $from->modify(self::FOUR_WEEKS);
        if ($next->format('Y-m') === $from->format('Y-m')) {
            $next = $from->modify(self::FIVE_WEEKS);
        }
        return $next->format('Y-m-d');
    }

    /**
     * The next $count rounds after $date, earliest first, stopping early at
     * $until when given (the end of a major's maintenance window: a round past
     * it is a round that will never happen).
     *
     * @return list<string>
     */
    public static function following(string $date, int $count, ?string $until = null): array
    {
        $rounds = [];
        $cursor = $date;
        for ($i = 0; $i < $count; $i++) {
            $cursor = self::next($cursor);
            if ($until !== null && $cursor > $until) {
                break;
            }
            $rounds[] = $cursor;
        }
        return $rounds;
    }

    private static function parse(string $date): \DateTimeImmutable
    {
        if (!DueDate::isValid($date)) {
            throw new \InvalidArgumentException("Invalid date '{$date}', expected YYYY-MM-DD");
        }
        return new \DateTimeImmutable($date . ' 00:00:00 UTC');
    }
}
