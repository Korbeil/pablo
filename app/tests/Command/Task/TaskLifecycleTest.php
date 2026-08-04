<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Task;

use Pablo\Agents\SessionInfo;
use Pablo\Command\Internal\WatchAgentCommand;
use Pablo\Command\Task\CloseCommand;
use Pablo\Command\Task\PrecommitCheckCommand;
use Pablo\Command\Task\RelaunchCommand;
use Pablo\Command\Task\RetriggerCiCommand;
use Pablo\Command\Task\SkipCiCommand;
use Pablo\Command\Task\StateCommand;
use Pablo\Command\Task\TaskCommand;
use Pablo\Command\Task\WaitingCommand;
use Pablo\Domain\Agent;
use Pablo\Domain\State;
use Pablo\Tests\Command\CommandTestBed;

final class TaskLifecycleTest extends CommandTestBed
{
    public function testStateForcesWithSharedHandler(): void
    {
        $this->runCommand(new StateCommand($this->store, $this->agents), ['state' => 'request-changes']);
        $this->assertSame(['pr-feedback'], $this->agents->launch);
        $this->assertSame([7], $this->drafts);
        $this->assertSame(State::RequestChanges, $this->getTask()->state);
    }

    public function testStateNoTriggerSkipsActions(): void
    {
        $this->runCommand(new StateCommand($this->store, $this->agents), ['state' => 'request-changes', '--no-trigger' => true]);
        $this->assertSame([], $this->agents->launch);
        $this->assertSame(State::RequestChanges, $this->getTask()->state);
    }

