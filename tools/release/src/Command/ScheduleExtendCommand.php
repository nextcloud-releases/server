<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Command;

use Nextcloud\ReleaseTools\GitHub\KnpGitHubApi;
use Nextcloud\ReleaseTools\MajorLifecycle;
use Nextcloud\ReleaseTools\MilestonePlan;
use Nextcloud\ReleaseTools\ReleaseSchedule;
use Nextcloud\ReleaseTools\SchedulePlan;
use Nextcloud\ReleaseTools\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'schedule:extend',
    description: 'Keep the next two patch milestones dated in release-schedule.json',
)]
final class ScheduleExtendCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('tag', InputArgument::REQUIRED, 'Release tag, e.g. v33.0.10rc1 or v33.0.10')
            ->addArgument('schedule', InputArgument::REQUIRED, 'release-schedule.json path')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Rewrite the schedule file (default: report only)')
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'How many future milestones to keep dated', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = Version::fromTag((string) $input->getArgument('tag'));
        $path = (string) $input->getArgument('schedule');
        $schedule = ReleaseSchedule::load($path)->toArray();

        // The round date of the version being released. Its own schedule entry
        // is the truth when present; otherwise fall back to the due date of its
        // milestone, which the release team set when the round was planned.
        $shipping = MilestonePlan::name($version->major, $version->minor, $version->patch);
        $lifecycle = new MajorLifecycle(KnpGitHubApi::withToken(self::token()));
        $anchor = $schedule[$shipping] ?? $lifecycle->releaseDate($version);
        if ($anchor === null) {
            // Nothing to chain from. Say so loudly rather than guessing a date.
            $output->writeln(
                "::warning::No date for '{$shipping}' in the schedule or on its milestone;"
                . ' cannot work out the following rounds.',
            );
            return Command::SUCCESS;
        }

        $plan = SchedulePlan::forRelease(
            $version,
            $schedule,
            $anchor,
            $lifecycle->windowEnd($version->major),
            max(1, (int) $input->getOption('depth')),
        );

        $output->writeln("Release {$shipping} is due {$anchor}.");
        if ($plan->isEmpty()) {
            $output->writeln('Schedule is already up to date.');
            return Command::SUCCESS;
        }
        foreach ($plan->add as $title => $date) {
            $output->writeln("  + {$title}: {$date}");
        }
        foreach ($plan->remove as $title) {
            $output->writeln("  - {$title} (shipping now)");
        }
        if ($plan->add === []) {
            $output->writeln(
                sprintf('  no further rounds: %d leaves maintenance on %s', $version->major, $lifecycle->windowEnd($version->major) ?? '?'),
            );
        }

        if (!$input->getOption('write')) {
            $output->writeln('Report only, pass --write to apply.');
            return Command::SUCCESS;
        }

        self::save($path, $plan->applyTo($schedule));
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

    private static function token(): string
    {
        $token = getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN');
        if ($token === false || $token === '') {
            throw new \RuntimeException('GH_TOKEN (or GITHUB_TOKEN) is required');
        }
        return $token;
    }
}
