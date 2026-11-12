<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Command;

use Nextcloud\ReleaseTools\GitHub\KnpGitHubApi;
use Nextcloud\ReleaseTools\MajorLifecycle;
use Nextcloud\ReleaseTools\ReleaseSchedule;
use Nextcloud\ReleaseTools\ScheduleGate;
use Nextcloud\ReleaseTools\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'schedule:check',
    description: 'Fail a stable release whose release schedule is not ready, before anything is tagged',
)]
final class ScheduleCheckCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('tag', InputArgument::REQUIRED, 'Release tag, e.g. v34.0.4')
            ->addArgument('schedule', InputArgument::REQUIRED, 'release-schedule.json path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tag = (string) $input->getArgument('tag');
        $version = Version::fromTag($tag);
        $schedule = ReleaseSchedule::load((string) $input->getArgument('schedule'));

        $token = getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN');
        if ($token === false || $token === '') {
            throw new \RuntimeException('GH_TOKEN (or GITHUB_TOKEN) is required');
        }
        $api = KnpGitHubApi::withToken($token);

        $problems = (new ScheduleGate($api, new MajorLifecycle($api)))->problems($version, $schedule);
        if ($problems === []) {
            $output->writeln("Release schedule is ready for {$tag}.");
            return Command::SUCCESS;
        }

        foreach ($problems as $problem) {
            $output->writeln("<error>{$problem}</error>");
        }
        return Command::FAILURE;
    }
}
