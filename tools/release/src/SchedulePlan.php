<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

/**
 * What release-schedule.json should look like once a release ships: the next
 * two patch milestones of that series dated, and the entry for the version
 * being released gone.
 *
 * Two rules keep this from fighting the humans who own the schedule:
 *
 *  - an existing entry is never re-dated. The wiki wins, and a date already in
 *    the file is assumed to have come from it, so the cadence only fills gaps.
 *    The chain continues from whatever date is there, not from what the cadence
 *    would have picked.
 *  - a round past the major's maintenance window is not scheduled at all, since
 *    it is a round that will never happen.
 *
 * Removing shipped entries is hygiene, not a requirement: ReleaseSchedule only
 * ever reads patch+1 and patch+2 of the version being released, so nothing
 * breaks if the removals are never applied.
 */
final class SchedulePlan
{
    /**
     * @param array<string, string> $add title => YYYY-MM-DD, dates to fill in
     * @param list<string> $remove titles for versions that have now shipped
     */
    private function __construct(
        public readonly array $add,
        public readonly array $remove,
    ) {
    }

    /**
     * @param Version $version the version being released
     * @param array<string, string> $schedule title => YYYY-MM-DD, as committed
     * @param string $anchor the round date of $version (YYYY-MM-DD)
     * @param ?string $windowEnd last day of the major's maintenance window
     * @param int $depth how many future milestones to keep dated
     */
    public static function forRelease(
        Version $version,
        array $schedule,
        string $anchor,
        ?string $windowEnd = null,
        int $depth = 2,
    ): self {
        $add = [];
        $cursor = $anchor;
        for ($i = 1; $i <= $depth; $i++) {
            $title = MilestonePlan::name($version->major, $version->minor, $version->patch + $i);
            if (isset($schedule[$title])) {
                // Already dated, by the wiki or by hand. Keep it and chain on.
                $cursor = $schedule[$title];
                continue;
            }
            $cursor = ReleaseCadence::next($cursor);
            if ($windowEnd !== null && $cursor > $windowEnd) {
                // Past end of life: this round is never going to happen, and
                // neither is any after it.
                break;
            }
            $add[$title] = $cursor;
        }

        return new self($add, self::shipped($version, $schedule));
    }

    /**
     * Entries for versions of this series that have shipped, or are shipping
     * now. Everything at or below the current patch, not just the exact match,
     * so a round that was skipped does not leave an entry behind for good.
     *
     * @param array<string, string> $schedule
     * @return list<string>
     */
    private static function shipped(Version $version, array $schedule): array
    {
        $shipped = [];
        foreach (array_keys($schedule) as $title) {
            if (preg_match('/^Nextcloud (\d+)\.(\d+)\.(\d+)$/', $title, $m) !== 1) {
                continue;
            }
            if ((int) $m[1] === $version->major
                && (int) $m[2] === $version->minor
                && (int) $m[3] <= $version->patch
            ) {
                $shipped[] = $title;
            }
        }
        return $shipped;
    }

    public function isEmpty(): bool
    {
        return $this->add === [] && $this->remove === [];
    }

    /**
     * The schedule with this plan applied, ordered by version so the diff stays
     * readable.
     *
     * @param array<string, string> $schedule
     * @return array<string, string>
     */
    public function applyTo(array $schedule): array
    {
        foreach ($this->remove as $title) {
            unset($schedule[$title]);
        }
        $schedule = [...$schedule, ...$this->add];
        uksort($schedule, static fn (string $a, string $b) => self::sortKey($a) <=> self::sortKey($b));
        return $schedule;
    }

    /** @return list<int|string> version components, so 33.0.9 sorts before 33.0.10 */
    private static function sortKey(string $title): array
    {
        if (preg_match('/^Nextcloud (\d+)(?:\.(\d+)\.(\d+))?$/', $title, $m) !== 1) {
            return [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, $title];
        }
        return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), ''];
    }
}
