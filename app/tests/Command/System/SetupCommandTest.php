<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Command\System\SetupCommand;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Doctor\Doctor;
use Pablo\Store\Store;
use Pablo\Tests\FakeAgents;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupCommandTest extends TestCase
{
    use UsesGlobalConfig;

    private const DEFAULTS = <<<'YAML'
sync:
  strategy: rebase
  auto_apply: false
  interval_minutes: 30
state_polling:
  interval_minutes: 10
review:
  bot_whitelist: []
ci:
  ignore_checks: []
default_model: openrouter/test/model
pr_description_locale: en
YAML;

    private string $tmp;
    private string $projectsDir;
    private FakeProcessRunner $runner;

    /**
     * Doctor whose PATH lookups are decided by the test.
     */
    private function doctor(): Doctor
    {
        /** @var callable|null */
        $onWhich = $this->onWhich;

        return new Doctor($this->runner, new AgentLauncherFactory(new GlobalConfig()), $onWhich);
    }

    /** @var callable|null */
    public $onWhich;

    protected function setUp(): void
    {
        $this->runner = new FakeProcessRunner();
        $this->tmp = sys_get_temp_dir().'/pablo-setup-'.uniqid();
        $this->projectsDir = $this->tmp.'/projects';
        mkdir($this->projectsDir, 0o777, true);

        // Hermetic by default: pretend every CLI exists, whatever the real
        // PATH holds (tests that exercise a missing CLI override onWhich).
        $this->onWhich = static fn (string $name): string => '/usr/bin/'.$name;

        $this->writeGlobalConfig(self::DEFAULTS);
        putenv('PABLO_PROJECTS_DIR='.$this->projectsDir);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
        $this->unsetGlobalConfig();
        $this->removeDir($this->tmp);
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function writeProject(string $name, string $provider, ?string $space = null): void
    {
        $confluence = null !== $space ? "\nconfluence:\n  space: {$space}" : '';
        file_put_contents($this->projectsDir.'/'.$name.'.yaml', <<<YAML
            name: {$name}
            type: work
            repo:
              path: /tmp/{$name}
              primary_branch: main
            issue_tracker:
              provider: {$provider}
              identity: octocat
              project_key: XX
            YAML.$confluence."\n");
    }

    private function setupTester(): CommandTester
    {
        $command = new SetupCommand(
            $this->doctor(),
            new Store(),
            new Config(new GlobalConfig()),
            new AgentLauncherFactory(new GlobalConfig()),
            new FakeAgents(),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    private function allOkProbes(): void
    {
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(0, 'ok');
    }

    public function testAllGreenNoProjects(): void
    {
        $this->allOkProbes();

        $tester = $this->setupTester();

        $this->assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('All required CLIs are installed', $tester->getDisplay());
    }

    public function testFirstMissingCheckIsShownAlone(): void
    {
        $this->onWhich = static fn (string $name): ?string => null;
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(0, 'ok');

        $tester = $this->setupTester();
        $display = $tester->getDisplay();

        $this->assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('gh', $display);
        $this->assertStringContainsString('not installed', $display);
        $this->assertStringContainsString('Install:', $display);
        $this->assertStringContainsString('re-run this command', $display);
        $this->assertStringNotContainsString('orca', $display);
        $this->assertStringNotContainsString('opencode', $display);
        $this->assertStringNotContainsString('acli', $display);
    }

    public function testSkipsGreenChecksToFirstFailure(): void
    {
        $this->onWhich = static fn (string $bin): ?string => 'gh' === $bin ? '/usr/bin/gh' : null;
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(0, 'ok');

        $tester = $this->setupTester();
        $display = $tester->getDisplay();

        $this->assertStringContainsString('orca', $display);
        $this->assertStringNotContainsString('opencode', $display);
    }

    public function testAuthFailureShowsHint(): void
    {
        $this->writeProject('acme', 'github');
        $this->onWhich = static fn (string $name): string => '/usr/bin/'.$name;
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => 'gh' === $argv[0] ? new \Pablo\Support\ProbeResult(1, 'You are not logged into any GitHub hosts') : new \Pablo\Support\ProbeResult(0, 'ok');

        $tester = $this->setupTester();
        $display = $tester->getDisplay();

        $this->assertStringContainsString('gh', $display);
        $this->assertStringContainsString('gh auth login', $display);
        $this->assertStringNotContainsString('linear', $display);
    }

    public function testTrackersIgnoredWithoutProjectsUsingThem(): void
    {
        $this->writeProject('acme', 'github');
        $this->allOkProbes();

        $tester = $this->setupTester();
        $display = $tester->getDisplay();

        $this->assertStringContainsString('All required CLIs are installed', $display);
        $this->assertStringNotContainsString('acli', $display);
        $this->assertStringNotContainsString('linear', $display);
    }

    public function testAcliShownForJiraProject(): void
    {
        $this->writeProject('acme', 'jira');
        $this->onWhich = static fn (string $name): string => '/usr/bin/'.$name;
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => (['acli', 'jira', 'auth', 'status'] === $argv) ? new \Pablo\Support\ProbeResult(1, 'not authenticated') : new \Pablo\Support\ProbeResult(0, 'ok');

        $tester = $this->setupTester();
        $display = $tester->getDisplay();

        $this->assertStringContainsString('acli', $display);
        $this->assertStringContainsString('acli auth login', $display);
        $this->assertStringNotContainsString('linear', $display);
    }

    public function testAcliConfluenceShownWhenSpaceConfigured(): void
    {
        $this->writeProject('acme', 'jira', 'PIM');
        $this->onWhich = static fn (string $name): string => '/usr/bin/'.$name;
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => match (true) {
            ['acli', 'jira', 'auth', 'status'] === $argv => new \Pablo\Support\ProbeResult(0, 'ok'),
            ['acli', 'confluence', 'auth', 'status'] === $argv => new \Pablo\Support\ProbeResult(1, 'not authenticated'),
            default => new \Pablo\Support\ProbeResult(0, 'ok'),
        };

        $tester = $this->setupTester();
        $display = $tester->getDisplay();

        $this->assertStringContainsString('acli-confluence', $display);
        $this->assertStringContainsString('acli confluence auth login', $display);
    }

    public function testLinearShownForLinearProject(): void
    {
        $this->writeProject('acme', 'linear');
        $this->onWhich = static fn (string $name): string => '/usr/bin/'.$name;
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => (['linear', 'auth', 'status'] === $argv) ? new \Pablo\Support\ProbeResult(1, 'not authenticated') : new \Pablo\Support\ProbeResult(0, 'ok');

        $tester = $this->setupTester();
        $display = $tester->getDisplay();

        $this->assertStringContainsString('linear', $display);
        $this->assertStringContainsString('linear auth login', $display);
        $this->assertStringNotContainsString('acli', $display);
    }
}
