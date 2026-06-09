<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Command;

use Nextcloud\ReleaseTools\GitHub\KnpGitHubApi;
use Nextcloud\ReleaseTools\ReleaseConfig;
use Nextcloud\ReleaseTools\RepoTagger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'repo:tag', description: 'Tag all release repositories at a branch')]
final class RepoTagCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('tag', InputArgument::REQUIRED, 'Tag to create, e.g. v34.0.1')
            ->addArgument('branch', InputArgument::REQUIRED, 'Release branch (stableXX/master); falls back to each repo default')
            ->addArgument('config', InputArgument::REQUIRED, 'Config file (stableXX.json / master.json)')
            ->addArgument('tag-only', InputArgument::REQUIRED, 'tag-only.json path')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Recreate existing tags (never on the server repos)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without changing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tag = (string) $input->getArgument('tag');
        $branch = (string) $input->getArgument('branch');
        $repos = ReleaseConfig::repos((string) $input->getArgument('config'), (string) $input->getArgument('tag-only'));
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        $tagger = new RepoTagger(KnpGitHubApi::withToken(self::token()), $dryRun);

        $ok = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($repos as $repo) {
            $r = $tagger->tag($repo, $branch, $tag, $force);
            $output->writeln(sprintf('%-40s %-10s %s (%s)', $r->repo, $r->status, $r->branch, $r->detail));
            match ($r->status) {
                'OK' => $ok++,
                'SKIPPED' => $skipped++,
                default => $failed++,
            };
        }

        $output->writeln(sprintf('%sTagged: %d | Skipped: %d | Failed: %d', $dryRun ? '[dry-run] ' : '', $ok, $skipped, $failed));
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
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
