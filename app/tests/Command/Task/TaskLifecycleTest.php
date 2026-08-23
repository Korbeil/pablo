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
use Pablo\Domain\Task;
use Pablo\Tests\Command\CommandTestBed;
use Pablo\Tests\FakeAnalytics;

final class TaskLifecycleTest extends CommandTestBed
{
    public function testStateForcesWithSharedHandler(): void
    {
        $this->runCommand(new StateCommand($this->stateMachine, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['state' => 'request-changes']);
        $this->assertSame(['pr-feedback'], $this->agents->launch);
        $this->assertSame([7], $this->gh->drafts);
        $this->assertSame(State::RequestChanges, $this->getTask()->state);
    }

    public function testStateNoTriggerSkipsActions(): void
    {
        $this->runCommand(new StateCommand($this->stateMachine, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['state' => 'request-changes', '--no-trigger' => true]);
        $this->assertSame([], $this->agents->launch);
        $this->assertSame(State::RequestChanges, $this->getTask()->state);
    }

    public function testStateOutsideWorktreeFails(): void
    {
        chdir($this->tmp);
        $tester = $this->runCommand(new StateCommand($this->stateMachine, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['state' => 'draft']);
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function testStateWithWorktreeOptionFromOutsideWorktree(): void
    {
        chdir($this->tmp);
        $tester = $this->runCommand(new StateCommand($this->stateMachine, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), [
            'state' => 'request-changes',
            '--worktree' => $this->wt,
        ]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(State::RequestChanges, $this->getTask()->state);
    }

    public function testWaitingToggleRoundtrip(): void
    {
        $this->runCommand(new WaitingCommand($this->stateMachine, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents));
        $this->assertSame(State::Waiting, $this->getTask()->state);
        $this->runCommand(new WaitingCommand($this->stateMachine, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents));
        $task = $this->getTask();
        $this->assertSame(State::InProgress, $task->state);
        $this->assertTrue($task->taskAnalystRan);
    }

    public function testWaitingRefusedFromRequestChanges(): void
    {
        $task = $this->getTask();
        $task->state = State::RequestChanges;
        $this->store->save($task);
        $tester = $this->runCommand(new WaitingCommand($this->stateMachine, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents));
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function testCloseRefusesWhileAgentsActive(): void
    {
        $this->agents->active = [new SessionInfo('a', 'running')];
        $tester = $this->runCommand(new CloseCommand($this->git, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), [], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('agent', $tester->getErrorOutput());
    }

    public function testCloseRemovesWorktreeAndRecord(): void
    {
        $tester = $this->runCommand(new CloseCommand($this->git, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['--yes' => true]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([[$this->wt, 'wk-45']], $this->git->removed);
        $this->assertNull($this->store->get('wallet-kit', 'wk-45'));
    }

    public function testCloseByBranchFromAnywhere(): void
    {
        chdir($this->tmp);
        $tester = $this->runCommand(new CloseCommand($this->git, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['branch' => 'wk-45']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([[$this->wt, 'wk-45']], $this->git->removed);
        $this->assertNull($this->store->get('wallet-kit', 'wk-45'));
        $this->assertStringContainsString('closed wk-45 (wallet-kit)', $tester->getDisplay());
    }

    public function testCloseAmbiguousBranchRequiresProject(): void
    {
        mkdir($this->tmp.'/repo2', 0o777, true);
        file_put_contents($this->projectsDir.'/other-kit.yaml', <<<YAML
            name: other-kit
            type: open-source
            repo:
              path: {$this->tmp}/repo2
              primary_branch: main
            worktrees_root: {$this->tmp}/wt2
            issue_tracker:
              provider: github
              identity: octocat
              project_key: OK
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
            YAML);
        $this->store->save(new Task('other-kit', 'wk-45', $this->tmp.'/wt2/wk-45', State::InProgress));

        $close = fn (): CloseCommand => new CloseCommand($this->git, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents);

        chdir($this->tmp);
        $tester = $this->runCommand($close(), ['branch' => 'wk-45'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('several projects', $tester->getErrorOutput());
        $this->assertStringContainsString('other-kit', $tester->getErrorOutput());
        $this->assertStringContainsString('wallet-kit', $tester->getErrorOutput());

        $tester = $this->runCommand($close(), ['branch' => 'wk-45', '--project' => 'wallet-kit']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertNull($this->store->get('wallet-kit', 'wk-45'));
    }

    public function testCloseUnknownBranchFails(): void
    {
        chdir($this->tmp);
        $tester = $this->runCommand(new CloseCommand($this->git, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['branch' => 'nope'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("no PABLO task named 'nope'", $tester->getErrorOutput());
    }

    public function testCloseOrphanedRecordPrunesStaleWorktree(): void
    {
        (new \Symfony\Component\Process\Process(['rm', '-rf', $this->wt]))->run();
        chdir($this->tmp);
        $tester = $this->runCommand(new CloseCommand($this->git, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['branch' => 'wk-45']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertNull($this->store->get('wallet-kit', 'wk-45'));
        $this->assertStringContainsString('stale worktree pruned', $tester->getDisplay());
    }

    public function testPrecommitCheckAllowed(): void
    {
        $tester = $this->runCommand(new PrecommitCheckCommand($this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents));
        $this->assertSame(0, $tester->getStatusCode());
        $data = json_decode($tester->getDisplay(), true);
        $this->assertSame([
            'project' => 'wallet-kit',
            'branch' => 'wk-45',
            'state' => 'in-progress',
            'allowed' => true,
            'pr_description_locale' => 'en',
        ], $data);
    }

    public function testPrecommitCheckDisallowed(): void
    {
        $task = $this->getTask();
        $task->state = State::Waiting;
        $this->store->save($task);
        $tester = $this->runCommand(new PrecommitCheckCommand($this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents));
        $this->assertSame(0, $tester->getStatusCode());
        $data = json_decode($tester->getDisplay(), true);
        $this->assertFalse($data['allowed']);
    }

    public function testPrecommitCheckRefusesNonTaskDir(): void
    {
        chdir($this->tmp);
        $tester = $this->runCommand(new PrecommitCheckCommand($this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents));
        $this->assertSame(2, $tester->getStatusCode());
    }

    public function testPrecommitCheckWithWorktreeOptionFromOutsideWorktree(): void
    {
        chdir($this->tmp);
        $tester = $this->runCommand(new PrecommitCheckCommand($this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['--worktree' => $this->wt]);
        $this->assertSame(0, $tester->getStatusCode());
        $data = json_decode($tester->getDisplay(), true);
        $this->assertSame('wk-45', $data['branch']);
        $this->assertTrue($data['allowed']);
    }

    public function testPrecommitCheckWorktreeOptionUnknownPath(): void
    {
        chdir($this->tmp);
        $tester = $this->runCommand(new PrecommitCheckCommand($this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['--worktree' => $this->tmp.'/nowhere']);
        $this->assertSame(2, $tester->getStatusCode());
    }

    public function testTaskCurrentDumpsRecord(): void
    {
        $tester = $this->runCommand(new TaskCommand($this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['current' => 'current']);
        $data = json_decode($tester->getDisplay(), true);
        $this->assertSame('wk-45', $data['branch']);
        $this->assertSame($this->tmp.'/repo', $data['repo_path']);
    }

    public function testWatchAgentDraftsPrWhenStateMatches(): void
    {
        $task = $this->getTask();
        $task->state = State::RequestChanges;
        $this->store->save($task);
        $tester = $this->runCommand(new WatchAgentCommand($this->gh, $this->repoSlug, $this->time, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents, new FakeAnalytics(), new \Pablo\Analytics\OpenCodeUsage($this->runner)), [
            '--project' => 'wallet-kit',
            '--branch' => 'wk-45',
            '--handle' => 't1',
            '--then' => 'pr-draft',
            '--expect-state' => 'request-changes',
        ]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([7], $this->gh->drafts);
    }

    public function testWatchAgentSkipsWhenStateMovedOn(): void
    {
        $tester = $this->runCommand(new WatchAgentCommand($this->gh, $this->repoSlug, $this->time, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents, new FakeAnalytics(), new \Pablo\Analytics\OpenCodeUsage($this->runner)), [
            '--project' => 'wallet-kit',
            '--branch' => 'wk-45',
            '--handle' => 't1',
            '--then' => 'pr-draft',
            '--expect-state' => 'request-changes',
        ]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([], $this->gh->drafts);
    }

    public function testWatchAgentStampsFinishedWhenAgentGiven(): void
    {
        $task = $this->getTask();
        $task->agentLaunches['task-analyst'] = new \Pablo\Domain\AgentLaunch(Agent::TaskAnalyst, '2026-08-11T00:00:00+00:00', 1);
        $this->store->save($task);

        $tester = $this->runCommand(new WatchAgentCommand($this->gh, $this->repoSlug, $this->time, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents, new FakeAnalytics(), new \Pablo\Analytics\OpenCodeUsage($this->runner)), [
            '--project' => 'wallet-kit',
            '--branch' => 'wk-45',
            '--handle' => 't1',
            '--agent' => 'task-analyst',
        ]);
        $this->assertSame(0, $tester->getStatusCode());

        $launch = $this->getTask()->agentLaunches['task-analyst'];
        $this->assertNotNull($launch->finishedAt);
        // The watcher reports the run and marks it so the poller's
        // reconciliation sweep never re-emits it.
        $this->assertTrue($launch->reported);
        $this->assertNotNull($launch->runId);
    }

    public function testWatchAgentDoesNotReReportAnAlreadyReportedLaunch(): void
    {
        $task = $this->getTask();
        $task->agentLaunches['task-analyst'] = new \Pablo\Domain\AgentLaunch(
            Agent::TaskAnalyst,
            '2026-08-11T00:00:00+00:00',
            1,
            '2026-08-11T00:05:00+00:00',
            runId: 'seeded-run',
            reported: true,
        );
        $this->store->save($task);

        $analytics = new FakeAnalytics();
        $tester = $this->runCommand(new WatchAgentCommand($this->gh, $this->repoSlug, $this->time, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents, $analytics, new \Pablo\Analytics\OpenCodeUsage($this->runner)), [
            '--project' => 'wallet-kit',
            '--branch' => 'wk-45',
            '--handle' => 't1',
            '--agent' => 'task-analyst',
            '--backend' => 'openchamber',
        ]);
        $this->assertSame(0, $tester->getStatusCode());
        // The sweep got there first: no second event may be emitted.
        $this->assertSame([], $analytics->runsFinished);
        $launch = $this->getTask()->agentLaunches['task-analyst'];
        $this->assertTrue($launch->reported);
        $this->assertSame('seeded-run', $launch->runId);
    }

    public function testLaunchAgentPersistsRunIdOnTheLaunchRecord(): void
    {
        $task = $this->getTask();
        $task->agentLaunches['task-analyst'] = new \Pablo\Domain\AgentLaunch(Agent::TaskAnalyst, '2026-08-11T00:00:00+00:00', 1);
        $this->store->save($task);

        $analytics = new FakeAnalytics();
        $tester = $this->runCommand(new \Pablo\Command\Internal\InternalLaunchAgentCommand($analytics, $this->time, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), [
            '--project' => 'wallet-kit',
            '--branch' => 'wk-45',
            '--worktree' => $this->wt,
            '--agent' => 'task-analyst',
            '--prompt' => 'plan the thing',
            '--backend' => 'orca',
        ]);
        $this->assertSame(0, $tester->getStatusCode());

        // The generated run id is persisted onto the launch record so a
        // sweep-emitted finished event keeps the same id as its start event.
        $runId = $analytics->runsStarted[0]['runId'];
        $this->assertNotSame('', $runId);
        $launch = $this->getTask()->agentLaunches['task-analyst'];
        $this->assertSame($runId, $launch->runId);
        $this->assertFalse($launch->reported);
        // Launch metadata is preserved, not restamped.
        $this->assertSame('2026-08-11T00:00:00+00:00', $launch->launchedAt);
        $this->assertSame(1, $launch->attempts);
    }

    public function testRelaunchFiresBothAndResetsCounters(): void
    {
        $this->writeProjects('/setup.sh');
        $task = $this->getTask();
        $task->state = State::InProgress;
        $task->agentLaunches['task-analyst'] = new \Pablo\Domain\AgentLaunch(Agent::TaskAnalyst, 'x', 3);
        $this->store->save($task);

        $tester = $this->runCommand(new RelaunchCommand($this->stateMachine, $this->time, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents));
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
        $tester = $this->runCommand(new RelaunchCommand($this->stateMachine, $this->time, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['--only' => 'startup-script']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([], $this->agents->launch);
        $this->assertSame(['/setup.sh'], $this->agents->startupScript);
        $this->assertStringContainsString('re-launched startup-script', $tester->getDisplay());
    }

    public function testRelaunchOnlyAnalystSkipsStartup(): void
    {
        $tester = $this->runCommand(new RelaunchCommand($this->stateMachine, $this->time, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['--only' => 'task-analyst']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(['task-analyst'], $this->agents->launch);
        $this->assertSame([], $this->agents->startupScript);
    }

    public function testRelaunchCiRedFiresCiAnalyst(): void
    {
        $task = $this->getTask();
        $task->state = State::CiRed;
        $this->store->save($task);
        $this->runCommand(new RelaunchCommand($this->stateMachine, $this->time, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents));
        $this->assertSame(['ci-analyst'], $this->agents->launch);
        $this->assertSame(1, $this->getTask()->agentLaunches['ci-analyst']->attempts);
    }

    public function testRelaunchRequestChangesFiresPrFeedback(): void
    {
        $task = $this->getTask();
        $task->state = State::RequestChanges;
        $this->store->save($task);
        $this->runCommand(new RelaunchCommand($this->stateMachine, $this->time, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents));
        $this->assertSame(['pr-feedback'], $this->agents->launch);
    }

    public function testRelaunchUnknownLabelPrintsNothing(): void
    {
        $tester = $this->runCommand(new RelaunchCommand($this->stateMachine, $this->time, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['--only' => 'ci-analyst']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('nothing to relaunch', $tester->getDisplay());
    }

    public function testSkipCiFromCiRed(): void
    {
        $task = $this->getTask();
        $task->state = State::CiRed;
        $this->store->save($task);
        $tester = $this->runCommand(new SkipCiCommand($this->stateMachine, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents));
        $this->assertSame(0, $tester->getStatusCode());
        $updated = $this->getTask();
        $this->assertSame(State::WaitingReview, $updated->state);
        $this->assertTrue($updated->ciIgnored);
        $this->assertSame([7], $this->gh->readies);
    }

    public function testSkipCiFromWrongStateFails(): void
    {
        $task = $this->getTask();
        $task->state = State::Draft;
        $this->store->save($task);
        $tester = $this->runCommand(new SkipCiCommand($this->stateMachine, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents));
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function testRetriggerCi(): void
    {
        $tester = $this->runCommand(new RetriggerCiCommand($this->gh, $this->repoSlug, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents));
        $this->assertSame(0, $tester->getStatusCode());
        $out = $tester->getDisplay();
        $this->assertStringContainsString('re-triggered CI', $out);
        $this->assertStringContainsString('42', $out);
        $this->assertStringContainsString('43', $out);
        $this->assertSame([7], $this->gh->drafts);
    }
}
