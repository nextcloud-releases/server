<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

/** Outcome of tagging one repository. status is OK | SKIPPED | FAILED. */
final class TagResult
{
    public function __construct(
        public readonly string $repo,
        public readonly string $branch,
        public readonly string $status,
        public readonly string $detail,
    ) {
    }
}
