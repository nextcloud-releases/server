<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Updater;

/**
 * Base64 signature handling for the feature files. Signatures are stored
 * wrapped to 64-character lines (matching `fold -w 64` in the bash script).
 */
final class Signature
{
    /** @return list<string> the signature folded into 64-char lines */
    public static function wrap(string $sig): array
    {
        return str_split($sig, 64);
    }

    /** The signature as a feature-file block: each 64-char line indented 4 spaces. */
    public static function block(string $sig): string
    {
        return implode("\n", array_map(static fn (string $l): string => '    ' . $l, self::wrap($sig)));
    }

    /**
     * Replace an old signature with a new one in $text. Each wrapped line is
     * high-entropy and unique, so a line-by-line replace is safe.
     */
    public static function replace(string $text, string $oldSig, string $newSig): string
    {
        if ($oldSig === '') {
            return $text;
        }
        $old = self::wrap($oldSig);
        $new = self::wrap($newSig);
        foreach ($old as $i => $line) {
            if ($line === '' || !isset($new[$i])) {
                continue;
            }
            $text = str_replace($line, $new[$i], $text);
        }
        return $text;
    }
}
