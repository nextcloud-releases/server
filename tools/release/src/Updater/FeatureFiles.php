<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Updater;

/**
 * Rewrites the updater server's Behat feature files for a release. Pure: takes
 * the four file contents (stable/beta/latest/daily) and returns the updated
 * ones. Ports the update_features_* functions of update-updater-server.sh, plus
 * the staged-rollout and daily-channel upkeep the bash script never did.
 *
 * @phpstan-type Files array{stable: string, beta: string, latest: string, daily: string}
 */
final class FeatureFiles
{
    /**
     * @param Files $files
     * @return Files
     */
    public static function apply(string $releaseType, array $files, FeatureInputs $in): array
    {
        return match ($releaseType) {
            'patch' => self::patch($files, $in),
            'patch_promote_rc' => self::patchPromoteRc($files, $in),
            'prerelease' => self::prerelease($files, $in),
            'stable_to_prerelease' => self::stableToPrerelease($files, $in),
            'first_stable' => self::firstStable($files, $in),
            'first_prerelease' => self::firstPrerelease($files, $in),
            default => $files,
        };
    }

    /** Patch release: bump version/URL/internal/signature in both channels. */
    private static function patch(array $files, FeatureInputs $in): array
    {
        foreach (['stable', 'beta'] as $ch) {
            $files[$ch] = strtr($files[$ch], [
                "nextcloud-{$in->oldUrlVersion}." => "nextcloud-{$in->urlVersion}.",
                "/v{$in->oldUrlVersion}/" => "/v{$in->urlVersion}/",
                $in->oldInternal => $in->internalVersion,
            ]);
            $files[$ch] = Signature::replace($files[$ch], $in->oldZipSig, $in->zipSig);
        }
        $files['latest'] = strtr($files['latest'], [
            "\"{$in->oldVersionString}\"" => "\"{$in->versionString}\"",
            "nextcloud-{$in->oldUrlVersion}." => "nextcloud-{$in->urlVersion}.",
        ]);
        // A staged patch (deploy < 100) keeps the "(staged rollout)" scenario's
        // mtime above the rollout cutoff so the install stays excluded.
        if ($in->deploy < 100) {
            $files['stable'] = self::setStagedRolloutMtime($files['stable'], $in->deploy);
        }
        return $files;
    }

