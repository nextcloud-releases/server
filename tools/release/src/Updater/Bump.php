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

        // The releases.json key to actually drop. Same as $oldKey for the
        // common case (a pre-release supersedes the previous RC; a patch
        // supersedes the previous stable), but null for stable_to_prerelease,
        // where the stable entry stays and the pre-release is merely added.
        $replaceKey = $oldKey;

        // A pre-release with no prior pre-release entry is one of two things:
        //  - if the major already shipped a stable release, it is a pre-release
        //    of an upcoming *patch* (e.g. 34.0.1 RC1 after 34.0.0): flip the
        //    beta channel from that stable entry to the pre-release, but keep
        //    the stable entry (stable installs still resolve against it);
        //  - otherwise it is the first pre-release of a brand-new major.
        // A patch/first-stable with no entry to replace is an error: proceeding
        // with empty "old" values would corrupt the feature files (the empty
        // version string matches the """ doc-string delimiters).
        $type = $plan->releaseType;
        if ($oldKey === null) {
            if ($type === ReleasePlan::TYPE_PRERELEASE) {
                $stableKey = ReleasesJson::findOldKey($releases, $plan->major, ReleasePlan::TYPE_PATCH);
                if ($stableKey !== null) {
                    $type = 'stable_to_prerelease';
                    $oldKey = $stableKey; // read old values from the stable entry
                    $replaceKey = null;   // but do not remove it
                } else {
                    $type = 'first_prerelease';
                }
            } else {
                throw new \RuntimeException(
                    "No existing entry found for major {$plan->major} (type={$type}) in releases.json",
                );
            }
        }

        // A stable patch usually goes out through one or more RCs first
        // (34.0.0 -> 34.0.1 RC1 -> 34.0.1 RC2 -> 34.0.1). When such an RC is
        // still in releases.json, promoting the patch must also retire it: flip
        // the beta channel off the pre-release and drop its entry. With no RC
        // in flight (a direct hotfix), this stays an ordinary patch.
        $base = "{$plan->major}.{$plan->minor}.{$plan->patch}";
        $rcKey = null;
        if ($type === ReleasePlan::TYPE_PATCH) {
            $rcKey = ReleasesJson::findPrereleaseForBase($releases, $base);
            if ($rcKey !== null) {
                $type = 'patch_promote_rc';
            }
        }

        // Old values (read before mutating). old* = the stable being replaced
        // on the stable channel; rc* = the pre-release being retired from the
        // beta channel (patch_promote_rc only).
        $oldInternal = $oldKey !== null ? (string) ($releases[$oldKey]['internalVersion'] ?? '') : '';
        $oldZipSig = '';
        if ($oldKey !== null) {
            $oldZipSig = (string) ($releases[$oldKey]['signatures']['zip'] ?? $releases[$oldKey]['signature'] ?? '');
        }
        $oldVersionString = $oldKey ?? '';
        $oldUrlVersion = $oldKey !== null ? strtolower(str_replace(' ', '', $oldKey)) : '';

        $rcInternal = '';
        $rcZipSig = '';
        $rcVersionString = '';
        $rcUrlVersion = '';
        if ($rcKey !== null) {
            $rcInternal = (string) ($releases[$rcKey]['internalVersion'] ?? '');
            $rcZipSig = (string) ($releases[$rcKey]['signatures']['zip'] ?? $releases[$rcKey]['signature'] ?? '');
            $rcVersionString = $rcKey;
            $rcUrlVersion = strtolower(str_replace(' ', '', $rcKey));
        }

        // Update releases.json: replace the old stable, and drop the promoted RC.
        $entry = ReleasesJson::newEntry($internalVersion, $bz2Sig, $zipSig, $plan->deploy);
        $releases = ReleasesJson::apply($releases, $replaceKey, $plan->versionString, $entry);
        if ($rcKey !== null) {
            unset($releases[$rcKey]);
        }
        $this->writeJson($releasesPath, ReleasesJson::encode($releases));

        // Update major_versions.json for a brand-new major.
        $majorsPath = "{$this->dir}/config/major_versions.json";
        $majors = $this->readJson($majorsPath);
        if ($type === 'first_prerelease') {
            $majors = MajorVersions::ensureMajor($majors, $plan->major, $minPhp);
            $this->writeJson($majorsPath, MajorVersions::encode($majors));
        }

        // Cross-major facts for appended scenarios. The first beta of a new
        // major appends a beta scenario "Updating latest {prevMajor} to {major}":
        // its received version is the latest {prevMajor} an install on the beta
        // channel can be on, i.e. the prev major's in-flight pre-release when one
        // exists (released earlier in the same batch), otherwise its latest
        // stable. Every other shape appends stable scenarios and wants the latest
        // stable only.
        $prevMajor = $plan->major - 1;
        $prevStableKey = $type === 'first_prerelease'
            ? ReleasesJson::findLatestForMajor($releases, $prevMajor)
            : ReleasesJson::findOldKey($releases, $prevMajor, ReleasePlan::TYPE_PATCH);
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
            deploy: $plan->deploy,
            rcUrlVersion: $rcUrlVersion,
            rcVersionString: $rcVersionString,
            rcInternal: $rcInternal,
            rcZipSig: $rcZipSig,
        );

        $dir = "{$this->dir}/tests/integration/features";
        $files = [
            'stable' => $this->read("{$dir}/stable.feature"),
            'beta' => $this->read("{$dir}/beta.feature"),
            'latest' => $this->read("{$dir}/latest.feature"),
            'daily' => $this->read("{$dir}/daily.feature"),
        ];
        $files = FeatureFiles::apply($type, $files, $inputs);
        $this->write("{$dir}/stable.feature", $files['stable']);
        $this->write("{$dir}/beta.feature", $files['beta']);
        $this->write("{$dir}/latest.feature", $files['latest']);
        $this->write("{$dir}/daily.feature", $files['daily']);

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
