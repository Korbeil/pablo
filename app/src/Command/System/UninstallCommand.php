<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\AgentTemplates;
use Pablo\App\Kernel;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Install\InstallPaths;
use Pablo\Install\Links;
use Pablo\Install\PabloCommand;
use Pablo\Install\PathRc;
use Pablo\Install\Scheduler\SchedulerFactory;
use Pablo\Install\ShellCompletion;
use Pablo\Store\Store;
use Pablo\Support\Files;
use Pablo\Support\PabloPaths;
use Pablo\Update\Updater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes what `pablo system:install` created — the background services, the
 * links PABLO owns in every runtime's user directory (links of a clone
 * install included), the `pablo` command, the PATH block, the shell
 * completion — plus
 * the generated ~/.pablo/runtime and container caches. Runtime data in
 * ~/.pablo (config, projects, state, stamps, worktrees, logs) is kept.
 */
#[AsCommand(name: 'system:uninstall', description: 'remove the background services, links, pablo command and PATH block (keeps ~/.pablo data)')]
final class UninstallCommand extends Command
{
    public function __construct(
        private readonly SchedulerFactory $schedulers,
        private readonly AgentTemplates $agentTemplates,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $info = static function (string $line) use ($output): void {
            $output->writeln('➜ '.$line);
        };

        $this->schedulers->create()->uninstall($info);

        $links = new Links(PabloPaths::runtimeDir());
        foreach ($this->candidates() as $dest) {
            if ($links->removeIfOurs($dest)) {
                $info("removed {$dest}");
            }
        }

        foreach ($this->agentLaunchers->runtimes()->all() as $runtime) {
            array_map($info, $runtime->agentFiles()->cleanupLegacy());
        }
        foreach ([PabloPaths::runtimeDir(), Kernel::binaryCacheRoot()] as $generated) {
            if (is_dir($generated)) {
                Files::remove($generated);
                $info("removed {$generated}");
            }
        }

        foreach (PathRc::remove(PabloPaths::home(), getenv('ZDOTDIR') ?: null) as $line) {
            $info($line);
        }
        array_map($info, ShellCompletion::remove(PabloPaths::home()));

        // The binary `pablo update` kept for --rollback: only PABLO writes it.
        $previous = Updater::previousOf(InstallPaths::binPath());
        if (is_file($previous) && @unlink($previous)) {
            $info('removed '.$previous);
        }

        // Last: when it is the running binary, it goes when this process exits.
        if (PabloCommand::removeIfOurs(InstallPaths::binPath(), PabloPaths::pharPath())) {
            $info('removed '.InstallPaths::binPath());
        }

        $info('done. ~/.pablo (config, projects, state, stamps, worktrees, logs) was left in place.');

        return self::SUCCESS;
    }

    /**
     * Every path an install of any runtime may have linked (only links that
     * are PABLO's are removed).
     *
     * @return list<string>
     */
    private function candidates(): array
    {
        $sources = $this->agentTemplates->templatesDir();
        $paths = [];
        foreach ($this->agentLaunchers->runtimes()->all() as $runtime) {
            $paths = [...$paths, ...$runtime->agentFiles()->installedPaths($sources)];
        }

        return $paths;
    }
}
