<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Updater;

/**
 * The values the feature-file templating needs: the new release (from
 * ReleasePlan), the old release being replaced (read from releases.json), and a
 * few cross-major facts used when appending scenarios (previous major's stable
 * internal version, the major's minimum PHP, its EOL date).
 */
final class FeatureInputs
{
    public function __construct(
        public readonly int $major,
        public readonly string $urlVersion,
        public readonly string $versionString,
        public readonly string $internalVersion,
        public readonly string $zipSig,
        public readonly string $urlDir,
        public readonly string $oldUrlVersion,
        public readonly string $oldVersionString,
        public readonly string $oldInternal,
        public readonly string $oldZipSig,
        public readonly int $prevMajor,
        public readonly string $prevStableInternal,
        public readonly string $phpVersion,
        public readonly string $eolDate,
    ) {
    }

    /** The Gherkin EOL assertion: a date when known, otherwise the "0" sentinel. */
    public function eolLine(): string
    {
        return $this->eolDate !== ''
            ? "And EOL date is \"{$this->eolDate}\""
            : 'And EOL is set to "0"';
    }
}
