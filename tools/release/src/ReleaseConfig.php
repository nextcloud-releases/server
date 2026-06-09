<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

/**
 * Helpers for the release config files (stableXX.json / master.json /
 * tag-only.json) and the tag list, mirroring the selection logic in the
 * workflows and audit-milestones.sh.
 */
final class ReleaseConfig
{
    /**
     * Repo list from a config file + the tag-only file (sorted, unique).
     *
     * @return list<string>
     */
    public static function repos(string $configPath, string $tagOnlyPath): array
    {
        return RepoList::merge(
            self::decode($configPath),
            self::decode($tagOnlyPath),
        );
    }

    /**
     * Major version a config covers: "stable34" -> 34; "master" -> the highest
     * released major + 1 (the one in development on master).
     *
     * @param list<string> $serverTags tag names on nextcloud-releases/server
     */
    public static function majorFromConfigBasename(string $basename, array $serverTags): int
    {
        if ($basename === 'master') {
            return self::highestStableMajor($serverTags) + 1;
        }
        if (preg_match('/^stable(\d+)$/', $basename, $m) === 1) {
            return (int) $m[1];
        }
        throw new \InvalidArgumentException("Cannot derive major from config '{$basename}'");
    }

    /**
     * The latest stable release for a major from the tag list, or null if there
     * is none yet.
     *
     * @param list<string> $tagNames
     */
    public static function latestStable(int $major, array $tagNames): ?Version
    {
        $matching = array_values(array_filter(
            $tagNames,
            static fn (string $t) => preg_match("/^v{$major}\\.\\d+\\.\\d+$/", $t) === 1,
        ));
        if ($matching === []) {
            return null;
        }
        usort($matching, 'version_compare');
        return Version::fromTag(end($matching));
    }

    /** @param list<string> $tagNames */
    private static function highestStableMajor(array $tagNames): int
    {
        $max = 0;
        foreach ($tagNames as $t) {
            if (preg_match('/^v(\d+)\.\d+\.\d+$/', $t, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $max;
    }

    /** @return list<mixed> */
    private static function decode(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read config file: {$path}");
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
