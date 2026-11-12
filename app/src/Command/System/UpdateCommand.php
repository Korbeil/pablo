<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Install\Scheduler\SchedulerFactory;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Update\Updater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Updates PABLO with PABLO: replaces the running binary with the latest
 * release for this OS and architecture — or the release named as argument,
 * older ones included (see Updater) — then runs
 * `system:install` with the new binary so agents, links and services follow
 * and the dashboard restarts on it. The replaced binary is kept:
 * `--rollback` swaps it back, and a failing `system:install` does so
 * automatically.
 */
#[AsCommand(name: 'system:update', description: 'update the pablo binary to the latest release (or the one given), then re-run system:install', aliases: ['update'])]
final class UpdateCommand extends Command
{
    public function __construct(
        private readonly Updater $updater,
        private readonly SchedulerFactory $schedulers,
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
            ->addArgument('release', InputArgument::OPTIONAL, 'the release to install, by name (e.g. 5b34e92; default: the latest)')
            ->addOption('check', null, InputOption::VALUE_NONE, 'only tell whether a newer release exists')
            ->addOption('no-install', null, InputOption::VALUE_NONE, 'replace the binary but do not run system:install afterwards')
            ->addOption('rollback', null, InputOption::VALUE_NONE, 'swap back the binary the last update replaced, then re-run system:install');
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
        $requested = $input->getArgument('release');
        $requested = \is_string($requested) && '' !== $requested ? $requested : null;

        if ($input->getOption('rollback')) {
            if (null !== $requested) {
                throw new PabloError('--rollback takes no release: it restores the binary the last update replaced');
            }

            return $this->rollback($input, $output, $info, $binary, $current);
        }

        if (null !== $requested) {
            // An explicit release is installed as asked, older ones included.
            $target = $requested;
            if ($target === $current) {
                $output->writeln("PABLO <info>{$current}</info> is already the running release.");

                return self::SUCCESS;
            }
        } else {
            $target = $this->updater->latestVersion();
            if (!Updater::isNewer($target, $current)) {
                $output->writeln("PABLO <info>{$current}</info> is up to date (latest release: {$target}).");

                return self::SUCCESS;
            }
        }
        if ($input->getOption('check')) {
            $output->writeln("PABLO <info>{$target}</info> is available (installed: {$current}). Run <info>pablo update".(null !== $requested ? ' '.$target : '').'</info>.');

            return self::SUCCESS;
        }

        // Read before anything changes: a --without-web install stays without.
        $withWeb = $this->schedulers->create()->hasWebService();

        // No dispatch run of the old binary may overlap the swap and the
        // pruning of its container; ticks skip while this is held.
        $lock = $this->updater->lockDispatch($info);
        try {
            try {
                $this->updater->update($target, $info);
            } catch (PabloError $e) {
                throw null !== $requested ? new PabloError($e->getMessage()." (is {$target} a PABLO release? See castor release:list or https://github.com/Korbeil/pablo/releases)") : $e;
            }
            $output->writeln("PABLO updated: {$current} → <info>{$target}</info>");

            if ($input->getOption('no-install')) {
                $output->writeln('Run <info>pablo system:install</info> to refresh the agents and restart the services.');

                return self::SUCCESS;
            }

            // The new binary, not this process: it brings its own templates and units.
            $result = $this->install($output, $info, $binary, $withWeb, hint: false);
            if (0 === $result) {
                return self::SUCCESS;
            }

            // A release whose install fails leaves a half-installed PABLO:
            // put the previous binary back and let it install again.
            $info('system:install failed with '.$target.': restoring the previous binary');
            try {
                $restored = $this->updater->rollback($info);
            } catch (PabloError $e) {
                $output->writeln('<error>cannot roll back: '.$e->getMessage().'</error>');
                $output->writeln("<error>fix the error above, then run: {$binary} system:install".($withWeb ? '' : ' --without-web').'</error>');

                return self::FAILURE;
            }
            $output->writeln("<error>PABLO rolled back: {$target} → {$restored} (system:install failed with {$target})</error>");
            $this->install($output, $info, $binary, $withWeb);

            return self::FAILURE;
        } finally {
            fclose($lock);
        }
    }

    /** @param \Closure(string): void $info */
    private function rollback(InputInterface $input, OutputInterface $output, \Closure $info, string $binary, string $current): int
    {
        $previous = $this->updater->previousPath();
        if (!is_file($previous)) {
            $output->writeln("<error>no previous binary to roll back to ({$previous} does not exist): pablo update keeps one when it replaces the binary.</error>");
            $output->writeln('To install a given release instead, run <info>pablo update &lt;release&gt;</info> (see https://github.com/Korbeil/pablo/releases).');

            return self::FAILURE;
        }
        if ($input->getOption('check')) {
            $output->writeln("A previous binary is kept: {$previous}. Run <info>pablo update --rollback</info> to restore it.");

            return self::SUCCESS;
        }

        $withWeb = $this->schedulers->create()->hasWebService();
        $lock = $this->updater->lockDispatch($info);
        try {
            $restored = $this->updater->rollback($info);
            $output->writeln("PABLO rolled back: {$current} → <info>{$restored}</info>");
            if ($input->getOption('no-install')) {
                $output->writeln('Run <info>pablo system:install</info> to refresh the agents and restart the services.');

                return self::SUCCESS;
            }

            return 0 === $this->install($output, $info, $binary, $withWeb) ? self::SUCCESS : self::FAILURE;
        } finally {
            fclose($lock);
        }
    }

    /**
     * `system:install` run by the binary now in place; its exit code.
     *
     * @param \Closure(string): void $info
     */
    private function install(OutputInterface $output, \Closure $info, string $binary, bool $withWeb, bool $hint = true): int
    {
        $info("running {$binary} system:install".($withWeb ? '' : ' --without-web'));
        $result = $this->updater->runInstall($withWeb);
        $output->writeln($result->output);
        if (0 !== $result->exitCode && $hint) {
            $output->writeln("<error>system:install failed: fix the error above, then run: {$binary} system:install".($withWeb ? '' : ' --without-web').'</error>');
        }

        return $result->exitCode;
    }
}
