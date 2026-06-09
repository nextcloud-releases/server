<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

/**
 * A parsed Nextcloud release tag (e.g. "v34.0.4", "v35.0.0beta1").
 *
 * Mirrors the parsing in .github/scripts/update-milestones.sh:
 *  - major/minor/patch are the numeric components (patch with any
 *    alpha/beta/rc suffix stripped),
 *  - isPrerelease is true when the tag contains alpha/beta/rc,
 *  - isFirstBeta is true only for the .0.0beta1 of a new major.
 */
final class Version
{
    public function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        public readonly bool $isPrerelease,
        public readonly bool $isFirstBeta,
    ) {
    }

    public static function fromTag(string $tag): self
    {
        $version = preg_replace('/^v/', '', $tag);
        // Strip the pre-release suffix (alpha/beta/rc and anything after it)
        // before reading the numeric components.
        $numeric = preg_replace('/(alpha|beta|rc).*/', '', $version);
        $parts = explode('.', $numeric);

        return new self(
            major: (int) ($parts[0] ?? 0),
            minor: (int) ($parts[1] ?? 0),
            patch: (int) ($parts[2] ?? 0),
            isPrerelease: preg_match('/(alpha|beta|rc)/', $tag) === 1,
            isFirstBeta: preg_match('/\.0\.0beta1$/', $tag) === 1,
        );
    }
}
