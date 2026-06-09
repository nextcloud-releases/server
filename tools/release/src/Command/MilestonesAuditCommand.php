<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Command;

use Nextcloud\ReleaseTools\GitHub\KnpGitHubApi;
use Nextcloud\ReleaseTools\MilestoneAuditor;
use Nextcloud\ReleaseTools\ReleaseConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'milestones:audit', description: 'Check milestone consistency for a major version')]
final class MilestonesAuditCommand extends Command
{
    private const SERVER_REPO = 'nextcloud-releases/server';

    protected function configure(): void
    {
        $this
            ->addArgument('config', InputArgument::REQUIRED, 'Config file (stableXX.json / master.json)')
            ->addArgument('tag-only', InputArgument::REQUIRED, 'tag-only.json path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = (string) $input->getArgument('config');
        $api = KnpGitHubApi::withToken(self::token());

        $serverTags = $api->listTagNames(self::SERVER_REPO);
        $major = ReleaseConfig::majorFromConfigBasename(basename($config, '.json'), $serverTags);
        $latest = ReleaseConfig::latestStable($major, $serverTags);
        if ($latest === null) {
            $output->writeln("No stable release found for major {$major}. Skipping audit.");
            return Command::SUCCESS;
        }

        $repos = ReleaseConfig::repos($config, (string) $input->getArgument('tag-only'));
        $warnings = (new MilestoneAuditor($api))->audit($latest, $repos);

        $output->writeln("=== Milestone audit for Nextcloud {$major} (latest stable {$latest->major}.{$latest->minor}.{$latest->patch}) ===");
        if ($warnings === []) {
            $output->writeln('OK - no issues found');
            return Command::SUCCESS;
        }
        foreach ($warnings as $w) {
            $output->writeln("::warning::{$w}");
        }
        $output->writeln(sprintf('%d issue(s) found', count($warnings)));
        return Command::FAILURE;
    }

    private static function token(): string
    {
        $token = getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN');
        if ($token === false || $token === '') {
            throw new \RuntimeException('GH_TOKEN (or GITHUB_TOKEN) is required');
        }
        return $token;
    }
}
