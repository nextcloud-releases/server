<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools;

use Nextcloud\ReleaseTools\GitHub\GitHubApi;

/**
 * Whether servers have been offered a version: the production branch of
 * updater_server is the only place that decides it. A GitHub release or files
 * on the download server can still be withdrawn; once production lists a
 * version, or a later one of the same major that replaced it, they cannot.
 */
final class Deployment
{
    public const REPO = 'nextcloud-releases/updater_server';
    public const BRANCH = 'production';
    public const FILE = 'config/releases.json';

    public function __construct(
        private readonly GitHubApi $api,
    ) {
    }

    /**
     * The production entry that rules $version out, or null when servers have
     * not been offered it.
     *
     * @throws \RuntimeException when production cannot be read: an unknown
     *                           state must never pass for "not deployed"
     */
    public function blockingEntry(string $version): ?string
    {
        $raw = $this->api->fileContent(self::REPO, self::FILE, self::BRANCH);
        $releases = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($releases)) {
            throw new \RuntimeException('Cannot read ' . self::FILE . ' on ' . self::REPO . '@' . self::BRANCH);
        }

        $major = (int) $version;
        foreach (array_keys($releases) as $listed) {
            $listed = (string) $listed;
            if ((int) $listed === $major && version_compare($listed, $version, '>=')) {
                return $listed;
            }
        }
        return null;
    }
}
