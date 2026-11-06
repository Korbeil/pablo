<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\ClaudeCodeAgents;
use Pablo\Agents\Runtime\CopilotAgents;
use Pablo\App\Kernel;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Install\InstallPaths;
use Pablo\Install\Links;
use Pablo\Install\PabloCommand;
use Pablo\Install\PathRc;
use Pablo\Install\Scheduler\SchedulerFactory;
use Pablo\Store\Store;
use Pablo\Support\Files;
use Pablo\Support\PabloPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes what `pablo system:install` created — the background services, the
 * links PABLO owns under ~/.config/opencode, ~/.claude and ~/.copilot (links
 * of a clone install included), the `pablo` command, the PATH block — plus
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

        foreach ([PabloPaths::runtimeDir(), Kernel::binaryCacheRoot(), InstallPaths::legacyClaudeDir()] as $generated) {
            if (is_dir($generated)) {
                Files::remove($generated);
                $info("removed {$generated}");
            }
        }

        if (PabloCommand::removeIfOurs(InstallPaths::binPath(), PabloPaths::pharPath())) {
            $info('removed '.InstallPaths::binPath());
        }

        foreach (PathRc::remove(PabloPaths::home(), getenv('ZDOTDIR') ?: null) as $line) {
            $info($line);
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
        $commands = array_map(static fn (string $path): string => basename($path, '.md'), Files::list(AgentTemplates::commandsDirFor($sources), '*.md'));
        $skills = array_map('basename', Files::list(AgentTemplates::skillsDirFor($sources), '*', dirsOnly: true));

        $opencode = InstallPaths::opencodeDir();
        $claude = InstallPaths::claudeDir();
        $copilot = InstallPaths::copilotDir();

        $paths = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $paths[] = $opencode.'/agents/'.$name.'.md';
            $paths[] = $claude.'/agents/'.ClaudeCodeAgents::agentId($name).'.md';
            $paths[] = $copilot.'/agents/'.CopilotAgents::agentId($name).'.agent.md';
        }
        foreach ($commands as $command) {
            $paths[] = $opencode.'/commands/'.$command.'.md';
            $paths[] = $claude.'/commands/'.$command.'.md';
            $paths[] = $copilot.'/skills/'.$command;
        }
        foreach ($skills as $skill) {
            $paths[] = $opencode.'/skill/'.$skill;
            $paths[] = $claude.'/skills/'.$skill;
            $paths[] = $copilot.'/skills/'.$skill;
        }

        return $paths;
    }
}
