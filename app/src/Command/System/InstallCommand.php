<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\ClaudeCodeAgents;
use Pablo\Agents\Runtime\ClaudeCodeRuntime;
use Pablo\Agents\Runtime\CopilotAgents;
use Pablo\App\ConsoleApplication;
use Pablo\App\Kernel;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Install\ClaudeTrust;
use Pablo\Install\ConfigSeeder;
use Pablo\Install\InstallPaths;
use Pablo\Install\Links;
use Pablo\Install\PabloCommand;
use Pablo\Install\PathRc;
use Pablo\Install\Scheduler\SchedulerFactory;
use Pablo\Store\Store;
use Pablo\Support\Files;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Installs PABLO for the current user (Linux + macOS). Idempotent: run it
 * again after an upgrade or to switch runtime.
 *
 *  1. ~/.pablo/config.yaml seeded from the bundled example (an existing one
 *     is kept, except for the keys --runtime / --backend set);
 *  2. ~/.local/bin/pablo: this binary (or, from a checkout, a shim), and
 *     ~/.local/bin on the PATH;
 *  3. the agents, commands and skills rendered into ~/.pablo/runtime and
 *     linked into ~/.config/opencode, ~/.claude or ~/.copilot — never over a
 *     pre-existing file that is not PABLO's;
 *  4. the dispatcher (and the dashboard, unless --without-web) as systemd
 *     user units or launchd agents, written from the bundled files;
 *  5. a `pablo system:doctor` run.
 *
 * It takes over a clone install (bin/install.sh): its links into the
 * checkout, its unit symlinks and its shim are replaced; the checkout itself
 * is not touched.
 */
