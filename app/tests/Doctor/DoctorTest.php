<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Agents\AgentTemplates;
use Pablo\Command\System\DoctorCommand;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Config\HooksConfig;
use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TrackerConfig;
use Pablo\Doctor\AgentFileState;
use Pablo\Doctor\AgentFileStatus;
use Pablo\Doctor\AgentStaleness;
use Pablo\Doctor\CheckResult;
use Pablo\Doctor\Doctor;
use Pablo\Store\Store;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class DoctorTest extends TestCase
{
    use UsesGlobalConfig;

    private const TMP = '/tmp';

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

    private const PROJECT = <<<'YAML'
name: wallet-kit
type: open-source
repo:
  path: %ROOT%/wallet-kit
  primary_branch: main
worktrees_root: %ROOT%/wt
issue_tracker:
  provider: github
  identity: octocat
  project_key: WK
YAML;

    private string $projectsDir = '';
    private FakeProcessRunner $runner;

    /** @var callable|null */
    public $onWhich;

    private function runner(): FakeProcessRunner
    {
        return $this->runner ??= new FakeProcessRunner();
    }

    private function doctor(): Doctor
    {
        /** @var callable|null */
        $which = $this->onWhich;

        return new Doctor($this->runner(), Registries::runtimes(new GlobalConfig()), Registries::ades(new GlobalConfig()), $which);
    }

    protected function setUp(): void
    {
        $this->projectsDir = TempDir::create('projects');
        $this->writeGlobalConfig(self::DEFAULTS);
        // A real checkout: the doctor's config section checks repo.path.
        mkdir($this->projectsDir.'/wallet-kit/.git', 0o777, true);
        file_put_contents($this->projectsDir.'/wallet-kit.yaml', str_replace('%ROOT%', $this->projectsDir, self::PROJECT));
        putenv('PABLO_PROJECTS_DIR='.$this->projectsDir);
        putenv('PABLO_STAMPS_DIR='.$this->projectsDir.'/stamps');
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
        putenv('PABLO_STAMPS_DIR');
        $this->unsetGlobalConfig();
    }

    private function makeCfg(string $name, string $provider, ?string $confluenceSpace = null, ?string $hookCommand = null): ProjectConfig
    {
        return new ProjectConfig(
            name: $name,
            type: 'work',
            repo: new RepoConfig(
                path: self::TMP.'/'.$name,
                primaryBranch: 'main',
                worktreesRoot: self::TMP.'/wt/'.$name,
            ),
            tracker: new TrackerConfig(
                provider: $provider,
                identity: 'octocat',
                projectKey: 'PR',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 30,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
            hooks: new HooksConfig(
                command: $hookCommand,
            ),
            confluenceSpace: $confluenceSpace,
        );
    }

    public function testHookExecutable(): void
    {
        $this->assertSame('notify-send', Doctor::hookExecutable('notify-send "PABLO" "$PABLO_TASK"'));
        $this->assertSame('osascript', Doctor::hookExecutable("FOO=1 'osascript' -e x"));
        $this->assertSame(getenv('HOME').'/bin/notify', Doctor::hookExecutable('~/bin/notify'));
        $this->assertNull(Doctor::hookExecutable('if true; then :; fi'));
        $this->assertNull(Doctor::hookExecutable('$HOME/bin/notify'));
        $this->assertNull(Doctor::hookExecutable('   '));
    }

    public function testHookWarningsOnlyForAMissingExecutable(): void
    {
        $this->onWhich = static fn (string $bin): ?string => 'notify-send' === $bin ? '/usr/bin/notify-send' : null;
        $warnings = $this->doctor()->hookWarnings([
            'a' => $this->makeCfg('a', 'github', hookCommand: 'notify-send hi'),
            'b' => $this->makeCfg('b', 'github', hookCommand: 'terminal-notifier -message hi'),
            'c' => $this->makeCfg('c', 'github', hookCommand: 'terminal-notifier -message hi'),
            'd' => $this->makeCfg('d', 'github', hookCommand: '/nonexistent/pablo-hook'),
            'e' => $this->makeCfg('e', 'github'),
        ]);
        $this->assertSame([
            "hooks.command: 'terminal-notifier' not found (projects: b, c)",
            "hooks.command: '/nonexistent/pablo-hook' not found (projects: d)",
        ], $warnings);
        $this->assertStringStartsWith('⚠️  hooks', $this->doctor()->renderHookWarnings($warnings));
    }

    public function testRequiredSetWithoutJiraLinear(): void
    {
        $projects = ['a' => $this->makeCfg('a', 'github')];
        $this->assertSame(['gh', 'opencode', 'orca'], $this->doctor()->requiredClis($projects));
    }

    public function testOpenChamberBackendReplacesOrca(): void
    {
        $this->writeGlobalConfig(self::DEFAULTS."\nagent_backend: openchamber\n");
        $projects = ['a' => $this->makeCfg('a', 'github')];
        $this->assertSame(['gh', 'openchamber', 'opencode'], $this->doctor()->requiredClis($projects));
    }

    /**
     * OpenChamber's desktop app also installs `openchamber`: exit 0 to every
     * call, it used to pass the check while every launch fell back headless.
     * It is refused from the file, and never run (that would open it).
     */
    public function testOpenChamberDesktopAppIsRefusedWithoutRunningIt(): void
    {
        $this->writeGlobalConfig(self::DEFAULTS."\nagent_backend: openchamber\n");
        $desktop = TempDir::file('doctor-oc');
        file_put_contents($desktop, "\x7fELF\x02\x01\x01");
        $this->onWhich = static fn (string $name): string => 'openchamber' === $name ? $desktop : '/usr/bin/'.$name;
        try {
            $openchamber = $this->findCli($this->doctor()->checkAll(['a' => $this->makeCfg('a', 'github')]), 'openchamber');
        } finally {
            unlink($desktop);
        }

        $this->assertFalse($openchamber->ok());
        $this->assertStringContainsString('desktop app', $openchamber->detail);
        $this->assertStringContainsString('npm i -g @openchamber/web', $openchamber->hint);
        $this->assertNotContains('openchamber', array_map(static fn (array $argv): string => $argv[0], $this->runner()->probeCalls));
    }

    public function testClaudeCodeRuntimeReplacesOpenCode(): void
    {
        $this->writeGlobalConfig(str_replace('openrouter/test/model', 'sonnet', self::DEFAULTS)."\nagent_runtime: claude-code\n");
        $projects = ['a' => $this->makeCfg('a', 'github')];
        // Never depend on a real `claude` on the PATH (CI has none).
        $this->onWhich = static fn (string $bin): string => '/usr/bin/'.$bin;
        $doctor = $this->doctor();
        $this->assertSame(['claude', 'gh', 'orca'], $doctor->requiredClis($projects));
        $this->assertSame([], $doctor->configErrors());
        $this->assertSame('curl -fsSL https://claude.ai/install.sh | bash', $doctor->installCommand('claude'));

        $this->runner()->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(0, "Login method: Claude Team account\n");
        $results = $doctor->checkIds(['claude']);
        $this->assertTrue($results[0]->ok());
        $this->assertSame('Login method: Claude Team account', $results[0]->detail);
        $this->assertSame([['claude', 'auth', 'status', '--text']], $this->runner()->probeCalls);
    }

    public function testCopilotRuntimeReplacesOpenCode(): void
    {
        $this->writeGlobalConfig(str_replace('openrouter/test/model', 'claude-sonnet-5.5', self::DEFAULTS)."\nagent_runtime: copilot\n");
        $this->onWhich = static fn (string $bin): string => '/usr/bin/'.$bin;
        $doctor = $this->doctor();
        $this->assertSame(['copilot', 'gh', 'orca'], $doctor->requiredClis(['a' => $this->makeCfg('a', 'github')]));
        $this->assertSame('npm install -g @github/copilot', $doctor->installCommand('copilot'));

        $this->runner()->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(0, "1.0.12\n");
        $this->assertTrue($doctor->checkIds(['copilot'])[0]->ok());
        $this->assertSame([['copilot', '--version']], $this->runner()->probeCalls);
    }

    public function testOpenChamberWithCopilotIsAConfigError(): void
    {
        $this->writeGlobalConfig(self::DEFAULTS."\nagent_runtime: copilot\nagent_backend: openchamber\n");
        $errors = $this->doctor()->configErrors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('agent_runtime copilot cannot run on agent_backend openchamber', $errors[0]);
    }

    public function testOpenChamberWithClaudeCodeIsAConfigError(): void
    {
        $this->writeGlobalConfig(self::DEFAULTS."\nagent_runtime: claude-code\nagent_backend: openchamber\n");
        $errors = $this->doctor()->configErrors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('OpenChamber only works with OpenCode', $errors[0]);
        $this->assertStringContainsString('agent_backend: orca', $errors[0]);
        $this->assertStringContainsString('❌ config', $this->doctor()->renderConfigErrors($errors));
    }

    public function testUnknownRuntimeIsAConfigError(): void
    {
        $this->writeGlobalConfig(self::DEFAULTS."\nagent_runtime: codex\n");
        $doctor = $this->doctor();
        $this->assertStringContainsString("unknown agent_runtime 'codex'", $doctor->configErrors()[0]);
        // The CLI checks still run (on OpenCode) so the rest stays reported.
        $this->assertContains('opencode', $doctor->requiredClis([]));
    }

    public function testRequiredIncludesAcliForJiraProjects(): void
    {
        $projects = ['a' => $this->makeCfg('a', 'jira'), 'b' => $this->makeCfg('b', 'linear')];
        $this->assertSame(['acli', 'gh', 'linear', 'opencode', 'orca'], $this->doctor()->requiredClis($projects));
    }

    public function testRequiredAddsAcliConfluenceOnlyWhenSpaceConfigured(): void
    {
        $projects = [
            'a' => $this->makeCfg('a', 'jira', 'PIM'),
            'b' => $this->makeCfg('b', 'jira'),
        ];
        $clis = $this->doctor()->requiredClis($projects);
        $this->assertContains('acli', $clis);
        $this->assertContains('acli-confluence', $clis);

        $projects = ['a' => $this->makeCfg('a', 'jira')];
        $this->assertNotContains('acli-confluence', $this->doctor()->requiredClis($projects));
    }

    public function testMissingCliReportedNotInstalled(): void
    {
        $this->onWhich = static fn (string $name): ?string => null;
        $results = $this->doctor()->checkAll(['a' => $this->makeCfg('a', 'github')]);
        foreach ($results as $r) {
            $this->assertFalse($r->installed);
            $this->assertFalse($r->ok());
        }
        $gh = $this->findCli($results, 'gh');
        $this->assertStringContainsString('not installed', $gh->detail);
    }

    public function testAuthFailureSurfacesCliMessageAndHint(): void
    {
        $this->onWhich = static fn (string $name): string => '/usr/bin/'.$name;
        $this->runner()->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(1, 'You are not logged into any GitHub hosts');
        $results = $this->doctor()->checkAll(['a' => $this->makeCfg('a', 'github')]);
        $gh = $this->findCli($results, 'gh');
        $this->assertTrue($gh->installed);
        $this->assertFalse($gh->authenticated);
        $this->assertFalse($gh->ok());
        $this->assertStringContainsString('not logged in', strtolower($gh->detail));
        $this->assertStringContainsString('gh auth login', $gh->hint);
    }

    public function testAllGreen(): void
    {
        $this->onWhich = static fn (string $name): string => '/usr/bin/'.$name;
        $this->runner()->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(0, 'ok');
        $results = $this->doctor()->checkAll(['a' => $this->makeCfg('a', 'github')]);
        foreach ($results as $r) {
            $this->assertTrue($r->ok());
        }
    }

    /**
     * @param list<string> $argv
     */
    private static function probeDispatch(array $argv): \Pablo\Support\ProbeResult
    {
        if ($argv === ['acli', 'confluence', 'auth', 'status']) {
            return new \Pablo\Support\ProbeResult(1, 'not authenticated: run acli confluence auth login');
        }
        if ($argv === ['acli', 'jira', 'auth', 'status']) {
            return new \Pablo\Support\ProbeResult(0, "✓ Authenticated\n  Email: acme@example.com");
        }

        return new \Pablo\Support\ProbeResult(0, 'ok');
    }

    public function testJiraProbeFailureSurfacesAcliHint(): void
    {
        $this->onWhich = static fn (string $name): string => '/usr/bin/'.$name;
        $this->runner()->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(1, 'Error: not authenticated');
        $results = $this->doctor()->checkAll(['a' => $this->makeCfg('a', 'jira')]);
        $check = $this->findCli($results, 'acli');
        $this->assertTrue($check->installed);
        $this->assertFalse($check->authenticated);
        $this->assertFalse($check->ok());
        $this->assertStringContainsString('acli auth login', $check->hint);
        $this->assertStringContainsString('not authenticated', $check->detail);
    }

    public function testJiraProbeOk(): void
    {
        $this->onWhich = static fn (string $name): string => '/usr/bin/'.$name;
        $this->runner()->onProbe = self::probeDispatch(...);
        $results = $this->doctor()->checkAll(['a' => $this->makeCfg('a', 'jira')]);
        $check = $this->findCli($results, 'acli');
        $this->assertTrue($check->ok());
        $this->assertStringContainsString('Authenticated', $check->detail);
    }

    public function testConfluenceProbeReportedSeparately(): void
    {
        $this->onWhich = static fn (string $name): string => '/usr/bin/'.$name;
        $this->runner()->onProbe = self::probeDispatch(...);
        $results = $this->doctor()->checkAll(['a' => $this->makeCfg('a', 'jira', 'PIM')]);
        $acliJira = $this->findCli($results, 'acli');
        $acliConf = $this->findCli($results, 'acli-confluence');
        $this->assertTrue($acliJira->ok());
        $this->assertFalse($acliConf->ok());
        $this->assertStringContainsString('confluence auth login', $acliConf->hint);
    }

    public function testRender(): void
    {
        $this->onWhich = static fn (string $name): string => '/usr/bin/'.$name;
        $this->runner()->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(0, 'ok');
        $results = [
            $this->doctor()->checkAll(['a' => $this->makeCfg('a', 'github')]),
        ];
        $all = array_merge(...$results);
        $out = $this->doctor()->render($all);
        $this->assertStringContainsString('✅', $out);
    }

    /** @param array<int, CheckResult> $results */
    private function findCli(array $results, string $cli): CheckResult
    {
        foreach ($results as $r) {
            if ($r->cli === $cli) {
                return $r;
            }
        }
        $this->fail("no result for {$cli}");
    }

    /** @return list<AgentFileStatus> */
    private function allAgentsOk(): array
    {
        $results = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $results[] = new AgentFileStatus($name, AgentFileState::Ok, 'ok');
        }

        return $results;
    }

    private function staleness(): AgentStaleness
    {
        return new AgentStaleness(new AgentTemplates(new Config(new GlobalConfig())), Registries::runtimes(), $this->allAgentsOk(...));
    }

    public function testDoctorExitCodes(): void
    {
        $this->onWhich = static fn (string $name): string => '/usr/bin/'.$name;
        $this->runner()->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(0, 'ok');
        $staleness = $this->staleness();
        $tester = new CommandTester(new DoctorCommand($this->doctor(), $staleness, new \Pablo\Doctor\SchedulerHealth(new \Pablo\Install\Scheduler\SchedulerFactory($this->runner(), 'Unsupported'), new \Pablo\Dispatch\DispatchHealth()), new Store(), new Config(new GlobalConfig()), Registries::launchers(new FakeProcessRunner(), new GlobalConfig()), new FakeAgents(), new \Pablo\Config\ConfigValidator(new GlobalConfig(), new Config(new GlobalConfig()))));
        $tester->execute([]);
        $this->assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());

        $this->runner()->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(1, 'login please');
        $tester = new CommandTester(new DoctorCommand($this->doctor(), $this->staleness(), new \Pablo\Doctor\SchedulerHealth(new \Pablo\Install\Scheduler\SchedulerFactory($this->runner(), 'Unsupported'), new \Pablo\Dispatch\DispatchHealth()), new Store(), new Config(new GlobalConfig()), Registries::launchers(new FakeProcessRunner(), new GlobalConfig()), new FakeAgents(), new \Pablo\Config\ConfigValidator(new GlobalConfig(), new Config(new GlobalConfig()))));
        $tester->execute([]);
        $this->assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
    }

    public function testDetachWarningOnlyWhenNoSessionHelperIsOnPath(): void
    {
        $this->onWhich = static fn (string $bin): ?string => 'perl' === $bin ? '/usr/bin/perl' : null;
        $this->assertNull($this->doctor()->detachWarning());

        $this->onWhich = static fn (string $bin): ?string => 'setsid' === $bin ? '/usr/bin/setsid' : null;
        $this->assertNull($this->doctor()->detachWarning());

        $this->onWhich = static fn (string $bin): ?string => null;
        $warning = (string) $this->doctor()->detachWarning();
        $this->assertStringContainsString('neither setsid nor perl', $warning);
    }
}
