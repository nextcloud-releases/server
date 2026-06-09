<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Updater;

/**
 * Rewrites the updater server's Behat feature files for a release. Pure: takes
 * the three file contents (stable/beta/latest) and returns the updated ones.
 * Ports the four update_features_* functions of update-updater-server.sh.
 *
 * @phpstan-type Files array{stable: string, beta: string, latest: string}
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
            'prerelease' => self::prerelease($files, $in),
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
        return $files;
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
        return $files;
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
