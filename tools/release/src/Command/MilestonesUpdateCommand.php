<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Command;

use Nextcloud\ReleaseTools\GitHub\KnpGitHubApi;
use Nextcloud\ReleaseTools\MajorLifecycle;
use Nextcloud\ReleaseTools\MilestoneUpdater;
use Nextcloud\ReleaseTools\ReleaseConfig;
use Nextcloud\ReleaseTools\ReleaseSchedule;
use Nextcloud\ReleaseTools\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'milestones:update', description: 'Close/create milestones and move open issues and pull requests for a release')]
final class MilestonesUpdateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('tag', InputArgument::REQUIRED, 'Release tag, e.g. v34.0.4 or v35.0.0beta1')
            ->addArgument('config', InputArgument::REQUIRED, 'Config file (stableXX.json / master.json)')
            ->addArgument('tag-only', InputArgument::REQUIRED, 'tag-only.json path')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without changing anything')
            ->addOption('schedule', null, InputOption::VALUE_REQUIRED, 'release-schedule.json with milestone due dates')
            ->addOption('next-due', null, InputOption::VALUE_REQUIRED, 'Override the next patch milestone due date (YYYY-MM-DD)')
            ->addOption('upcoming-due', null, InputOption::VALUE_REQUIRED, 'Override the upcoming patch milestone due date (YYYY-MM-DD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = Version::fromTag((string) $input->getArgument('tag'));
        $repos = ReleaseConfig::repos((string) $input->getArgument('config'), (string) $input->getArgument('tag-only'));

        $schedule = ReleaseSchedule::load(self::optional($input, 'schedule'));
        $nextOverride = self::optional($input, 'next-due');
        $upcomingOverride = self::optional($input, 'upcoming-due');
        $api = KnpGitHubApi::withToken(self::token());

        // A stable release whose next patch milestone has no date either ended
        // the series or ran against a stale schedule, and the two look the same
        // from the schedule alone. Only an EOL major may carry on without one;
        // the EOL lookup costs an API read and is skipped whenever a date is
        // there, so an explicit schedule entry always wins.
        $seriesFinal = !$schedule->hasNext($version, $nextOverride)
            && (new MajorLifecycle($api))->isFinalRelease($version);

        // Resolve due dates before touching GitHub: a stable release with no
        // scheduled (or overridden) date fails here, leaving nothing half-done.
        $due = $seriesFinal
            ? ['next' => null, 'upcoming' => null]
            : $schedule->resolve($version, $nextOverride, $upcomingOverride);

        $dryRun = (bool) $input->getOption('dry-run');
        $updater = new MilestoneUpdater($api, $dryRun);
        $updater->run($version, $repos, $due['next'], $due['upcoming'], $seriesFinal);

        foreach ($updater->log as $line) {
            $output->writeln($line);
        }
        $output->writeln(sprintf(
            '%s closed=%d created=%d moved=%d',
            $dryRun ? '[dry-run]' : 'Done:',
            $updater->closed,
            $updater->created,
            $updater->moved,
        ));
        return Command::SUCCESS;
    }

    /** A VALUE_REQUIRED option as a non-empty string, or null when absent. */
    private static function optional(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        return $value !== null ? (string) $value : null;
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
