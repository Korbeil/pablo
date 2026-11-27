<?php

declare(strict_types=1);

namespace Pablo\Tests\Doctor;

use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TrackerConfig;
use Pablo\Dispatch\DispatchHealth;
use Pablo\Doctor\SchedulerHealth;
use Pablo\Install\Scheduler\SchedulerFactory;
use Pablo\Install\Scheduler\ServiceFiles;
use Pablo\Support\ProbeResult;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\SandboxedEnv;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * system:doctor's scheduler section, against a throwaway HOME and a fake
 * process runner: no real systemctl/launchctl ever runs.
 */
final class SchedulerHealthTest extends TestCase
{
    use SandboxedEnv;

    private const NOW = 1_800_000_000.0;

    private string $tmp;
    private FakeProcessRunner $runner;
    private DispatchHealth $health;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('scheduler-health');
        mkdir($this->tmp.'/home', 0o777, true);
        $this->setEnv('HOME', $this->tmp.'/home');
        $this->setEnv('PABLO_STAMPS_DIR', $this->tmp.'/stamps');
        $this->setEnv('PABLO_CONFIG', $this->tmp.'/config.yaml');
        $this->runner = new FakeProcessRunner();
        $this->health = new DispatchHealth();
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    private function cfg(string $name): ProjectConfig
    {
        return new ProjectConfig(
            name: $name,
            type: 'work',
            repo: new RepoConfig(
                path: $this->tmp.'/'.$name,
                primaryBranch: 'main',
                worktreesRoot: $this->tmp.'/wt/'.$name,
            ),
            tracker: new TrackerConfig(
                provider: 'github',
                identity: 'octocat',
                projectKey: 'PR',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 720,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
    }

    private function installTimer(?int $tick = null): void
    {
        $dir = $this->tmp.'/home/.config/systemd/user';
        mkdir($dir, 0o777, true);
        file_put_contents($dir.'/pablo-dispatch.timer', '# '.ServiceFiles::marker('pablo-dispatch.timer')."\n".(null !== $tick ? "[Timer]\nOnCalendar=*:0/{$tick}\n" : ''));
    }

    /** @param array<string, ProjectConfig> $projects */
    private function render(array $projects = []): string
    {
        $lines = (new SchedulerHealth(new SchedulerFactory($this->runner, 'Linux'), $this->health))->lines($projects, self::NOW);

        return implode("\n", $lines);
    }

    public function testMissingTimerAndNoDispatchWarn(): void
    {
        $out = $this->render();
        $this->assertStringContainsString('⚠️ scheduler  pablo-dispatch.timer is not installed', $out);
        $this->assertStringContainsString('pablo system:install', $out);
        $this->assertStringContainsString('⚠️ dispatch   no dispatch recorded yet', $out);
        $this->assertSame([], $this->runner->probeCalls, 'no systemctl call for a timer that is not there');
    }

    public function testActiveTimerAndRecentDispatchAreGreen(): void
    {
        $this->installTimer();
        $this->runner->onProbe = static fn (array $argv): ProbeResult => new ProbeResult(0, '');
        $this->health->finishDispatch(self::NOW - 4 * 60, self::NOW - 4 * 60 + 3, DispatchHealth::OUTCOME_OK);

        $out = $this->render();
        $this->assertStringContainsString('✅ scheduler  pablo-dispatch.timer active', $out);
        $this->assertStringContainsString('✅ dispatch   last dispatch 4 min ago (ok)', $out);
        $this->assertSame([['systemctl', '--user', 'is-active', '--quiet', 'pablo-dispatch.timer']], $this->runner->probeCalls);
    }

    public function testInactiveTimerAndStaleDispatchWarn(): void
    {
        $this->installTimer();
        $this->runner->onProbe = static fn (array $argv): ProbeResult => new ProbeResult(3, 'inactive');
        $this->health->finishDispatch(self::NOW - 40 * 60, self::NOW - 40 * 60 + 3, DispatchHealth::OUTCOME_OK);

        $out = $this->render();
        $this->assertStringContainsString('⚠️ scheduler  pablo-dispatch.timer is installed but not active', $out);
        $this->assertStringContainsString('⚠️ dispatch   last dispatch 40 min ago (ok)', $out);
    }

    public function testATimerOnAnotherTickThanTheConfigSaysToReinstall(): void
    {
        $this->installTimer(5);
        $this->runner->onProbe = static fn (array $argv): ProbeResult => new ProbeResult(0, '');
        $this->health->finishDispatch(self::NOW - 60, self::NOW - 59, DispatchHealth::OUTCOME_OK);

        $out = $this->render();
        $this->assertStringContainsString('✅ scheduler  pablo-dispatch.timer active, every 5 minutes', $out);
        $this->assertStringContainsString('⚠️ scheduler  pablo-dispatch.timer runs every 5 minutes, dispatch.tick_minutes says 1 minute', $out);

        file_put_contents($this->tmp.'/config.yaml', "dispatch:\n  tick_minutes: 5\n");
        $this->assertStringNotContainsString('dispatch.tick_minutes says', $this->render());
    }

    /** Three missed ticks, but never under 15 minutes: one run may take a few. */
    public function testStaleThresholdFollowsTheTick(): void
    {
        $this->assertSame(15, SchedulerHealth::staleAfterMinutes(1));
        $this->assertSame(15, SchedulerHealth::staleAfterMinutes(5));
        $this->assertSame(90, SchedulerHealth::staleAfterMinutes(30));

        $this->installTimer(30);
        file_put_contents($this->tmp.'/config.yaml', "dispatch:\n  tick_minutes: 30\n");
        $this->runner->onProbe = static fn (array $argv): ProbeResult => new ProbeResult(0, '');
        $this->health->finishDispatch(self::NOW - 40 * 60, self::NOW - 40 * 60 + 3, DispatchHealth::OUTCOME_OK);

        $this->assertStringContainsString('✅ dispatch   last dispatch 40 min ago (ok)', $this->render());
    }

    public function testPreflightFailureIsShown(): void
    {
        $this->health->finishDispatch(self::NOW - 60, self::NOW - 59, DispatchHealth::OUTCOME_PREFLIGHT_FAILED, 'gh: not logged in');

        $this->assertStringContainsString('⚠️ dispatch   last dispatch 1 min ago (preflight-failed): gh: not logged in', $this->render());
    }

    public function testFailingJobsAndPollCheckFailuresAreListed(): void
    {
        $this->health->recordFailure('acme', 'poll', self::NOW - 600, 'first');
        $this->health->recordFailure('acme', 'poll', self::NOW - 300, 'first');
        $this->health->recordFailure('acme', 'poll', self::NOW - 60, 'gh: HTTP 502');
        $this->health->recordSuccess('beta', 'poll', self::NOW - 60, 2);

        $out = $this->render(['acme' => $this->cfg('acme'), 'beta' => $this->cfg('beta')]);
        $this->assertMatchesRegularExpression('/⚠️ job +acme poll failing since \d{4}-\d\d-\d\d \d\d:\d\d \S+ \(3×\): gh: HTTP 502/u', $out);
        // 3 failures: 20 min, capped by the 10-min poll interval.
        $this->assertStringContainsString('→ backing off: next try after', $out);
        $this->assertStringContainsString('beta poll: 2 task check(s) failed on the last run', $out);
    }

    public function testUnsupportedPlatformOnlyWarns(): void
    {
        $lines = (new SchedulerHealth(new SchedulerFactory($this->runner, 'Plan9'), $this->health))->lines([], self::NOW);

        $this->assertStringContainsString('⚠️ scheduler  unsupported platform: Plan9', implode("\n", $lines));
    }
}
