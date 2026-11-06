<?php

declare(strict_types=1);

namespace Pablo\Tests\Install;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentTemplates;
use Pablo\Command\System\GenerateAgentsCommand;
use Pablo\Command\System\InstallCommand;
use Pablo\Command\System\UninstallCommand;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Install\Scheduler\SchedulerFactory;
use Pablo\Store\Store;
use Pablo\Support\ProbeResult;
use Pablo\Tests\FakeAgents;
use Pablo\Tests\FakeProcessRunner;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A throwaway HOME, a PATH holding only the fake tools a test creates, and
 * system:install / system:uninstall wired in-process with the real
 * system:generate-agents, a stub system:doctor and a FakeProcessRunner in
 * place of systemctl / launchctl.
 */
trait InstallSandbox
{
    private const ISOLATED_ENV = [
        'HOME', 'PATH', 'SHELL', 'ZDOTDIR', 'PABLO_CONFIG', 'PABLO_PROJECTS_DIR', 'PABLO_RUNTIME_DIR',
        'PABLO_CLAUDE_DIR', 'PABLO_AGENT_RUNTIME', 'PABLO_AGENT_BACKEND', 'CLAUDE_CONFIG_DIR', 'COPILOT_HOME',
    ];

    private string $sandbox;
    private string $home;
    private FakeProcessRunner $runner;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function createInstallSandbox(): void
    {
        $this->sandbox = sys_get_temp_dir().'/pablo-install-'.bin2hex(random_bytes(4));
        $this->home = $this->sandbox.'/home';
        mkdir($this->home, 0o777, true);
        mkdir($this->sandbox.'/bin', 0o777, true);

        foreach (self::ISOLATED_ENV as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
        putenv('HOME='.$this->home);
        putenv('PATH='.$this->sandbox.'/bin');
        putenv('SHELL=/bin/bash');

        $this->runner = new FakeProcessRunner();
        $this->runner->onProbe = static fn (array $argv): ProbeResult => new ProbeResult(0, '');
    }

    protected function removeInstallSandbox(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv(false === $value ? $name : $name.'='.$value);
        }
        if (str_contains($this->sandbox, 'pablo-install-')) {
            exec('rm -rf '.escapeshellarg($this->sandbox));
        }
    }

    /** A command that exists on the sandbox PATH. */
    protected function fakeTool(string $name): void
    {
        file_put_contents($this->sandbox.'/bin/'.$name, "#!/bin/sh\nexit 0\n");
        chmod($this->sandbox.'/bin/'.$name, 0o755);
    }

    /** @param array<string, mixed> $input */
    protected function install(array $input = [], string $os = 'Linux'): CommandTester
    {
        return $this->runCommand('system:install', $input, $os);
    }

    protected function uninstall(string $os = 'Linux'): CommandTester
    {
        return $this->runCommand('system:uninstall', [], $os);
    }

    /** @return list<string> every service-manager command line run, e.g. "systemctl --user daemon-reload" */
    protected function serviceCalls(): array
    {
        return array_map(static fn (array $argv): string => implode(' ', $argv), $this->runner->probeCalls);
    }

    protected function configValue(string $key): ?string
    {
        foreach (file($this->home.'/.pablo/config.yaml', \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, $key.':')) {
                return trim(substr($line, \strlen($key) + 1));
            }
        }

        return null;
    }

    /** @param array<string, mixed> $input */
    private function runCommand(string $name, array $input, string $os): CommandTester
    {
        $global = new GlobalConfig();
        $config = new Config($global);
        $store = new Store();
        $launchers = new AgentLauncherFactory($global);
        $templates = new AgentTemplates($config);
        $schedulers = new SchedulerFactory($this->runner, $os);

        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new GenerateAgentsCommand($templates, $store, $config, $launchers, new FakeAgents()));
        $application->addCommand(new class extends Command {
            public function __construct()
            {
                parent::__construct('system:doctor');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln('(doctor stub)');

                return self::SUCCESS;
            }
        });
        $application->addCommand(new InstallCommand($global, $schedulers, $store, $config, $launchers, new FakeAgents()));
        $application->addCommand(new UninstallCommand($schedulers, $templates, $store, $config, $launchers, new FakeAgents()));

        $tester = new CommandTester($application->find($name));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }
}
