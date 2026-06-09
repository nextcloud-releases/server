<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Updater;

/**
 * Applies a release to a checked-out updater_server working copy: rewrites
 * config/releases.json, config/major_versions.json (new majors) and the Behat
 * feature files. The git clone, `make config/config.php`, commit and PR stay in
 * the workflow; this is the pure-logic core that was the body of
 * update-updater-server.sh.
 */
final class Bump
{
    public function __construct(
        private readonly string $dir,
    ) {
    }

    public function run(
        string $tag,
        string $bz2Sig,
        string $zipSig,
        string $internalVersion,
        ?int $deploy = null,
        string $minPhp = '8.1',
    ): ReleasePlan {
        $plan = ReleasePlan::fromTag($tag, $deploy);

        $releasesPath = "{$this->dir}/config/releases.json";
        $releases = $this->readJson($releasesPath);

        $oldKey = ReleasesJson::findOldKey($releases, $plan->major, $plan->releaseType);

        // A pre-release with no prior entry is the first pre-release of a new major.
        // A patch/first-stable with no entry to replace is an error: proceeding
        // with empty "old" values would corrupt the feature files (the empty
        // version string matches the """ doc-string delimiters).
        $type = $plan->releaseType;
        if ($oldKey === null) {
            if ($type === ReleasePlan::TYPE_PRERELEASE) {
                $type = 'first_prerelease';
            } else {
                throw new \RuntimeException(
                    "No existing entry found for major {$plan->major} (type={$type}) in releases.json",
                );
            }
        }

        // Old values (read before mutating).
        $oldInternal = $oldKey !== null ? (string) ($releases[$oldKey]['internalVersion'] ?? '') : '';
        $oldZipSig = '';
        if ($oldKey !== null) {
            $oldZipSig = (string) ($releases[$oldKey]['signatures']['zip'] ?? $releases[$oldKey]['signature'] ?? '');
        }
        $oldVersionString = $oldKey ?? '';
        $oldUrlVersion = $oldKey !== null ? strtolower(str_replace(' ', '', $oldKey)) : '';

        // Update releases.json.
        $entry = ReleasesJson::newEntry($internalVersion, $bz2Sig, $zipSig, $plan->deploy);
        $releases = ReleasesJson::apply($releases, $oldKey, $plan->versionString, $entry);
        $this->writeJson($releasesPath, ReleasesJson::encode($releases));

        // Update major_versions.json for a brand-new major.
        $majorsPath = "{$this->dir}/config/major_versions.json";
        $majors = $this->readJson($majorsPath);
        if ($type === 'first_prerelease') {
            $majors = MajorVersions::ensureMajor($majors, $plan->major, $minPhp);
            $this->writeJson($majorsPath, MajorVersions::encode($majors));
        }

        // Cross-major facts for appended scenarios.
        $prevMajor = $plan->major - 1;
        $prevStableKey = ReleasesJson::findOldKey($releases, $prevMajor, ReleasePlan::TYPE_PATCH);
        // Matches `jq -r` on a missing key: a literal "null" (used verbatim in the
        // appended scenario when the previous major has no stable release yet).
        $prevStableInternal = 'null';
        if ($prevStableKey !== null && isset($releases[$prevStableKey]['internalVersion'])) {
            $prevStableInternal = (string) $releases[$prevStableKey]['internalVersion'];
        }
        $eolDate = (string) ($majors[(string) $plan->major]['eol'] ?? '');
        $thisMinPhp = (string) ($majors[(string) $plan->major]['minPHP'] ?? '8.1');

        $inputs = new FeatureInputs(
            major: $plan->major,
            urlVersion: $plan->urlVersion,
            versionString: $plan->versionString,
            internalVersion: $internalVersion,
            zipSig: $zipSig,
            urlDir: $plan->urlDir,
            oldUrlVersion: $oldUrlVersion,
            oldVersionString: $oldVersionString,
            oldInternal: $oldInternal,
            oldZipSig: $oldZipSig,
            prevMajor: $prevMajor,
            prevStableInternal: $prevStableInternal,
            phpVersion: "{$thisMinPhp}.0",
            eolDate: $eolDate,
        );

        $dir = "{$this->dir}/tests/integration/features";
        $files = [
            'stable' => $this->read("{$dir}/stable.feature"),
            'beta' => $this->read("{$dir}/beta.feature"),
            'latest' => $this->read("{$dir}/latest.feature"),
        ];
        $files = FeatureFiles::apply($type, $files, $inputs);
        $this->write("{$dir}/stable.feature", $files['stable']);
        $this->write("{$dir}/beta.feature", $files['beta']);
        $this->write("{$dir}/latest.feature", $files['latest']);

        return $plan;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $data = json_decode($this->read($path), true);
        return is_array($data) ? $data : [];
    }

    private function writeJson(string $path, string $contents): void
    {
        $this->write($path, $contents);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read {$path}");
        }
        return $contents;
    }

    private function write(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
    }
}
