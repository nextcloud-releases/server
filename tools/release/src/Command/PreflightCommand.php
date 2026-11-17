<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Command;

use Nextcloud\ReleaseTools\GitHub\KnpGitHubApi;
use Nextcloud\ReleaseTools\MajorLifecycle;
use Nextcloud\ReleaseTools\Preflight;
use Nextcloud\ReleaseTools\ReleaseSchedule;
use Nextcloud\ReleaseTools\ScheduleGate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'release:preflight',
    description: 'Refuse a release before anything is tagged: version format, config, tags, version.php, schedule',
)]
final class PreflightCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('version', InputArgument::REQUIRED, 'Version to release, e.g. 35.0.4')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Directory holding stableN.json / master.json', '.')
            ->addOption('schedule', null, InputOption::VALUE_REQUIRED, 'release-schedule.json path', 'release-schedule.json')
            ->addOption('shadow', null, InputOption::VALUE_NONE, 'The legacy release script still tags: accept a leading v and existing tags')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Redo an existing release on a new commit, refused once updater_server production lists it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN');
        if ($token === false || $token === '') {
            throw new \RuntimeException('GH_TOKEN (or GITHUB_TOKEN) is required');
        }
        $api = KnpGitHubApi::withToken($token);

        $result = (new Preflight($api, new ScheduleGate($api, new MajorLifecycle($api)), (string) $input->getOption('root')))
            ->run(
                (string) $input->getArgument('version'),
                ReleaseSchedule::load((string) $input->getOption('schedule')),
                (bool) $input->getOption('shadow'),
                (bool) $input->getOption('force'),
            );

        foreach ($result->notes as $note) {
            $output->writeln("<comment>{$note}</comment>");
        }
        foreach ($result->problems as $problem) {
            $output->writeln("<error>{$problem}</error>");
        }

        // Downstream jobs read the resolved tag, branch and config from here.
        $githubOutput = getenv('GITHUB_OUTPUT');
        if ($githubOutput !== false && $githubOutput !== '' && $result->passed()) {
            file_put_contents($githubOutput, implode("\n", [
                "version={$result->version}",
                "tag={$result->tag}",
                "branch={$result->branch}",
                "config={$result->config}",
            ]) . "\n", FILE_APPEND);
        }

        if (!$result->passed()) {
            return Command::FAILURE;
        }
        $output->writeln("{$result->tag} from {$result->branch} ({$result->config}) can be released.");
        return Command::SUCCESS;
    }
}
