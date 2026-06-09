<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Updater;

/**
 * Everything the updater-server change derives from a release tag: the display
 * version, the download URL pieces, channel/stability, the release type and the
 * deploy percentage. Mirrors the tag parsing in update-updater-server.sh.
 *
 * Release type here is the *base* classification (first_stable / patch /
 * prerelease). It only becomes "first_prerelease" once a lookup confirms there
 * is no existing entry for the major (see ReleasesJson::findOldKey).
 */
final class ReleasePlan
{
    public const TYPE_FIRST_STABLE = 'first_stable';
    public const TYPE_PATCH = 'patch';
    public const TYPE_PRERELEASE = 'prerelease';

    public function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        public readonly string $versionString,
        public readonly string $urlVersion,
        public readonly string $urlDir,
        public readonly string $stability,
        public readonly string $channel,
        public readonly string $releaseType,
        public readonly int $deploy,
    ) {
    }

    public static function fromTag(string $tag, ?int $deployOverride = null): self
    {
        $version = preg_replace('/^v/', '', $tag);
        $major = (int) (preg_match('/^(\d+)/', $version, $m) ? $m[1] : 0);
        $parts = explode('.', $version);
        $minor = (int) ($parts[1] ?? 0);
        $patch = (int) (preg_match('/^(\d+)/', $parts[2] ?? '0', $m) ? $m[1] : 0);

        $modifier = preg_match('/(rc|beta|alpha)(\d+)$/i', $version, $m) ? strtolower($m[1] . $m[2]) : '';
        $modWord = $m[1] ?? '';
        $modNum = $m[2] ?? '';

        $base = "{$major}.{$minor}.{$patch}";

        if ($modifier !== '') {
            // RC -> "RC5" (uppercase, no space in the token); beta/alpha -> "beta 5".
            $display = strtolower($modWord) === 'rc'
                ? strtoupper($modWord) . $modNum
                : strtolower($modWord) . ' ' . $modNum;
            $versionString = "{$base} {$display}";
            $urlVersion = $base . $modifier;
            $urlDir = 'prereleases';
            $stability = 'beta';
            $type = self::TYPE_PRERELEASE;
        } elseif ($patch === 0 && $minor === 0) {
            $versionString = $base;
            $urlVersion = $base;
            $urlDir = 'releases';
            $stability = 'stable';
            $type = self::TYPE_FIRST_STABLE;
        } else {
            $versionString = $base;
            $urlVersion = $base;
            $urlDir = 'releases';
            $stability = 'stable';
            $type = self::TYPE_PATCH;
        }

        $deploy = $deployOverride ?? self::autoDeploy($type, $stability, $minor, $patch);

        return new self(
            major: $major,
            minor: $minor,
            patch: $patch,
            versionString: $versionString,
            urlVersion: $urlVersion,
            urlDir: $urlDir,
            stability: $stability,
            channel: $stability,
            releaseType: $type,
            deploy: $deploy,
        );
    }

    /** X.0.0 -> 30%, X.0.1 -> 70%, everything else -> 100%. */
    private static function autoDeploy(string $type, string $stability, int $minor, int $patch): int
    {
        if ($type === self::TYPE_FIRST_STABLE) {
            return 30;
        }
        if ($stability === 'stable' && $minor === 0 && $patch === 1) {
            return 70;
        }
        return 100;
    }
}
