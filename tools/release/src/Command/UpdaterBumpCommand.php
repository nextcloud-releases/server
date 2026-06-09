<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Command;

use Nextcloud\ReleaseTools\Updater\Bump;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'updater:bump', description: 'Apply a release to a checked-out updater_server working copy')]
final class UpdaterBumpCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('tag', InputArgument::REQUIRED, 'Release tag, e.g. v34.0.1 or v35.0.0beta1')
            ->addArgument('repo-dir', InputArgument::REQUIRED, 'Path to the checked-out updater_server')
            ->addOption('bz2-sig', null, InputOption::VALUE_REQUIRED, 'tar.bz2 signature (base64)')
            ->addOption('zip-sig', null, InputOption::VALUE_REQUIRED, 'zip signature (base64)')
            ->addOption('internal-version', null, InputOption::VALUE_REQUIRED, 'OC_Version, e.g. 34.0.1.1')
            ->addOption('min-php', null, InputOption::VALUE_REQUIRED, 'Minimum PHP for a new major', '8.1')
            ->addOption('deploy', null, InputOption::VALUE_REQUIRED, 'Deploy percentage (omit to auto-calculate)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deploy = $input->getOption('deploy');
        $plan = (new Bump((string) $input->getArgument('repo-dir')))->run(
            (string) $input->getArgument('tag'),
            (string) $this->require($input, 'bz2-sig'),
            (string) $this->require($input, 'zip-sig'),
            (string) $this->require($input, 'internal-version'),
            ($deploy === null || $deploy === '') ? null : (int) $deploy,
            (string) $input->getOption('min-php'),
        );

        $output->writeln(sprintf(
            'Updated updater_server for %s (type=%s, deploy=%d%%)',
            $plan->versionString,
            $plan->releaseType,
            $plan->deploy,
        ));
        return Command::SUCCESS;
    }

    private function require(InputInterface $input, string $option): string
    {
        $value = $input->getOption($option);
        if ($value === null || $value === '') {
            throw new \RuntimeException("--{$option} is required");
        }
        return (string) $value;
    }
}
