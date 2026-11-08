<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\App\ConsoleApplication;
use Pablo\App\Kernel;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
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
 *     linked into the selected runtime's user directory (~/.config/opencode,
 *     ~/.claude, …) — never over a pre-existing file that is not PABLO's;
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
            ->addOption('runtime', null, InputOption::VALUE_REQUIRED, implode(' | ', AgentRuntimeRegistry::NAMES).': record it in ~/.pablo/config.yaml')
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
        $runtime = $this->agentLaunchers->runtimes()->resolve(
            (new ConfigSeeder($this->globalConfig, $this->projectsLoader))->apply($runtimeOption, $backendOption, $info),
        );
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
        $info("generating the {$runtime->name()} agent definitions into ".PabloPaths::runtimeDir());
        $generated = $this->runCommand('system:generate-agents', ['--runtime' => $runtime->name()], $output);
        if (self::SUCCESS !== $generated) {
            throw new PabloError('system:generate-agents failed (see above)');
        }
        $this->link($runtime, $info);
        foreach ($this->agentLaunchers->runtimes()->all() as $any) {
            array_map($info, $any->agentFiles()->cleanupLegacy());
        }

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
    private function link(AgentRuntimeInterface $runtime, \Closure $info): void
    {
        $links = new Links(PabloPaths::runtimeDir());
        $files = $runtime->agentFiles();
        foreach ($files->links(AgentTemplates::renderedDir()) as $link => $target) {
            $info($links->link($target, $link));
        }
        array_map($info, $files->installNotes());
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
