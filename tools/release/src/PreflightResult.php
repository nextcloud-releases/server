<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

final class PreflightResult
{
    /**
     * @param list<string> $problems what refuses the release
     * @param list<string> $notes    worth reading, but not blocking
     */
    public function __construct(
        public readonly string $version,
        public readonly string $tag,
        public readonly string $branch,
        public readonly string $config,
        public readonly array $problems,
        public readonly array $notes,
    ) {
    }

    public function passed(): bool
    {
        return $this->problems === [];
    }
}
