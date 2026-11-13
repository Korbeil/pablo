<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\App\ConsoleApplication;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Housekeeping\Pruner;
use Pablo\Install\ConfigSeeder;
use Pablo\Install\InstallPaths;
use Pablo\Install\Links;
use Pablo\Install\PabloCommand;
use Pablo\Install\PathRc;
use Pablo\Install\Scheduler\SchedulerFactory;
use Pablo\Install\Scheduler\SchedulerPath;
use Pablo\Install\ShellCompletion;
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
 *  2. ~/.local/bin/pablo: this binary (or, from a checkout, a shim),
 *     ~/.local/bin on the PATH, and the shell completion of the user's shell
 *     (unless --without-completion);
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
        private readonly AgentRuntimeRegistry $runtimes,
        private readonly AdeRegistry $ades,
        private readonly Config $projectsLoader,
        private readonly Pruner $pruner = new Pruner(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('runtime', null, InputOption::VALUE_REQUIRED, implode(' | ', AgentRuntimeRegistry::NAMES).': record it in ~/.pablo/config.yaml', null, AgentRuntimeRegistry::NAMES)
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, implode(' | ', AdeRegistry::NAMES).': record it in ~/.pablo/config.yaml', null, AdeRegistry::NAMES)
            ->addOption('without-web', null, InputOption::VALUE_NONE, 'do not install the dashboard service (and remove a previously installed one)')
            ->addOption('without-completion', null, InputOption::VALUE_NONE, 'do not install the shell completion, now or on later installs (shell_completion: false), and remove an installed one')
            ->addOption('with-completion', null, InputOption::VALUE_NONE, 'install the shell completion again after --without-completion (shell_completion: true)')
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
        $withoutCompletion = (bool) $input->getOption('without-completion');
        $withCompletion = (bool) $input->getOption('with-completion');

        // 0. Bad options are refused before anything is touched; so is a
        //    platform without a scheduler, and an invalid scheduler.path.
        $seeder = new ConfigSeeder($this->globalConfig, $this->projectsLoader, $this->runtimes, $this->ades);
        $seeder->validate($runtimeOption, $backendOption);
        if (null !== $portOption && !GlobalConfig::isPort($portOption)) {
            throw new PabloError("invalid --port {$portOption}: expected a number between 1 and 65535");
        }
        if ($withoutCompletion && $withCompletion) {
            throw new PabloError('--with-completion and --without-completion cannot be used together');
        }
        $scheduler = $this->schedulers->create();
        $this->globalConfig->schedulerPath();

        // 1. Config.
        $runtime = $this->runtimes->resolve($seeder->apply($runtimeOption, $backendOption, $info));
        if (null !== $portOption) {
            // `pablo web` (and so the service) reads it: no port in the unit files.
            ConfigSeeder::set($this->globalConfig->configPath(), 'dashboard_port', (string) (int) $portOption);
            $info('dashboard port: '.(int) $portOption);
        }
        if ($withoutCompletion || $withCompletion) {
            ConfigSeeder::set($this->globalConfig->configPath(), 'shell_completion', $withCompletion ? 'true' : 'false');
        }

        // 2. pablo on the PATH.
        $info(PabloCommand::place(InstallPaths::binPath(), PabloPaths::pharPath(), PabloPaths::resourcesDir().'/app/bin/pablo'));
        $rc = PathRc::add(
            InstallPaths::binDir(),
            (string) getenv('PATH'),
            PathRc::rcFile((string) getenv('SHELL'), PabloPaths::home(), \PHP_OS_FAMILY, getenv('ZDOTDIR') ?: null),
        );
        $info($rc['message']);
        $this->shellCompletion($info);

        // 3. Agents, commands and skills: rendered into ~/.pablo/runtime, then linked.
        $info("generating the {$runtime->name()} agent definitions into ".PabloPaths::runtimeDir());
        $generated = $this->runCommand('system:generate-agents', ['--runtime' => $runtime->name()], $output);
        if (self::SUCCESS !== $generated) {
            throw new PabloError('system:generate-agents failed (see above)');
        }
        $this->link($runtime, $info);
        foreach ($this->runtimes->all() as $any) {
            array_map($info, $any->agentFiles()->cleanupLegacy());
        }

        // 4. Background services, then the containers of other versions: only
        //    once the services restarted on this binary (`pablo update` also
        //    holds the dispatch lock meanwhile), so no running process of an
        //    older binary loses the container it reads its services from.
        $path = $this->schedulerPath($runtime);
        $info('services PATH: '.$path);
        $scheduler->install(InstallPaths::binPath(), $withWeb, $path, $info);
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
     * The services' PATH, from the installing user's: where the selected
     * runtime's and ADE's CLIs and the other tools PABLO calls are found now.
     *
     * @throws PabloError on an invalid scheduler.path
     */
    private function schedulerPath(AgentRuntimeInterface $runtime): string
    {
        $clis = [$runtime->probe()->argv[0] ?? ''];
        $adeProbe = $this->ades->resolveOrDefault()->probe();
        if (null !== $adeProbe) {
            $clis[] = $adeProbe->argv[0] ?? '';
        }

        return SchedulerPath::build(
            PabloPaths::home(),
            (string) getenv('PATH'),
            [...$clis, ...SchedulerPath::TOOLS],
            $this->globalConfig->schedulerPath(),
            $this->schedulers->osFamily(),
        );
    }

    /**
     * The completion of the user's shell, unless shell_completion is false
     * (then an installed one is removed).
     *
     * @param \Closure(string): void $info
     */
    private function shellCompletion(\Closure $info): void
    {
        if ($this->globalConfig->shellCompletion()) {
            array_map($info, ShellCompletion::install((string) getenv('SHELL'), PabloPaths::home()));

            return;
        }
        $info('shell completion off (shell_completion: false; --with-completion turns it back on)');
        array_map($info, ShellCompletion::remove(PabloPaths::home()));
    }

    /**
     * Containers compiled by other versions of the binary are dead weight:
     * each version compiles its own (see Kernel::getCacheDir()).
     *
     * @param \Closure(string): void $info
     */
    private function pruneContainers(\Closure $info): void
    {
        $stale = $this->pruner->staleContainers();
        $this->pruner->apply($stale);
        foreach ($stale as $item) {
            $info("removed the container cache of {$item->path}");
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
