<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Command;

use Nextcloud\ReleaseTools\DueDate;
use Nextcloud\ReleaseTools\GitHub\KnpGitHubApi;
use Nextcloud\ReleaseTools\MilestoneUpdater;
use Nextcloud\ReleaseTools\ReleaseConfig;
use Nextcloud\ReleaseTools\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'milestones:update', description: 'Close/create milestones and move issues for a release')]
final class MilestonesUpdateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('tag', InputArgument::REQUIRED, 'Release tag, e.g. v34.0.4 or v35.0.0beta1')
            ->addArgument('config', InputArgument::REQUIRED, 'Config file (stableXX.json / master.json)')
            ->addArgument('tag-only', InputArgument::REQUIRED, 'tag-only.json path')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without changing anything')
            ->addOption('next-due', null, InputOption::VALUE_REQUIRED, 'Due date (YYYY-MM-DD) for the next patch milestone')
            ->addOption('upcoming-due', null, InputOption::VALUE_REQUIRED, 'Due date (YYYY-MM-DD) for the upcoming patch milestone');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = Version::fromTag((string) $input->getArgument('tag'));
        $repos = ReleaseConfig::repos((string) $input->getArgument('config'), (string) $input->getArgument('tag-only'));

        $nextDue = $input->getOption('next-due');
        $upcomingDue = $input->getOption('upcoming-due');
        $nextDueOn = $nextDue !== null ? DueDate::toIso((string) $nextDue) : null;
        $upcomingDueOn = $upcomingDue !== null ? DueDate::toIso((string) $upcomingDue) : null;

        $dryRun = (bool) $input->getOption('dry-run');
        $api = KnpGitHubApi::withToken(self::token());
        $updater = new MilestoneUpdater($api, $dryRun);
        $updater->run($version, $repos, $nextDueOn, $upcomingDueOn);

        foreach ($updater->log as $line) {
            $output->writeln($line);
        }
        $output->writeln(sprintf(
            '%s closed=%d created=%d issues-moved=%d',
            $dryRun ? '[dry-run]' : 'Done:',
            $updater->closed,
            $updater->created,
            $updater->moved,
        ));
        return Command::SUCCESS;
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
