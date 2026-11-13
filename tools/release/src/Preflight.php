<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

use Nextcloud\ReleaseTools\GitHub\GitHubApi;

/**
 * Everything a release can be refused for before anything is tagged or
 * built, so a bad dispatch stops before anyone is asked to approve it.
 *
 * The input is the bare version ("35.0.4"). While the legacy release script
 * still dispatches the pipeline it sends "v35.0.4" and has already pushed the
 * tags, so a leading "v" and existing tags are only rejected once the
 * pipeline does the tagging itself.
 *
 * With $force an existing tag is expected (the release is being redone on a
 * new commit), but only as long as updater_server production has not offered
 * the version to servers.
 */
final class Preflight
{
    public const SERVER = 'nextcloud/server';
    public const RELEASES = 'nextcloud-releases/server';

    private const FORMAT = '/^(\d+)\.(\d+)\.(\d+)((alpha|beta|rc)\d+)?$/';

    public function __construct(
        private readonly GitHubApi $api,
        private readonly ScheduleGate $schedule,
        private readonly string $root,
    ) {
    }

    public function run(string $input, ReleaseSchedule $schedule, bool $shadow, bool $force = false): PreflightResult
    {
        $problems = [];
        $notes = [];

        $version = $input;
        if (str_starts_with($input, 'v')) {
            $version = substr($input, 1);
            if ($shadow) {
                $notes[] = "Got '{$input}': pass the version without 'v' ('{$version}').";
            } else {
                $problems[] = "Pass the version without 'v': '{$version}', not '{$input}'.";
            }
        }
        if (preg_match(self::FORMAT, $version) !== 1) {
            return new PreflightResult($version, '', '', '', [
                ...$problems,
                "'{$input}' is not a release version (expected MAJOR.MINOR.PATCH, optionally followed by alphaN, betaN or rcN).",
            ], $notes);
        }

        $tag = "v{$version}";
        $parsed = Version::fromTag($tag);
        $branch = preg_match('/^\d+\.0\.0(alpha|beta)/', $version) === 1 ? 'master' : "stable{$parsed->major}";
        $config = $branch === 'master' ? 'master.json' : "{$branch}.json";

        if (!is_file("{$this->root}/{$config}")) {
            $problems[] = "{$config} is missing: add it with the apps bundled in {$parsed->major}.";
        }

        $tagged = [];
        foreach ([self::SERVER, self::RELEASES] as $repo) {
            if ($this->api->tagSha($repo, $tag) !== null) {
                $tagged[] = $repo;
            }
        }
        if ($tagged !== [] && !$shadow && !$force) {
            $problems[] = "{$tag} already exists in " . implode(' and ', $tagged) . '. Use force to redo it on a new commit.';
        }
        if ($force) {
            try {
                $listed = (new Deployment($this->api))->blockingEntry($version);
                if ($listed !== null) {
                    $problems[] = "updater_server production already lists {$listed}, so servers have been offered {$version}: release a new version instead of forcing this one.";
                }
            } catch (\RuntimeException $e) {
                $problems[] = $e->getMessage() . ': refusing to force without knowing whether servers have been offered this version.';
            }
        }

        // The tag is the exact commit when it exists, the branch head otherwise
        // or when the release is being redone.
        $ref = in_array(self::SERVER, $tagged, true) && !$force ? $tag : $branch;
        $versionPhp = $this->api->fileContent(self::SERVER, 'version.php', $ref);
        $stated = $versionPhp !== null ? self::versionString($versionPhp) : null;
        if ($stated === null) {
            $problems[] = "Could not read \$OC_VersionString from nextcloud/server version.php at {$ref}.";
        } elseif (self::normalize($stated) !== $version) {
            $problems[] = "version.php at {$ref} says '{$stated}', not {$version}. Is the version bump merged?";
        }

        foreach ($this->schedule->problems($parsed, $schedule) as $problem) {
            $problems[] = $problem;
        }

        return new PreflightResult($version, $tag, $branch, $config, $problems, $notes);
    }

    /** "35.0.0 beta 3" and "35.0.3 RC2" become "35.0.0beta3" and "35.0.3rc2". */
    public static function normalize(string $versionString): string
    {
        return strtolower((string) preg_replace('/\s+/', '', $versionString));
    }

    private static function versionString(string $php): ?string
    {
        return preg_match('/\$OC_VersionString\s*=\s*[\'"]([^\'"]+)[\'"]/', $php, $m) === 1 ? $m[1] : null;
    }
}
