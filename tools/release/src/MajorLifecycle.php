<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

use Nextcloud\ReleaseTools\GitHub\GitHubApi;
use Nextcloud\ReleaseTools\GitHub\Milestone;

/**
 * When a major leaves maintenance, derived from its own milestones rather than
 * a hand-maintained end-of-life list.
 *
 * A major is maintained for 12 months from its release, and the "Nextcloud N"
 * milestone due date is that release date: it matches the announced date for
 * every major from 30 onwards, and updater_server's config/major_versions.json
 * carries exactly that date plus 12 months.
 *
 * The comparison is by month, never by day. A maintenance round shifts a week
 * either way, so 32.0.15 on 2026-09-10 is the last release of a series whose
 * 12-month window runs to 2026-09-27. Months are compared as "YYYY-MM"
 * strings, which orders correctly because the parts are zero-padded.
 */
final class MajorLifecycle
{
    /** Months of maintenance a major gets from its release. */
    private const SUPPORT_MONTHS = 12;

    /** A major's own milestones live here; the app repos mirror them. */
    private const SERVER_REPO = 'nextcloud/server';

    /** @var array<string, ?string>|null milestone title => due date, fetched once */
    private ?array $dueByTitle = null;

    public function __construct(
        private readonly GitHubApi $api,
    ) {
    }

    /**
     * Whether $version is the last release of its series, i.e. it ships in or
     * after the month its major leaves maintenance.
     *
     * False when the major's release date cannot be read, so a missing or
     * due-date-less "Nextcloud N" milestone never silently ends a series.
     */
    public function isFinalRelease(Version $version): bool
    {
        $eol = $this->eolMonth($version->major);
        return $eol !== null && $this->releaseMonth($version) >= $eol;
    }

    /** The month (YYYY-MM) a major leaves maintenance, or null when unknown. */
    public function eolMonth(int $major): ?string
    {
        $released = $this->month(MilestonePlan::name($major));
        return $released !== null ? self::addMonths($released, self::SUPPORT_MONTHS) : null;
    }

    /**
     * Last day of a major's maintenance window (its release date plus 12
     * months), or null when the release date cannot be read.
     */
    public function windowEnd(int $major): ?string
    {
        $released = $this->date(MilestonePlan::name($major));
        if ($released === null) {
            return null;
        }
        return (new \DateTimeImmutable($released . ' 00:00:00 UTC'))
            ->modify('+' . self::SUPPORT_MONTHS . ' months')
            ->format('Y-m-d');
    }

    /**
     * The date a version is due, from its own milestone, or null when that
     * milestone is missing or carries no due date.
     */
    public function releaseDate(Version $version): ?string
    {
        foreach (MilestonePlan::currentMilestones($version) as $title) {
            $date = $this->date($title);
            if ($date !== null) {
                return $date;
            }
        }
        return null;
    }

    /**
     * The month a version ships in, from its own milestone's due date. Falls
     * back to the current month when that milestone carries no date: a re-run
     * long after the fact then reads as later than the release, which can only
     * ever make a series look finished, never revive a finished one.
     */
    public function releaseMonth(Version $version): string
    {
        foreach (MilestonePlan::currentMilestones($version) as $title) {
            $month = $this->month($title);
            if ($month !== null) {
                return $month;
            }
        }
        return gmdate('Y-m');
    }

    /**
     * The majors whose maintenance window has not closed as of $asOf (today by
     * default), derived from the "Nextcloud N" milestones rather than a list
     * that has to be kept current. Long-EOL majors drop out on their own.
     *
     * A window that is open does not promise another release: 32's window ran
     * to 2026-09-27 while its last release was 32.0.15 on 2026-09-10. Callers
     * still have to check whether the next round fits inside it.
     *
     * @return list<int> ascending
     */
    public function maintainedMajors(?string $asOf = null): array
    {
        $asOf ??= gmdate('Y-m-d');
        $majors = [];
        foreach ($this->dues() as $title => $due) {
            if ($due === null || preg_match('/^Nextcloud (\d+)$/', $title, $m) !== 1) {
                continue;
            }
            $major = (int) $m[1];
            $end = $this->windowEnd($major);
            if ($end !== null && $end >= $asOf) {
                $majors[] = $major;
            }
        }
        sort($majors);
        return $majors;
    }

    /** "YYYY-MM" advanced by a number of months. */
    public static function addMonths(string $month, int $count): string
    {
        [$year, $index] = array_map('intval', explode('-', $month));
        // Count in absolute months so December never rolls over into a 13th.
        $total = $year * 12 + ($index - 1) + $count;
        return sprintf('%04d-%02d', intdiv($total, 12), $total % 12 + 1);
    }

    /** The YYYY-MM of a milestone's due date, or null when it has none. */
    private function month(string $title): ?string
    {
        $date = $this->date($title);
        return $date !== null ? substr($date, 0, 7) : null;
    }

    /** The YYYY-MM-DD of a milestone's due date, or null when it has none. */
    private function date(string $title): ?string
    {
        $due = $this->dues()[$title] ?? null;
        return $due !== null ? substr($due, 0, 10) : null;
    }

    /**
     * Every milestone's due date, fetched once.
     *
     * @return array<string, ?string>
     */
    private function dues(): array
    {
        return $this->dueByTitle ??= self::indexDues($this->api->listMilestones(self::SERVER_REPO));
    }

    /**
     * @param list<Milestone> $milestones
     * @return array<string, ?string>
     */
    private static function indexDues(array $milestones): array
    {
        $out = [];
        foreach ($milestones as $m) {
            $out[$m->title] = $m->dueOn;
        }
        return $out;
    }
}
