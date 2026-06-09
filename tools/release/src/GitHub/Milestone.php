<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\GitHub;

/** A GitHub milestone as the release tooling cares about it. */
final class Milestone
{
    public function __construct(
        public readonly int $number,
        public readonly string $title,
        public readonly string $state,
        public readonly int $openIssues,
        public readonly ?string $dueOn,
    ) {
    }
}
