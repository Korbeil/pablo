<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Agents\AgentTemplates;
use Pablo\Command\System\DoctorCommand;
use Pablo\Config\Config;
use Pablo\Config\ConfigValidator;
use Pablo\Config\GlobalConfig;
use Pablo\Dispatch\DispatchHealth;
use Pablo\Doctor\AgentStaleness;
use Pablo\Doctor\Doctor;
use Pablo\Doctor\SchedulerHealth;
use Pablo\Install\Scheduler\SchedulerFactory;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\SandboxedEnv;
use Pablo\Tests\TempDir;
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
    use SandboxedEnv;
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
    private FakeProcessRunner $runner;

    /** @var callable|null */
    public $onWhich;

    private function doctor(): Doctor
    {
        /** @var callable|null */
        $which = $this->onWhich;

        return new Doctor($this->runner, \Pablo\Tests\Registries::runtimes(new GlobalConfig()), \Pablo\Tests\Registries::ades(new GlobalConfig()), $which ?: static fn (string $name): string => '/usr/bin/'.$name);
    }

    private function command(): DoctorCommand
    {
        $global = new GlobalConfig();
        $staleness = new AgentStaleness(new AgentTemplates(new Config($global)), \Pablo\Tests\Registries::runtimes());

        return new DoctorCommand($this->doctor(), $staleness, new SchedulerHealth(new SchedulerFactory($this->runner, 'Linux'), new DispatchHealth()), new Config($global), new ConfigValidator($global, new Config($global)));
    }

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('doctor-agents');
        $this->agentsDir = $this->tmp.'/home/.config/opencode/agents';
        mkdir($this->agentsDir, 0o777, true);
        mkdir($this->tmp.'/projects', 0o777, true);
        // The checkout the test projects point at (the config section checks repo.path).
        mkdir($this->tmp.'/wallet-kit/.git', 0o777, true);

        $this->setEnv('HOME', $this->tmp.'/home');
        $this->setEnv('PABLO_PROJECTS_DIR', $this->tmp.'/projects');

        $this->writeGlobalConfig(self::DEFAULTS);
        $this->runner = new FakeProcessRunner();
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(0, 'ok');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
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

    public function testSchedulerSectionWarnsWithoutChangingTheExitCode(): void
    {
        $this->installFreshAgents();
        file_put_contents($this->tmp.'/projects/wallet-kit.yaml', <<<YAML
            name: wallet-kit
            type: work
            repo:
                path: {$this->tmp}/wallet-kit
                primary_branch: main
            issue_tracker:
                provider: github
                identity: octocat
                project_key: WK
            YAML);
        (new DispatchHealth())->recordFailure('wallet-kit', 'sync', microtime(true), 'git fetch: could not resolve host');

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('scheduler:', $display);
        $this->assertStringContainsString('pablo-dispatch.timer is not installed', $display);
        $this->assertStringContainsString('no dispatch recorded yet', $display);
        $this->assertStringContainsString('wallet-kit sync failing since', $display);
        $this->assertStringContainsString('(1×): git fetch: could not resolve host', $display);
    }

    public function testOpenChamberWithClaudeCodeFailsWithAClearMessage(): void
    {
        $this->writeGlobalConfig(str_replace('openrouter/test/model', 'sonnet', self::DEFAULTS)."\nagent_runtime: claude-code\nagent_backend: openchamber\n");

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('❌ config', $display);
        $this->assertStringContainsString('OpenChamber only works with OpenCode', $display);
        // The rest of the checks still run, on the Claude Code runtime.
        $this->assertStringContainsString('claude', $display);
        $this->assertStringContainsString('agents.json', $display);
    }

    public function testInvalidProjectFileFailsWithItsError(): void
    {
        $this->installFreshAgents();
        file_put_contents($this->tmp.'/projects/broken.yaml', "name: broken\ntype: work\n");

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("❌ config     broken.yaml: missing required key 'repo.path'", $tester->getDisplay());
    }

    public function testConfigSectionWarnsAboutUnknownKeysWithoutFailing(): void
    {
        $this->installFreshAgents();
        $this->writeGlobalConfig(str_replace("ci:\n  ignore_checks: []", "ci:\n  ignore_check: []", self::DEFAULTS));

        $tester = new CommandTester($this->command());
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('⚠️  config    config.yaml: ci.ignore_check is not a known key, ignored; did you mean `ci.ignore_checks`?', $tester->getDisplay());

        $tester = new CommandTester($this->command());
        $tester->execute(['--format' => 'json']);
        $this->assertSame(0, $tester->getStatusCode());
        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $config = array_values(array_filter($payload['checks'], static fn (array $c): bool => 'config' === $c['group']));
        $this->assertSame('warn', $config[0]['status']);
        $this->assertSame('config.yaml:ci.ignore_check', $config[0]['name']);
    }

    public function testConfigSectionFailsOnAMissingRepository(): void
    {
        $this->installFreshAgents();
        file_put_contents($this->tmp.'/projects/gone.yaml', <<<YAML
            name: gone
            type: work
            repo:
                path: {$this->tmp}/gone
                primary_branch: main
            issue_tracker:
                provider: github
                identity: octocat
                project_key: GO
            YAML);

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("❌ config     gone.yaml: repo.path is not a directory: {$this->tmp}/gone", $tester->getDisplay());
    }

    public function testABrokenGlobalConfigStopsTheDoctorWithItsError(): void
    {
        $this->writeGlobalConfig(str_replace('interval_minutes: 30', 'interval_minutes: 0', self::DEFAULTS));

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('❌ config     config.yaml: sync.interval_minutes must be a whole number of minutes, at least 1, got 0', $tester->getDisplay());
        $this->assertStringContainsString('pablo system:config:validate', $tester->getDisplay());
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

    public function testJsonListsEveryCheckWithItsStatus(): void
    {
        $this->installFreshAgents();
        file_put_contents($this->installedPath('task-analyst'), 'hand-edited');

        $tester = new CommandTester($this->command());
        $tester->execute(['--format' => 'json']);

        $this->assertSame(0, $tester->getStatusCode());
        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['ok']);
        $byName = [];
        foreach ($payload['checks'] as $check) {
            $this->assertSame(['group', 'name', 'status', 'message', 'hint'], \array_slice(array_keys($check), 0, 5));
            $byName[$check['group'].':'.$check['name']] = $check;
        }
        $this->assertSame('ok', $byName['cli:gh']['status']);
        $this->assertSame('warn', $byName['agents:task-analyst']['status']);
        $this->assertStringContainsString('pablo system:generate-agents', $byName['agents:task-analyst']['hint']);
        $this->assertSame('ok', $byName['agents:task-feedback']['status']);
    }

    public function testJsonKeepsTheFailingExitCode(): void
    {
        $this->installFreshAgents();
        unlink($this->installedPath('ci-analyst'));

        $tester = new CommandTester($this->command());
        $tester->execute(['--format' => 'json']);

        $this->assertSame(1, $tester->getStatusCode());
        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['ok']);
        $failed = array_values(array_filter($payload['checks'], static fn (array $c): bool => 'fail' === $c['status']));
        $this->assertSame(['ci-analyst'], array_column($failed, 'name'));
    }

    public function testMissingHookCommandWarnsInBothFormatsWithoutFailing(): void
    {
        $this->installFreshAgents();
        $this->writeGlobalConfig(self::DEFAULTS."\nhooks:\n  command: pablo-missing-notifier --now\n");
        file_put_contents($this->tmp.'/projects/wallet-kit.yaml', <<<YAML
            name: wallet-kit
            type: work
            repo:
                path: {$this->tmp}/wallet-kit
                primary_branch: main
            issue_tracker:
                provider: github
                identity: octocat
                project_key: WK
            YAML);
        $this->onWhich = static fn (string $name): ?string => 'pablo-missing-notifier' === $name ? null : '/usr/bin/'.$name;

        $tester = new CommandTester($this->command());
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString("hooks.command: 'pablo-missing-notifier' not found (projects: wallet-kit)", $tester->getDisplay());

        $tester = new CommandTester($this->command());
        $tester->execute(['--format' => 'json']);
        $this->assertSame(0, $tester->getStatusCode());
        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['ok']);
        $hooks = array_values(array_filter($payload['checks'], static fn (array $c): bool => 'hooks' === $c['group']));
        $this->assertCount(1, $hooks);
        $this->assertSame('warn', $hooks[0]['status']);
        $this->assertStringContainsString("'pablo-missing-notifier' not found", $hooks[0]['message']);
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
                $templates->render($templates->templatesDir(), $name, $providers),
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