#[AsCommand(name: 'system:install', description: 'install PABLO for this user: config, pablo on the PATH, agents/commands/skills, background services')]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly GlobalConfig $globalConfig,
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
            ->addOption('runtime', null, InputOption::VALUE_REQUIRED, 'opencode | claude-code | copilot: record it in ~/.pablo/config.yaml')
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, 'orca | openchamber | headless: record it in ~/.pablo/config.yaml')
            ->addOption('without-web', null, InputOption::VALUE_NONE, 'do not install the dashboard service (and remove a previously installed one)')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'port of the dashboard: record it in ~/.pablo/config.yaml (dashboard_port)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $info = static function (string $line) use ($output): void {
            $output->writeln('➜ '.$line);
        };
        $runtimeOption = self::option($input, 'runtime');
        $backendOption = self::option($input, 'backend');
        $portOption = self::option($input, 'port');
        $withWeb = !$input->getOption('without-web');

        // 0. Bad options are refused before anything is touched; so is a
        //    platform without a scheduler.
        ConfigSeeder::validate($runtimeOption, $backendOption);
        if (null !== $portOption && !GlobalConfig::isPort($portOption)) {
            throw new PabloError("invalid --port {$portOption}: expected a number between 1 and 65535");
        }
        $scheduler = $this->schedulers->create();

        // 1. Config.
        $runtime = (new ConfigSeeder($this->globalConfig, $this->projectsLoader))->apply($runtimeOption, $backendOption, $info);
        if (null !== $portOption) {
            // `pablo web` (and so the service) reads it: no port in the unit files.
            ConfigSeeder::set($this->globalConfig->configPath(), 'dashboard_port', (string) (int) $portOption);
            $info('dashboard port: '.(int) $portOption);
        }

        // 2. pablo on the PATH.
        $info(PabloCommand::place(InstallPaths::binPath(), PabloPaths::pharPath(), PabloPaths::resourcesDir().'/app/bin/pablo'));
        $rc = PathRc::add(
            InstallPaths::binDir(),
            (string) getenv('PATH'),
            PathRc::rcFile((string) getenv('SHELL'), PabloPaths::home(), \PHP_OS_FAMILY, getenv('ZDOTDIR') ?: null),
        );
        $info($rc['message']);

        // 3. Agents, commands and skills: rendered into ~/.pablo/runtime, then linked.
        $info("generating the {$runtime} agent definitions into ".PabloPaths::runtimeDir());
        $generated = $this->runCommand('system:generate-agents', ['--runtime' => $runtime], $output);
        if (self::SUCCESS !== $generated) {
            throw new PabloError('system:generate-agents failed (see above)');
        }
        $this->link($runtime, $info);
        $this->dropLegacyClaudeDir($info);

        // 4. Background services, then the containers of other versions: only
        //    once the services restarted on this binary (`pablo update` also
        //    holds the dispatch lock meanwhile), so no running process of an
        //    older binary loses the container it reads its services from.
        $scheduler->install(InstallPaths::binPath(), $withWeb, $info);
        $this->pruneContainers($info);

        // 5. Preflight.
        $info('running pablo system:doctor (a failure here is a warning at install time —');
        $info('projects may not be configured yet):');
        $doctorOk = self::SUCCESS === $this->runCommand('system:doctor', [], $output);

        $output->writeln('');
        $info('PABLO '.ConsoleApplication::version().' is installed. Next steps:');
        if (null !== $rc['rc']) {
            $output->writeln("   0. open a new terminal (or run: source {$rc['rc']}) so the pablo command is found");
        }
        if (!$doctorOk) {
            $output->writeln('   •  some checks failed: pablo system:setup walks you through each fix');
        }
        $output->writeln('   1. pablo project:new               # run it inside your repository');
        $output->writeln('   2. pablo task:start <issue-url>    # or: pablo task:start --project <name> "what to do"');
        $output->writeln($withWeb
            ? '   3. watch it on http://127.0.0.1:'.$this->globalConfig->dashboardPort(WebCommand::DEFAULT_PORT)
            : '   3. pablo task:list (or pablo web for the dashboard)');

        return self::SUCCESS;
    }

    /**
     * @param \Closure(string): void $info
     *
     * @throws PabloError
     */
    private function link(string $runtime, \Closure $info): void
    {
        $links = new Links(PabloPaths::runtimeDir());
        $opencode = AgentTemplates::opencodeRuntimeDir();
        $skills = Files::list($opencode.'/skills', '*', dirsOnly: true);

        if ('claude-code' === $runtime) {
            // PABLO's own launches pass agents.json with --agents; the agents
            // are also linked into ~/.claude/agents as pablo-<agent>, so they
            // show in /agents and the @ picker of your own sessions.
            $claude = ClaudeCodeRuntime::defaultDir();
            if (!is_file($claude.'/agents.json')) {
                throw new PabloError("{$claude}/agents.json was not generated — run: pablo system:generate-agents --runtime claude-code");
            }
            foreach (AgentTemplates::AGENT_NAMES as $name) {
                $file = ClaudeCodeAgents::agentId($name).'.md';
                $info($links->link($claude.'/agents/'.$file, InstallPaths::claudeDir().'/agents/'.$file));
            }
            foreach (Files::list($claude.'/commands', '*.md') as $command) {
                $info($links->link($command, InstallPaths::claudeDir().'/commands/'.basename($command)));
            }
            foreach ($skills as $skill) {
                $info($links->link($skill, InstallPaths::claudeDir().'/skills/'.basename($skill)));
            }
            // Interactive sessions (Orca) would otherwise ask, for every new
            // worktree, whether Claude Code may trust it.
            foreach (ClaudeTrust::trust($this->worktreeRoots()) as $line) {
                $info($line);
            }

            return;
        }

        if ('copilot' === $runtime) {
            // pablo-<agent> in ~/.copilot/agents (usable in your own Copilot
            // sessions too); /pablo-commit-and-pr and pablo-task as skills.
            $copilot = AgentTemplates::copilotRuntimeDir();
            foreach (AgentTemplates::AGENT_NAMES as $name) {
                $file = CopilotAgents::agentId($name).'.agent.md';
                $info($links->link($copilot.'/agents/'.$file, InstallPaths::copilotDir().'/agents/'.$file));
            }
            foreach ([...Files::list($copilot.'/skills', '*', dirsOnly: true), ...$skills] as $skill) {
                $info($links->link($skill, InstallPaths::copilotDir().'/skills/'.basename($skill)));
            }
            $info("Copilot: log in once with 'copilot login' if you have not already");

            return;
        }

        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $info($links->link($opencode.'/agents/'.$name.'.md', InstallPaths::opencodeDir().'/agents/'.$name.'.md'));
        }
        foreach (Files::list($opencode.'/commands', '*.md') as $command) {
            $info($links->link($command, InstallPaths::opencodeDir().'/commands/'.basename($command)));
        }
        foreach ($skills as $skill) {
            $info($links->link($skill, InstallPaths::opencodeDir().'/skill/'.basename($skill)));
        }
    }

    /**
     * ~/.pablo/worktrees, and every project's own worktrees_root.
     *
     * @return list<string>
     */
    private function worktreeRoots(): array
    {
        $roots = [ClaudeTrust::defaultRoot()];
        try {
            foreach ($this->projectsLoader->loadProjects() as $project) {
                $roots[] = $project->worktreesRoot;
            }
        } catch (\Throwable) {
            // a broken project file is reported elsewhere (doctor, dispatch)
        }

        return $roots;
    }

    /**
     * The clone install kept the Claude Code `--agents` file in ~/.pablo/claude;
     * it now lives in ~/.pablo/runtime/claude.
     *
     * @param \Closure(string): void $info
     */
    private function dropLegacyClaudeDir(\Closure $info): void
    {
        $legacy = InstallPaths::legacyClaudeDir();
        if (!is_file($legacy.'/agents.json') || $legacy === ClaudeCodeRuntime::defaultDir()) {
            return;
        }
        @unlink($legacy.'/agents.json');
        @rmdir($legacy);
        $info("removed {$legacy}/agents.json (now in ".ClaudeCodeRuntime::defaultDir().')');
    }

    /**
     * Containers compiled by other versions of the binary are dead weight:
     * each version compiles its own (see Kernel::getCacheDir()).
     *
     * @param \Closure(string): void $info
     */
    private function pruneContainers(\Closure $info): void
    {
        if (!PabloPaths::isPhar()) {
            return;
        }
        foreach (Files::list(Kernel::binaryCacheRoot(), '*', dirsOnly: true) as $dir) {
            if (basename($dir) !== ConsoleApplication::version()) {
                Files::remove($dir);
                $info("removed the container cache of {$dir}");
            }
        }
    }

    /** @param array<string, string> $arguments */
    private function runCommand(string $name, array $arguments, OutputInterface $output): int
    {
        $application = $this->getApplication();
        if (null === $application) {
            throw new PabloError("cannot run {$name}: no console application");
        }
        $input = new ArrayInput($arguments);
        $input->setInteractive(false);

        return $application->find($name)->run($input, $output);
    }

    private static function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
