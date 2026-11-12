<?php

declare(strict_types=1);

namespace Pablo\Tests\Analytics;

use Pablo\Agents\Runtime\OpenCode\OpenCodeUsage;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Analytics\UsageSnapshot;
use Pablo\Command\Internal\WatchAgentCommand;
use Pablo\Command\Task\CloseCommand;
use Pablo\Command\Task\StartCommand;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\State;
use Pablo\Domain\Task as TaskRecord;
use Pablo\StateMachine\TaskCtx;
use Pablo\Support\Naming;
use Pablo\Support\TaskSummarizer;
use Pablo\Task\TaskStarter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class HooksTest extends \Pablo\Tests\Command\CommandTestBed
{
    public function testEnterStateRecordsTransitionAfterSave(): void
    {
        $task = $this->getTask();
        $this->assertSame(State::InProgress, $task->state);
        $cfg = $this->projectsLoader->loadProjects()['wallet-kit'];
        $ctx = new TaskCtx(task: $task, cfg: $cfg, store: $this->store, agents: $this->agents);

        $this->stateMachine->enterState($ctx, State::Waiting);

        $this->assertCount(1, $this->analytics->transitions);
        $this->assertSame(State::InProgress, $this->analytics->transitions[0]['from']);
        $this->assertSame(State::Waiting, $this->analytics->transitions[0]['task']->state);
    }

    public function testStartRecordsTaskOpenedOnce(): void
    {
        $this->git->allBranchNames = [];
        $starter = new TaskStarter(
            $this->providers,
            new Naming(),
            $this->git,
            $this->stateMachine,
            new TaskSummarizer($this->runner),
            $this->analytics,
            $this->store,
            $this->projectsLoader,
            $this->agents,
        );
        $cmd = new StartCommand(
            $starter,
            $this->projectsLoader,
            $this->store,
            $this->agentLaunchers,
            $this->agents,
        );
        $tester = $this->runCommand($cmd, ['--project' => 'wallet-kit', 'input' => ['fix the thing']]);
        $this->assertSame(0, $tester->getStatusCode());

        $this->assertCount(1, $this->analytics->opened);
        $opened = $this->analytics->opened[0];
        $this->assertSame('wallet-kit', $opened->project);
        $this->assertNotNull($opened->prompt);
        // The initial enterState(InProgress) also flowed through the machine.
        $this->assertCount(1, $this->analytics->transitions);
    }

    public function testCloseRecordsTaskClosed(): void
    {
        $cmd = new CloseCommand(
            $this->taskCloser(),
            $this->store,
            $this->projectsLoader,
            $this->agentLaunchers,
            $this->agents,
        );
        $tester = $this->runCommand($cmd, ['branch' => 'wk-45']);
        $this->assertSame(0, $tester->getStatusCode());

        $this->assertCount(1, $this->analytics->closed);
        $this->assertSame('wk-45', $this->analytics->closed[0]->branch);
        $this->assertNull($this->store->get('wallet-kit', 'wk-45'));
    }

    public function testWatcherRecordsFinishedRunEvenWithoutUsage(): void
    {
        $task = $this->getTask();
        $task->agentLaunches['task-analyst'] = new AgentLaunch(Agent::TaskAnalyst, '2026-08-01T00:00:00+00:00', 2);
        $this->store->save($task);

        // FakeProcessRunner::probe defaults to an empty successful output, so
        // harvest() finds no OpenCode sessions and records null usage —
        // without ever invoking a real binary.
        $cmd = new WatchAgentCommand(
            $this->gh,
            $this->repoSlug,
            $this->time,
            $this->store,
            $this->projectsLoader,
            $this->agentLaunchers,
            $this->agents,
            $this->analytics,
            new OpenCodeUsage($this->runner),
        );
        $input = new ArrayInput([
            '--project' => 'wallet-kit',
            '--branch' => 'wk-45',
            '--handle' => 't1',
            '--agent' => 'task-analyst',
            '--backend' => 'orca',
            '--run-id' => 'r42',
        ]);
        $input->bind($cmd->getDefinition());
        $status = $cmd->run($input, new NullOutput());
        $this->assertSame(0, $status);

        $this->assertCount(1, $this->analytics->runsFinished);
        $record = $this->analytics->runsFinished[0];
        $this->assertSame('r42', $record->runId);
        $this->assertSame('task-analyst', $record->agent);
        $this->assertSame('orca', $record->backend);
        $this->assertSame('2026-08-01T00:00:00+00:00', $record->startedAt);
        $this->assertNotNull($record->durationS);
        $this->assertGreaterThanOrEqual(0, $record->durationS ?? -1);
        $this->assertNull($record->usage);
        // The finishedAt stamping behaviour must be preserved alongside.
        $this->assertNotNull($this->getTask()->agentLaunches['task-analyst']->finishedAt);
    }

    /**
     * internal:run-startup-script watches the script's handle: once it exits
     * the launch is stamped finished (so the poller never re-runs it), with
     * no usage harvested (no LLM call) and no "waiting for feedback" signal.
     */
    public function testWatcherStampsStartupScriptFinishedWithoutUsage(): void
    {
        $task = $this->getTask();
        $task->agentLaunches['startup-script'] = new AgentLaunch(Agent::StartupScript, '2026-08-01T00:00:00+00:00', 1);
        $this->store->save($task);

        $cmd = new WatchAgentCommand(
            $this->gh,
            $this->repoSlug,
            $this->time,
            $this->store,
            $this->projectsLoader,
            $this->agentLaunchers,
            $this->agents,
            $this->analytics,
            new class implements UsageHarvesterInterface {
                public function harvest(string $worktree, string $launchedAt, ?string $fingerprint): ?UsageSnapshot
                {
                    throw new \LogicException('a startup script has no usage to harvest');
                }
            },
        );
        $input = new ArrayInput([
            '--project' => 'wallet-kit',
            '--branch' => 'wk-45',
            '--handle' => 'pid:999999', // dead: the wait returns at once
            '--agent' => 'startup-script',
            '--backend' => 'headless',
        ]);
        $input->bind($cmd->getDefinition());
        $this->assertSame(0, $cmd->run($input, new NullOutput()));

        $launch = $this->getTask()->agentLaunches['startup-script'];
        $this->assertNotNull($launch->finishedAt);
        $this->assertTrue($launch->reported);
        $this->assertCount(1, $this->analytics->runsFinished);
        $this->assertNull($this->analytics->runsFinished[0]->usage);
        $this->assertFalse($this->getTask()->hasFinishedAgent());
    }

    public function testWatcherWithoutAgentRecordsNoRunEvent(): void
    {
        $cmd = new WatchAgentCommand(
            $this->gh,
            $this->repoSlug,
            $this->time,
            $this->store,
            $this->projectsLoader,
            $this->agentLaunchers,
            $this->agents,
            $this->analytics,
            new OpenCodeUsage($this->runner),
        );
        $input = new ArrayInput([
            '--project' => 'wallet-kit',
            '--branch' => 'wk-45',
            '--handle' => 't1',
            '--then' => 'pr-draft',
            '--expect-state' => 'request-changes',
        ]);
        $input->bind($cmd->getDefinition());
        $cmd->run($input, new NullOutput());

        $this->assertSame([], $this->analytics->runsFinished);
        $this->assertInstanceOf(TaskRecord::class, $this->getTask());
    }
}
