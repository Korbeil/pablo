<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Store\Store;
use Pablo\Update\Updater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Updates PABLO with PABLO: replaces the running binary with the latest
 * release for this OS and architecture (see Updater), then runs
 * `system:install` with the new binary so agents, links and services follow
 * and the dashboard restarts on it.
 */
#[AsCommand(name: 'system:update', description: 'update the pablo binary to the latest release, then re-run system:install', aliases: ['update'])]
final class UpdateCommand extends Command
{
    public function __construct(
        private readonly Updater $updater,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'only tell whether a newer release exists')
            ->addOption('no-install', null, InputOption::VALUE_NONE, 'replace the binary but do not run system:install afterwards');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $info = static function (string $line) use ($output): void {
            $output->writeln('➜ '.$line);
        };

        $binary = $this->updater->binaryPath();
        if ('' === $binary) {
            $output->writeln('pablo runs from a repository checkout: update it with <info>git pull</info>, then <info>php app/bin/pablo system:install</info>.');

            return self::FAILURE;
        }

        $current = $this->updater->currentVersion();
        $latest = $this->updater->latestVersion();
        if (!Updater::isNewer($latest, $current)) {
            $output->writeln("PABLO <info>{$current}</info> is up to date (latest release: {$latest}).");

            return self::SUCCESS;
        }
        if ($input->getOption('check')) {
            $output->writeln("PABLO <info>{$latest}</info> is available (installed: {$current}). Run <info>pablo update</info>.");

            return self::SUCCESS;
        }

        $this->updater->update($latest, $info);
        $output->writeln("PABLO updated: {$current} → <info>{$latest}</info>");

        if ($input->getOption('no-install')) {
            $output->writeln('Run <info>pablo system:install</info> to refresh the agents and restart the services.');

            return self::SUCCESS;
        }

        // The new binary, not this process: it brings its own templates and units.
        $info("running {$binary} system:install");
        $result = $this->updater->runInstall();
        $output->writeln($result->output);
        if (0 !== $result->exitCode) {
            $output->writeln("<error>system:install failed: fix the error above, then run: {$binary} system:install</error>");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
