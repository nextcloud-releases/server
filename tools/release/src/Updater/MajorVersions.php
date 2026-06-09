<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Updater;

/**
 * Transform on config/major_versions.json: add a new major (with its minimum
 * PHP) at the top, as update-updater-server.sh does for a first pre-release.
 * Existing majors are left untouched.
 */
final class MajorVersions
{
    /**
     * @param array<string, mixed> $majors
     * @return array<string, mixed>
     */
    public static function ensureMajor(array $majors, int $major, string $minPhp): array
    {
        $key = (string) $major;
        if (array_key_exists($key, $majors)) {
            return $majors;
        }
        // Prepend, matching jq's `{($m): ...} + .`.
        return [$key => ['minPHP' => $minPhp]] + $majors;
    }

    public static function encode(array $data): string
    {
        return ReleasesJson::encode($data);
    }
}
