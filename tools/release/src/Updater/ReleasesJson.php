<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Updater;

/**
 * Transforms on the updater server's config/releases.json. Pure: takes the
 * decoded map (version string -> entry) and returns a new one. Mirrors the jq
 * in update-updater-server.sh, including the tab-indented output the repo uses.
 */
final class ReleasesJson
{
    /**
     * The entry this release replaces: for a patch, the current stable entry of
     * the major; for a (pre)release, the latest RC/beta/alpha entry. Null when
     * there is none (a first pre-release of a new major).
     *
     * @param array<string, mixed> $releases
     */
    public static function findOldKey(array $releases, int $major, string $type): ?string
    {
        $prefix = "{$major}.";
        $found = null;
        foreach (array_keys($releases) as $key) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            $isPre = preg_match('/[Rr][Cc]|[Bb]eta|[Aa]lpha/', $key) === 1;
            if ($type === ReleasePlan::TYPE_PATCH) {
                if (!$isPre && !str_contains($key, 'Enterprise')) {
                    $found = $key; // keep last match (insertion order)
                }
            } elseif ($isPre) {
                $found = $key;
            }
        }
        return $found;
    }

    /**
     * The new entry. `deploy` is only written when it is not 100%.
     *
     * @return array<string, mixed>
     */
    public static function newEntry(string $internalVersion, string $bz2Sig, string $zipSig, int $deploy): array
    {
        $entry = [
            'internalVersion' => $internalVersion,
            'signatures' => ['bz2' => $bz2Sig, 'zip' => $zipSig],
        ];
        if ($deploy !== 100) {
            $entry['deploy'] = $deploy;
        }
        return $entry;
    }

    /**
     * Replace $oldKey (if given) with $newKey => $entry, else just append it.
     *
     * @param array<string, mixed> $releases
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public static function apply(array $releases, ?string $oldKey, string $newKey, array $entry): array
    {
        if ($oldKey !== null) {
            unset($releases[$oldKey]);
        }
        $releases[$newKey] = $entry;
        return $releases;
    }

    /** Encode with tab indentation + trailing newline, matching the repo style. */
    public static function encode(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // json_encode pretty-prints with 4 spaces; the repo uses tabs.
        $json = preg_replace_callback(
            '/^( +)/m',
            static fn (array $m): string => str_repeat("\t", intdiv(strlen($m[1]), 4)),
            $json,
        );
        return $json . "\n";
    }
}
