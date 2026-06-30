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
        // Rollout percentage of this release. Drives the staged-rollout
        // scenario's mtime (an install is offered the update iff the last two
        // digits of its mtime are <= deploy; see updater_server Response.php).
        public readonly int $deploy = 100,
        // The in-flight RC a stable patch promotes (patch_promote_rc only):
        // these describe the pre-release being retired from the beta channel,
        // distinct from the old* fields, which describe the stable being
        // replaced on the stable channel. Empty for every other release shape.
        public readonly string $rcUrlVersion = '',
        public readonly string $rcVersionString = '',
        public readonly string $rcInternal = '',
        public readonly string $rcZipSig = '',
    ) {
    }

    /** The Gherkin EOL assertion: a date when known, otherwise the "0" sentinel. */
    public function eolLine(): string
    {
        return $this->eolDate !== ''
            ? "And EOL date is set to \"{$this->eolDate}\""
            : 'And EOL is set to "0"';
    }
}
