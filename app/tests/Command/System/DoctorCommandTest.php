<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentTemplates;
use Pablo\Command\System\DoctorCommand;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Doctor\AgentStaleness;
use Pablo\Doctor\Doctor;
use Pablo\Store\Store;
use Pablo\Tests\FakeAgents;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers system:doctor's agents-staleness check against a temp $HOME: the
 * installed ~/.config/opencode/agents/<name>.md files are seeded from a fresh
 * in-memory render of the repo templates (exactly what install.sh symlinks).
 */
final class DoctorCommandTest extends TestCase
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
    private string $agentsDir;
    private string $oldHome = '';
    private FakeProcessRunner $runner;

    /** @var callable|null */
    public $onWhich;

    private function doctor(): Doctor
    {
        /** @var callable|null */
        $which = $this->onWhich;

        return new Doctor($this->runner, new AgentLauncherFactory(new GlobalConfig()), $which ?: static fn (string $name): string => '/usr/bin/'.$name);
    }

    private function command(): DoctorCommand
    {
        $global = new GlobalConfig();
        $staleness = new AgentStaleness(new AgentTemplates(new Config($global)));

        return new DoctorCommand($this->doctor(), $staleness, new Store(), new Config($global), new AgentLauncherFactory($global), new FakeAgents());
    }

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-doctor-agents-'.uniqid();
        $this->agentsDir = $this->tmp.'/home/.config/opencode/agents';
        mkdir($this->agentsDir, 0o777, true);
        mkdir($this->tmp.'/projects', 0o777, true);

        $oldHome = getenv('HOME');
        $this->oldHome = false !== $oldHome ? $oldHome : '';
        putenv('HOME='.$this->tmp.'/home');
        putenv('PABLO_PROJECTS_DIR='.$this->tmp.'/projects');

        $this->writeGlobalConfig(self::DEFAULTS);
        $this->runner = new FakeProcessRunner();
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(0, 'ok');
    }

    protected function tearDown(): void
    {
        if ('' !== $this->oldHome) {
            putenv('HOME='.$this->oldHome);
        } else {
            putenv('HOME');
        }
        putenv('PABLO_PROJECTS_DIR');
        $this->unsetGlobalConfig();
        $this->removeDir($this->tmp);
    }

    public function testAllAgentsFresh(): void
    {
        $this->installFreshAgents();

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('agents:', $display);
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $this->assertStringContainsString("✅ {$name}", $display, $name);
        }
    }

    public function testStaleAgentWarnsSoftlyAndDoesNotFail(): void
    {
        $this->installFreshAgents();
        file_put_contents($this->installedPath('task-feedback'),
            file_get_contents($this->installedPath('task-feedback'))."\nstale tail\n");

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('⚠️ task-feedback', $display);
        $this->assertStringContainsString('stale — differs from freshly rendered', $display);
        $this->assertStringContainsString('pablo system:generate-agents', $display);
        // staleness is cosmetic: doctor stays green
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testMissingAgentFailsHard(): void
    {
        $this->installFreshAgents();
        unlink($this->installedPath('ci-analyst'));

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('❌ ci-analyst', $display);
        $this->assertStringContainsString('missing', $display);
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function testBrokenSymlinkFailsHard(): void
    {
        $this->installFreshAgents();
        unlink($this->installedPath('pr-feedback'));
        symlink($this->tmp.'/nowhere/pr-feedback.md', $this->installedPath('pr-feedback'));

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('❌ pr-feedback', $display);
        $this->assertStringContainsString('broken symlink', $display);
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function testNonPabloFileFailsHard(): void
    {
        $this->installFreshAgents();
        unlink($this->installedPath('rebase-conflict-resolver'));
        file_put_contents($this->installedPath('rebase-conflict-resolver'), 'the user\'s own agent');

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('❌ rebase-conflict-resolver', $display);
        $this->assertStringContainsString('not a PABLO symlink', $display);
        $this->assertSame(1, $tester->getStatusCode());
    }

    /** Stale next to fresh agents: only the stale one is flagged, exit stays 0. */
    public function testStaleIsReportedPerAgentOnly(): void
    {
        $this->installFreshAgents();
        file_put_contents($this->installedPath('task-analyst'), 'hand-edited');

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('⚠️ task-analyst', $display);
        $this->assertStringContainsString('✅ task-feedback', $display);
        $this->assertSame(0, $tester->getStatusCode());
    }

    /**
     * Installs every generated agent into the fake HOME the way install.sh
     * does: a freshly rendered .md with a symlink pointing at it.
     */
    private function installFreshAgents(): void
    {
        $rendered = $this->tmp.'/repo-agents';
        mkdir($rendered, 0o777, true);
        $templates = new AgentTemplates(new Config(new GlobalConfig()));
        $providers = $templates->enabledProviders($this->tmp.'/projects');
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            file_put_contents(
                $rendered.'/'.$name.'.md',
                $templates->render($templates->repoAgentsDir(), $name, $providers),
            );
            symlink($rendered.'/'.$name.'.md', $this->installedPath($name));
        }
    }

    private function installedPath(string $name): string
    {
        return $this->agentsDir.'/'.$name.'.md';
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
