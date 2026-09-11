<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Command;

use Nextcloud\ReleaseTools\GitHub\KnpGitHubApi;
use Nextcloud\ReleaseTools\MajorLifecycle;
use Nextcloud\ReleaseTools\ReleaseSchedule;
use Nextcloud\ReleaseTools\ScheduleRefresh;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'schedule:extend',
    description: 'Keep the next two patch milestones dated for every maintained major',
)]
final class ScheduleExtendCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('schedule', InputArgument::REQUIRED, 'release-schedule.json path')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Rewrite the schedule file (default: report only)')
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'How many future milestones to keep dated', '2')
            ->addOption('as-of', null, InputOption::VALUE_REQUIRED, 'Judge maintenance windows against this date (YYYY-MM-DD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('schedule');
        $current = ReleaseSchedule::load($path)->toArray();

        $api = KnpGitHubApi::withToken(self::token());
        $refresh = new ScheduleRefresh($api, new MajorLifecycle($api));
        $updated = $refresh->apply(
            $current,
            max(1, (int) $input->getOption('depth')),
            self::optional($input, 'as-of'),
        );

        $output->writeln('Maintained majors:');
        foreach ($refresh->log as $line) {
            $output->writeln($line);
        }

        if ($updated === $current) {
            $output->writeln('Schedule is already up to date.');
            return Command::SUCCESS;
        }
        if (!$input->getOption('write')) {
            $output->writeln('Report only, pass --write to apply.');
            return Command::SUCCESS;
        }

        self::save($path, $updated);
        $output->writeln("Wrote {$path}.");
        return Command::SUCCESS;
    }

    /** @param array<string, string> $schedule */
    private static function save(string $path, array $schedule): void
    {
        // FORCE_OBJECT so an emptied schedule stays "{}" and not "[]": the file
        // is a title => date map, and PHP encodes an empty array as a list.
        $json = json_encode(
            $schedule,
            JSON_PRETTY_PRINT | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException("Cannot write {$path}");
        }
    }

    /** A VALUE_REQUIRED option as a non-empty string, or null when absent. */
    private static function optional(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        return $value !== null && $value !== '' ? (string) $value : null;
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