    /**
     * Set the mtime of every "(staged rollout)" scenario so its last two digits
     * exceed the rollout percentage (deploy + 1) - an install with that mtime is
     * excluded from the rollout (Response.php: included iff last-2-digits <= deploy).
     */
    private static function setStagedRolloutMtime(string $text, int $deploy): string
    {
        $mtime = $deploy + 1;
        $lines = explode("\n", $text);
        $inStaged = false;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'Scenario:')) {
                $inStaged = str_contains($line, '(staged rollout)');
            }
            if ($inStaged && str_contains($line, 'installation mtime is "')) {
                $lines[$i] = preg_replace('/installation mtime is "\d+"/', "installation mtime is \"{$mtime}\"", $line);
                $inStaged = false; // only the first mtime line of the scenario
            }
        }
        return implode("\n", $lines);
    }

    /** RC/beta bump: update the beta channel only. */
    private static function prerelease(array $files, FeatureInputs $in): array
    {
        $files['beta'] = strtr($files['beta'], [
            "nextcloud-{$in->oldUrlVersion}." => "nextcloud-{$in->urlVersion}.",
            "/v{$in->oldUrlVersion}/" => "/v{$in->urlVersion}/",
            $in->oldInternal => $in->internalVersion,
            $in->oldVersionString => $in->versionString,
        ]);
        $files['beta'] = Signature::replace($files['beta'], $in->oldZipSig, $in->zipSig);
        $files['latest'] = strtr($files['latest'], [
            $in->oldVersionString => $in->versionString,
            "nextcloud-{$in->oldUrlVersion}." => "nextcloud-{$in->urlVersion}.",
        ]);
        return $files;
    }

    /**
     * Stable patch that promotes an in-flight RC (e.g. 34.0.1 RC2 -> 34.0.1):
     * the stable channel moves off the previous stable (handled by patch()),
     * and the beta channel - currently on the RC - is flipped to the same
     * stable, swapping prereleases/ -> releases/. The exact inverse of
     * stableToPrerelease, on the beta side.
     */
    private static function patchPromoteRc(array $files, FeatureInputs $in): array
    {
        // Stable channel + latest stable section: previous stable -> this patch.
        $files = self::patch($files, $in);

        // Beta channel: retire the RC, pointing it at the new stable.
        $files['beta'] = strtr($files['beta'], [
            "prereleases/nextcloud-{$in->rcUrlVersion}." => "releases/nextcloud-{$in->urlVersion}.",
            "/v{$in->rcUrlVersion}/nextcloud-{$in->rcUrlVersion}." => "/v{$in->urlVersion}/nextcloud-{$in->urlVersion}.",
            "/v{$in->rcUrlVersion}/" => "/v{$in->urlVersion}/",
            $in->rcInternal => $in->internalVersion,
            "\"{$in->rcVersionString}\"" => "\"{$in->versionString}\"",
        ]);
        $files['beta'] = Signature::replace($files['beta'], $in->rcZipSig, $in->zipSig);

        // latest beta section: RC -> this stable.
        $files['latest'] = self::replaceInSection(
            $files['latest'],
            'I want to know the latest beta',
            'URL to download',
            [
                "\"{$in->rcVersionString}\"" => "\"{$in->versionString}\"",
                "prereleases/nextcloud-{$in->rcUrlVersion}.zip" => "releases/nextcloud-{$in->urlVersion}.zip",
            ],
        );
        return $files;
    }

    /**
     * Pre-release of a patch line (e.g. 34.0.1 RC1 after 34.0.0 stable): flip
     * the beta channel from the current stable release to the new pre-release,
     * swapping the download dir releases/ -> prereleases/. The exact inverse of
     * firstStable, minus the appended scenarios and the stable-section rewrite -
     * the major already has its beta scenarios, so we only retarget them.
     */
    private static function stableToPrerelease(array $files, FeatureInputs $in): array
    {
        $files['beta'] = strtr($files['beta'], [
            "releases/nextcloud-{$in->oldUrlVersion}." => "prereleases/nextcloud-{$in->urlVersion}.",
            "/v{$in->oldUrlVersion}/nextcloud-{$in->oldUrlVersion}." => "/v{$in->urlVersion}/nextcloud-{$in->urlVersion}.",
            "/v{$in->oldUrlVersion}/" => "/v{$in->urlVersion}/",
            $in->oldInternal => $in->internalVersion,
            "\"{$in->oldVersionString}\"" => "\"{$in->versionString}\"",
        ]);
        $files['beta'] = Signature::replace($files['beta'], $in->oldZipSig, $in->zipSig);

        $files['latest'] = self::replaceInSection(
            $files['latest'],
            'I want to know the latest beta',
            'URL to download',
            [
                "\"{$in->oldVersionString}\"" => "\"{$in->versionString}\"",
                "releases/nextcloud-{$in->oldUrlVersion}.zip" => "prereleases/nextcloud-{$in->urlVersion}.zip",
            ],
        );
        return $files;
    }

    /** First stable of a new major: convert RC -> stable in beta, add stable scenarios. */
    private static function firstStable(array $files, FeatureInputs $in): array
    {
        $files['beta'] = strtr($files['beta'], [
            "prereleases/nextcloud-{$in->oldUrlVersion}." => "releases/nextcloud-{$in->urlVersion}.",
            "/v{$in->oldUrlVersion}/nextcloud-{$in->oldUrlVersion}." => "/v{$in->urlVersion}/nextcloud-{$in->urlVersion}.",
            "/v{$in->oldUrlVersion}/" => "/v{$in->urlVersion}/",
            $in->oldInternal => $in->internalVersion,
            "\"{$in->oldVersionString}\"" => "\"{$in->versionString}\"",
        ]);
        $files['beta'] = Signature::replace($files['beta'], $in->oldZipSig, $in->zipSig);

        $files['stable'] .= self::appendedScenarios('stable', 'releases', $in->eolLine(), $in);

        // latest.feature: stable section -> this release; beta section -> this release.
        $currentStable = self::versionInSection($files['latest'], 'latest stable release');
        if ($currentStable !== null) {
            $files['latest'] = self::replaceInSection(
                $files['latest'],
                'I want to know the latest stable',
                'URL to download',
                [
                    "Version \"{$currentStable}\"" => "Version \"{$in->versionString}\"",
                    'nextcloud-' . self::urlOf($currentStable) . '.zip' => "nextcloud-{$in->urlVersion}.zip",
                ],
            );
        }
        $files['latest'] = self::replaceInSection(
            $files['latest'],
            'I want to know the latest beta',
            'URL to download',
            [
                "\"{$in->oldVersionString}\"" => "\"{$in->versionString}\"",
                "prereleases/nextcloud-{$in->oldUrlVersion}.zip" => "releases/nextcloud-{$in->urlVersion}.zip",
            ],
        );
        return $files;
    }

    /** First pre-release of a new major: add beta scenarios, point latest beta at it. */
    private static function firstPrerelease(array $files, FeatureInputs $in): array
    {
        $files['beta'] .= self::appendedScenarios('beta', $in->urlDir, 'And EOL is set to "0"', $in);

        $currentBeta = self::versionInSection($files['latest'], 'latest beta release');
        if ($currentBeta !== null) {
            $files['latest'] = self::replaceInSection(
                $files['latest'],
                'I want to know the latest beta',
                'URL to download',
                [
                    "Version \"{$currentBeta}\"" => "Version \"{$in->versionString}\"",
                    'nextcloud-' . self::urlOf($currentBeta) . '.zip' => "nextcloud-{$in->urlVersion}.zip",
                ],
            );
            $files['latest'] = self::replaceInSection(
                $files['latest'],
                'I want to know the latest beta',
                'URL to download',
                ["server/releases/nextcloud-{$in->urlVersion}" => "server/{$in->urlDir}/nextcloud-{$in->urlVersion}"],
            );
        }
        $files['daily'] = self::firstPrereleaseDaily($files['daily'], $in);
        return $files;
    }

    /**
     * Daily channel upkeep when a new major is born: the former "master" daily
     * (the previous major) becomes a stable daily, and a fresh master daily
     * scenario for the new major is prepended. "master" daily points at
     * latest-master.zip / docs/latest; a stable daily points at
     * latest-stable{major}.zip / docs/{major}.
     */
    private static function firstPrereleaseDaily(string $daily, FeatureInputs $in): string
    {
        // Demote the previous major's master daily to a stable daily.
        $daily = strtr($daily, [
            'daily/latest-master.zip' => "daily/latest-stable{$in->prevMajor}.zip",
            '/server/latest/admin_manual' => "/server/{$in->prevMajor}/admin_manual",
        ]);
        // Prepend the new major's master daily scenario.
        $scenario = "  Scenario: Updating an outdated Nextcloud {$in->major} daily\n"
            . "    Given There is a release with channel \"daily\"\n"
            . "    And The received version is \"{$in->major}.1.0\"\n"
            . "    And the received build is \"2012-10-19T18:44:30+00:00\"\n"
            . "    When The request is sent\n"
            . "    Then The response is non-empty\n"
            . "    And Update to version \"100.0.0.0\" is available\n"
            . "    And URL to download is \"https://download.nextcloud.com/server/daily/latest-master.zip\"\n"
            . "    And URL to documentation is \"https://docs.nextcloud.com/server/latest/admin_manual/maintenance/upgrade.html\"\n"
            . "    And EOL date is set to \"{$in->eolDate}\"\n"
            . "    And No signature is set\n";
        return preg_replace('/^  Scenario:/m', $scenario . "\n  Scenario:", $daily, 1) ?? $daily;
    }

    /** The two scenarios appended to a channel's feature file. */
    private static function appendedScenarios(string $channel, string $dir, string $eolLine, FeatureInputs $in): string
    {
        $crossMajor = self::scenario(
            "Updating Nextcloud latest {$in->prevMajor} to {$in->major} on the {$channel} channel",
            $channel,
            $in->prevStableInternal,
            $dir,
            $eolLine,
            $in,
        );
        $sameMajor = self::scenario(
            "Updating Nextcloud {$in->major} on the {$channel} channel",
            $channel,
            "{$in->major}.0.0.0",
            $dir,
            $eolLine,
            $in,
        );
        return "\n{$crossMajor}\n\n{$sameMajor}\n";
    }

    private static function scenario(string $title, string $channel, string $received, string $dir, string $eolLine, FeatureInputs $in): string
    {
        $u = $in->urlVersion;
        $download = "https://download.nextcloud.com/server/{$dir}/nextcloud-{$u}";
        $github = "https://github.com/nextcloud-releases/server/releases/download/v{$u}/nextcloud-{$u}";
        return "  Scenario: {$title}\n"
            . "    Given There is a release with channel \"{$channel}\"\n"
            . "    And The received version is \"{$received}\"\n"
            . "    And The received PHP version is \"{$in->phpVersion}\"\n"
            . "    And the installation mtime is \"11\"\n"
            . "    When The request is sent\n"
            . "    Then The response is non-empty\n"
            . "    And Update to version \"{$in->internalVersion}\" is available\n"
            . "    And URL to download is \"{$download}.zip\"\n"
            . "    And Download URLS contain \"{$download}.zip\"\n"
            . "    And Download URLS contain \"{$download}.tar.bz2\"\n"
            . "    And Download URLS contain \"{$github}.zip\"\n"
            . "    And Download URLS contain \"{$github}.tar.bz2\"\n"
            . "    And URL to documentation is \"https://docs.nextcloud.com/server/{$in->major}/admin_manual/maintenance/upgrade.html\"\n"
            . "    {$eolLine}\n"
            . "    And The signature is\n"
            . "    \"\"\"\n"
            . Signature::block($in->zipSig) . "\n"
            . "    \"\"\"";
    }

    /** "33.0.5" / "34.0.0 RC5" -> the URL form "33.0.5" / "34.0.0rc5". */
    private static function urlOf(string $versionString): string
    {
        return strtolower(str_replace(' ', '', $versionString));
    }

    /** First `Version "X"` value within the scenario named by $sectionNeedle. */
    private static function versionInSection(string $text, string $sectionNeedle): ?string
    {
        $lines = explode("\n", $text);
        $in = false;
        foreach ($lines as $line) {
            if (str_contains($line, $sectionNeedle)) {
                $in = true;
            }
            if ($in && preg_match('/Version "([^"]+)"/', $line, $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * Apply $replacements only between the first line containing $start and the
     * next line (at or after it) containing $end - the PHP form of sed's
     * `/start/,/end/ { s|..|..| }` range substitution.
     *
     * @param array<string, string> $replacements
     */
    private static function replaceInSection(string $text, string $start, string $end, array $replacements): string
    {
        $lines = explode("\n", $text);
        $startIdx = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, $start)) {
                $startIdx = $i;
                break;
            }
        }
        if ($startIdx === null) {
            return $text;
        }
        for ($i = $startIdx; $i < count($lines); $i++) {
            $lines[$i] = strtr($lines[$i], $replacements);
            if ($i > $startIdx && str_contains($lines[$i], $end)) {
                break;
            }
        }
        return implode("\n", $lines);
    }
}
