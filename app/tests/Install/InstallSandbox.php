<?php

declare(strict_types=1);

namespace Pablo\Tests\Install;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Command\System\GenerateAgentsCommand;
use Pablo\Command\System\InstallCommand;
use Pablo\Command\System\UninstallCommand;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Install\Scheduler\SchedulerFactory;
use Pablo\Support\ProbeResult;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\SandboxedEnv;
use Pablo\Tests\TempDir;
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
    use SandboxedEnv;

    private const ISOLATED_ENV = [
        'HOME', 'PATH', 'SHELL', 'ZDOTDIR', 'PABLO_CONFIG', 'PABLO_PROJECTS_DIR', 'PABLO_RUNTIME_DIR',
        'PABLO_CLAUDE_DIR', 'PABLO_AGENT_RUNTIME', 'PABLO_AGENT_BACKEND', 'CLAUDE_CONFIG_DIR', 'COPILOT_HOME',
        'ORCA_CLI_COMMAND',
    ];

    private string $sandbox;
    private string $home;
    private FakeProcessRunner $runner;

    protected function createInstallSandbox(): void
    {
        $this->sandbox = TempDir::create('install');
        $this->home = $this->sandbox.'/home';
        mkdir($this->home, 0o777, true);
        mkdir($this->sandbox.'/bin', 0o777, true);

        foreach (self::ISOLATED_ENV as $name) {
            $this->setEnv($name, null);
        }
        $this->setEnv('HOME', $this->home);
        $this->setEnv('PATH', $this->sandbox.'/bin');
        $this->setEnv('SHELL', '/bin/bash');

        $this->runner = new FakeProcessRunner();
        $this->runner->onProbe = static fn (array $argv): ProbeResult => new ProbeResult(0, '');
    }

    protected function removeInstallSandbox(): void
    {
        $this->restoreEnv();
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
        $templates = new AgentTemplates($config);
        $runtimes = new AgentRuntimeRegistry($global, $templates);
        $ades = new AdeRegistry($global);
        $schedulers = new SchedulerFactory($this->runner, $os);

        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new GenerateAgentsCommand($templates, $runtimes, $config));
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
        $application->addCommand(new InstallCommand($global, $schedulers, $runtimes, $ades, $config));
        $application->addCommand(new UninstallCommand($schedulers, $templates, $runtimes));

        $tester = new CommandTester($application->find($name));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }
}
