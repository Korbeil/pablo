<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Agent;

use Pablo\Agents\AgentRuns;
use Pablo\Agents\DetachedRun;
use Pablo\Command\Agent\ListCommand;
use Pablo\Command\Agent\StopCommand;
use Pablo\Command\Internal\WatchAgentCommand;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Tests\Command\CommandTestBed;
use Pablo\Tests\FakeAnalytics;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * agent:list, agent:stop and the watcher's timeout, over FakeAgents: no
 * real run is listed, waited on or stopped.
 */
final class AgentCommandsTest extends CommandTestBed
{
    private AgentRuns $runs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runs = new AgentRuns($this->store, $this->agentLaunchers, $this->git, $this->time, fn (?string $ade) => $this->agents);
        $task = $this->getTask();
        $task->agentLaunches[Agent::TaskAnalyst->value] = (new AgentLaunch(Agent::TaskAnalyst, $this->time->utcnow(), 1))
            ->withHandle('term_1', 'orca', 'opencode');
        $this->store->save($task);
        $this->agents->running = ['term_1' => true];
        $this->agents->detached = [new DetachedRun('pid:42', $this->wt, 'rebase-conflict-resolver', null, 'bg', 'opencode', 'ses_42')];
    }

    private function list(): ListCommand
    {
        return new ListCommand($this->runs, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents);
    }

    private function stop(): StopCommand
    {
        return new StopCommand($this->runs, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents);
    }

    public function testListShowsEveryRunAtWork(): void
    {
        $tester = $this->runCommand($this->list());

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('wallet-kit/wk-45', $display);
        $this->assertStringContainsString('term_1', $display);
        $this->assertStringContainsString('rebase-conflict-resolver', $display);
        $this->assertStringContainsString('opencode -s ses_42', $display);
    }

    public function testListAsJson(): void
    {
        $tester = $this->runCommand($this->list(), ['--format' => 'json', '--project' => 'wallet-kit']);

        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame(['runs'], array_keys($payload));
        $rows = $payload['runs'];
        $this->assertIsArray($rows);
        $this->assertSame(['pid:42', 'term_1'], array_column($rows, 'handle'));
        $this->assertSame(['bg', 'orca'], array_column($rows, 'ade'));
    }

    public function testListRejectsAnUnknownFormat(): void
    {
        $tester = $this->runCommand($this->list(), ['--format' => 'yaml']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("invalid --format 'yaml'", $tester->getDisplay());
    }

    public function testListWithNothingAtWork(): void
    {
        $this->agents->running = [];
        $this->agents->detached = [];

        $this->assertStringContainsString('no PABLO agent run at work', $this->runCommand($this->list())->getDisplay());
    }

    public function testStopNeedsYesWhenNotInteractive(): void
    {
        $tester = $this->runCommand($this->stop(), ['branch' => 'wk-45'], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame([], $this->agents->stops);
    }

    public function testStopAsksFirst(): void
    {
        $tester = new CommandTester($this->stop());
        $tester->setInputs(['n']);
        $tester->execute(['branch' => 'wk-45']);

        $this->assertStringContainsString('nothing stopped', $tester->getDisplay());
        $this->assertSame([], $this->agents->stops);
    }

    public function testStopOneAgent(): void
    {
        $tester = $this->runCommand($this->stop(), ['branch' => 'wk-45', '--agent' => 'task-analyst', '--yes' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(['term_1'], $this->agents->stops);
        $this->assertStringContainsString('✔ task-analyst: stopped term_1', $tester->getDisplay());
        $this->assertSame(AgentLaunch::STOPPED, $this->getTask()->agentLaunches['task-analyst']->stopReason);
    }

    public function testStopEveryRunOfTheTask(): void
    {
        $tester = $this->runCommand($this->stop(), ['branch' => 'wk-45', '--project' => 'wallet-kit', '--yes' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(['pid:42', 'term_1'], $this->agents->stops);
    }

    public function testStopTheTaskOfAWorktree(): void
    {
        $tester = $this->runCommand($this->stop(), ['--worktree' => $this->wt, '--agent' => 'task-analyst', '--yes' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(['term_1'], $this->agents->stops);
    }

    public function testAFailedStopFails(): void
    {
        $this->agents->canStop = false;

        $tester = $this->runCommand($this->stop(), ['branch' => 'wk-45', '--agent' => 'task-analyst', '--yes' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('✘ task-analyst: cannot stop term_1', $tester->getDisplay());
    }

    public function testStopAnUnknownTask(): void
    {
        $tester = $this->runCommand($this->stop(), ['branch' => 'nope', '--yes' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame([], $this->agents->stops);
    }

    private function watch(FakeAnalytics $analytics, string $agent = 'task-analyst'): CommandTester
    {
        $command = new WatchAgentCommand($this->gh, $this->repoSlug, $this->time, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents, new \Pablo\Agents\Runtime\OpenCode\OpenCodeUsage($this->runner), $analytics, runs: $this->runs, globalConfig: $this->globalConfig);

        return $this->runCommand($command, [
            '--backend' => 'orca',
            '--project' => 'wallet-kit',
            '--branch' => 'wk-45',
            '--handle' => 'term_1',
            '--agent' => $agent,
        ]);
    }

    public function testTheWatcherStopsARunStillAtWorkWhenTheTimeoutExpires(): void
    {
        $this->runner->onRun = static fn (): string => '';
        $analytics = new FakeAnalytics();

        $this->watch($analytics);

        $this->assertSame(['term_1'], $this->agents->stops);
        $launch = $this->getTask()->agentLaunches['task-analyst'];
        $this->assertSame(AgentLaunch::TIMED_OUT, $launch->stopReason);
        $this->assertTrue($launch->reported);
        $this->assertCount(1, $analytics->runsFinished, 'reported once');
        $this->assertSame($launch->finishedAt, $analytics->runsFinished[0]->finishedAt);
    }

    public function testTheWatcherLeavesAFinishedRunAlone(): void
    {
        $this->runner->onRun = static fn (): string => '';
        $this->agents->running = [];

        $this->watch(new FakeAnalytics());

        $this->assertSame([], $this->agents->stops);
        $launch = $this->getTask()->agentLaunches['task-analyst'];
        $this->assertNull($launch->stopReason);
        $this->assertTrue($launch->reported);
    }

    public function testTheWatcherNeverTimesOutTheStartupScript(): void
    {
        $this->runner->onRun = static fn (): string => '';

        $this->agents->runsEndOnWait = true;
        file_put_contents($this->configPath, "\nagents:\n  timeout_minutes: 5\n", \FILE_APPEND);

        $this->watch(new FakeAnalytics(), 'startup-script');

        $this->assertSame([], $this->agents->stops);
        $this->assertSame([['term_1', WatchAgentCommand::UNBOUNDED_WAIT_S]], $this->agents->waits);
    }
}
