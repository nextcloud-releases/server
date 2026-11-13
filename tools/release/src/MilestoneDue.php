<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

/**
 * The due dates a release's milestone update will set, resolved the same way
 * for milestones:update (on release day) and schedule:check (before anything
 * is tagged), so the gate can never pass a release the update then rejects.
 */
final class MilestoneDue
{
    /**
     * A stable release whose next patch milestone has no date either ended the
     * series or ran against a stale schedule, and the two look the same from
     * the schedule alone. Only an EOL major may carry on without one; the EOL
     * lookup costs an API read and is skipped whenever a date is there, so an
     * explicit schedule entry always wins.
     *
     * @return array{next: ?string, upcoming: ?string, seriesFinal: bool}
     * @throws \RuntimeException when a stable release that is not the last of
     *                           its series has no date for its next milestone
     */
    public static function resolve(
        ReleaseSchedule $schedule,
        Version $version,
        MajorLifecycle $lifecycle,
        ?string $nextOverride = null,
        ?string $upcomingOverride = null,
    ): array {
        $seriesFinal = !$schedule->hasNext($version, $nextOverride)
            && $lifecycle->isFinalRelease($version);

        $due = $seriesFinal
            ? ['next' => null, 'upcoming' => null]
            : $schedule->resolve($version, $nextOverride, $upcomingOverride);

        return [...$due, 'seriesFinal' => $seriesFinal];
    }
}