    public function testStateOutsideWorktreeFails(): void
    {
        chdir($this->tmp);
        $tester = $this->runCommand(new StateCommand($this->store, $this->agents), ['state' => 'draft']);
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function testWaitingToggleRoundtrip(): void
    {
        $this->runCommand(new WaitingCommand($this->store, $this->agents));
        $this->assertSame(State::Waiting, $this->getTask()->state);
        $this->runCommand(new WaitingCommand($this->store, $this->agents));
        $task = $this->getTask();
        $this->assertSame(State::InProgress, $task->state);
        $this->assertTrue($task->taskAnalystRan);
    }

    public function testWaitingRefusedFromRequestChanges(): void
    {
        $task = $this->getTask();
        $task->state = State::RequestChanges;
        $this->store->save($task);
        $tester = $this->runCommand(new WaitingCommand($this->store, $this->agents));
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function testCloseRefusesWhileAgentsActive(): void
    {
        $this->agents->active = [new SessionInfo('a', 'running')];
        $tester = $this->runCommand(new CloseCommand($this->store, $this->agents), [], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('agent', $tester->getErrorOutput());
    }

    public function testCloseRemovesWorktreeAndRecord(): void
    {
        $tester = $this->runCommand(new CloseCommand($this->store, $this->agents), ['--yes' => true]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([[$this->wt, 'wk-45']], $this->removed);
        $this->assertNull($this->store->get('wallet-kit', 'wk-45'));
    }

    public function testPrecommitCheckAllowed(): void
    {
        $tester = $this->runCommand(new PrecommitCheckCommand($this->store, $this->agents));
        $this->assertSame(0, $tester->getStatusCode());
        $data = json_decode($tester->getDisplay(), true);
        $this->assertSame([
            'project' => 'wallet-kit',
            'branch' => 'wk-45',
            'state' => 'in-progress',
            'allowed' => true,
        ], $data);
    }

    public function testPrecommitCheckDisallowed(): void
    {
        $task = $this->getTask();
        $task->state = State::Waiting;
        $this->store->save($task);
        $tester = $this->runCommand(new PrecommitCheckCommand($this->store, $this->agents));
        $this->assertSame(0, $tester->getStatusCode());
        $data = json_decode($tester->getDisplay(), true);
        $this->assertFalse($data['allowed']);
    }

    public function testPrecommitCheckRefusesNonTaskDir(): void
    {
        chdir($this->tmp);
        $tester = $this->runCommand(new PrecommitCheckCommand($this->store, $this->agents));
        $this->assertSame(2, $tester->getStatusCode());
    }

    public function testTaskCurrentDumpsRecord(): void
    {
        $tester = $this->runCommand(new TaskCommand($this->store, $this->agents), ['current' => 'current']);
        $data = json_decode($tester->getDisplay(), true);
        $this->assertSame('wk-45', $data['branch']);
        $this->assertSame($this->tmp.'/repo', $data['repo_path']);
    }

    public function testWatchAgentDraftsPrWhenStateMatches(): void
    {
        $task = $this->getTask();
        $task->state = State::RequestChanges;
        $this->store->save($task);
        $tester = $this->runCommand(new WatchAgentCommand($this->store, $this->agents), [
            '--project' => 'wallet-kit',
            '--branch' => 'wk-45',
            '--handle' => 't1',
            '--then' => 'pr-draft',
            '--expect-state' => 'request-changes',
        ]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([7], $this->drafts);
    }

    public function testWatchAgentSkipsWhenStateMovedOn(): void
    {
        $tester = $this->runCommand(new WatchAgentCommand($this->store, $this->agents), [
            '--project' => 'wallet-kit',
            '--branch' => 'wk-45',
            '--handle' => 't1',
            '--then' => 'pr-draft',
            '--expect-state' => 'request-changes',
        ]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([], $this->drafts);
    }

    public function testRelaunchFiresBothAndResetsCounters(): void
    {
        $this->writeProjects('/setup.sh');
        $task = $this->getTask();
        $task->state = State::InProgress;
        $task->agentLaunches['task-analyst'] = new \Pablo\Domain\AgentLaunch(Agent::TaskAnalyst, 'x', 3);
        $this->store->save($task);

        $tester = $this->runCommand(new RelaunchCommand($this->store, $this->agents));
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(['task-analyst'], $this->agents->launch);
        $this->assertSame(['/setup.sh'], $this->agents->startupScript);
        $fresh = $this->getTask();
        $this->assertSame(1, $fresh->agentLaunches['task-analyst']->attempts);
        $this->assertSame(1, $fresh->agentLaunches['startup-script']->attempts);
        $this->assertNotNull($fresh->agentLaunches['task-analyst']->launchedAt);
        $this->assertNotNull($fresh->agentLaunches['startup-script']->launchedAt);
        $this->assertStringContainsString('re-launched task-analyst, startup-script', $tester->getDisplay());
    }

    public function testRelaunchOnlyStartupSkipsAnalyst(): void
    {
        $this->writeProjects('/setup.sh');
        $tester = $this->runCommand(new RelaunchCommand($this->store, $this->agents), ['--only' => 'startup-script']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([], $this->agents->launch);
        $this->assertSame(['/setup.sh'], $this->agents->startupScript);
        $this->assertStringContainsString('re-launched startup-script', $tester->getDisplay());
    }

    public function testRelaunchOnlyAnalystSkipsStartup(): void
    {
        $tester = $this->runCommand(new RelaunchCommand($this->store, $this->agents), ['--only' => 'task-analyst']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(['task-analyst'], $this->agents->launch);
        $this->assertSame([], $this->agents->startupScript);
    }

    public function testRelaunchCiRedFiresCiAnalyst(): void
    {
        $task = $this->getTask();
        $task->state = State::CiRed;
        $this->store->save($task);
        $this->runCommand(new RelaunchCommand($this->store, $this->agents));
        $this->assertSame(['ci-analyst'], $this->agents->launch);
        $this->assertSame(1, $this->getTask()->agentLaunches['ci-analyst']->attempts);
    }

    public function testRelaunchRequestChangesFiresPrFeedback(): void
    {
        $task = $this->getTask();
        $task->state = State::RequestChanges;
        $this->store->save($task);
        $this->runCommand(new RelaunchCommand($this->store, $this->agents));
        $this->assertSame(['pr-feedback'], $this->agents->launch);
    }

    public function testRelaunchUnknownLabelPrintsNothing(): void
    {
        $tester = $this->runCommand(new RelaunchCommand($this->store, $this->agents), ['--only' => 'ci-analyst']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('nothing to relaunch', $tester->getDisplay());
    }

    public function testSkipCiFromCiRed(): void
    {
        $task = $this->getTask();
        $task->state = State::CiRed;
        $this->store->save($task);
        $tester = $this->runCommand(new SkipCiCommand($this->store, $this->agents));
        $this->assertSame(0, $tester->getStatusCode());
        $updated = $this->getTask();
        $this->assertSame(State::WaitingReview, $updated->state);
        $this->assertTrue($updated->ciIgnored);
        $this->assertSame([7], $this->readies);
    }

    public function testSkipCiFromWrongStateFails(): void
    {
        $task = $this->getTask();
        $task->state = State::Draft;
        $this->store->save($task);
        $tester = $this->runCommand(new SkipCiCommand($this->store, $this->agents));
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function testRetriggerCi(): void
    {
        $tester = $this->runCommand(new RetriggerCiCommand($this->store, $this->agents));
        $this->assertSame(0, $tester->getStatusCode());
        $out = $tester->getDisplay();
        $this->assertStringContainsString('re-triggered CI', $out);
        $this->assertStringContainsString('42', $out);
        $this->assertStringContainsString('43', $out);
        $this->assertSame([7], $this->drafts);
    }
}
