<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

/**
 * The committed milestone due-date schedule (release-schedule.json): a map of
 * milestone title => "YYYY-MM-DD". It is the source of truth for when the next
 * and upcoming patch milestones of a stable release are due, so the pipeline
 * sets them automatically instead of relying on a manual input.
 *
 * Resolution fails hard: a stable release whose next/upcoming milestone has no
 * entry (and no explicit override) aborts the milestone update, forcing the
 * schedule to be kept current rather than silently shipping without a due date.
 */
final class ReleaseSchedule
{
    /** @param array<string, string> $byTitle milestone title => YYYY-MM-DD */
    private function __construct(
        private readonly array $byTitle,
    ) {
    }

    /** Load from a JSON file; a null/empty path yields an empty schedule. */
    public static function load(?string $path): self
    {
        if ($path === null || $path === '') {
            return new self([]);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read release schedule: {$path}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Release schedule is not a JSON object: {$path}");
        }
        $byTitle = [];
        foreach ($data as $title => $date) {
            if (!is_string($title) || !is_string($date)) {
                throw new \RuntimeException(
                    'Release schedule entries must be "Milestone title": "YYYY-MM-DD"',
                );
            }
            $byTitle[$title] = $date;
        }
        return new self($byTitle);
    }

    /**
     * ISO due dates for the next and upcoming patch milestones of a stable
     * release. Explicit overrides win over the schedule. Pre-releases (including
     * first betas) roll no patch milestones, so they need no due dates.
     *
     * @return array{next: ?string, upcoming: ?string}
     * @throws \RuntimeException when a stable release has a milestone with no
     *                           override and no schedule entry
     */
    public function resolve(Version $version, ?string $nextOverride = null, ?string $upcomingOverride = null): array
    {
        if ($version->isPrerelease) {
            return ['next' => null, 'upcoming' => null];
        }

        $missing = [];
        $next = $this->pick(MilestonePlan::nextMilestone($version), $nextOverride, $missing);
        $upcoming = $this->pick(MilestonePlan::upcomingMilestone($version), $upcomingOverride, $missing);

        if ($missing !== []) {
            throw new \RuntimeException(
                'No due date for ' . implode(' and ', $missing)
                . '. Add it to the release schedule (release-schedule.json) or pass it explicitly.',
            );
        }

        return ['next' => $next, 'upcoming' => $upcoming];
    }

    /** @param list<string> $missing collects unresolved titles */
    private function pick(string $title, ?string $override, array &$missing): ?string
    {
        $raw = $override ?? ($this->byTitle[$title] ?? null);
        if ($raw === null) {
            $missing[] = "'{$title}'";
            return null;
        }
        return DueDate::toIso($raw);
    }
}
