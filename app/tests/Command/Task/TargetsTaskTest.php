<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Task;

use Pablo\Command\Task\PrecommitCheckCommand;
use Pablo\Command\Task\RelaunchCommand;
use Pablo\Command\Task\RetriggerCiCommand;
use Pablo\Command\Task\SkipCiCommand;
use Pablo\Command\Task\StateCommand;
use Pablo\Command\Task\TaskCommand;
use Pablo\Command\Task\WaitingCommand;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Tests\Command\CommandTestBed;
use Symfony\Component\Console\Command\Command;

/**
 * The shared `[<branch>] --project --worktree` targeting of task:* commands
 * (TargetsTask), exercised from outside any worktree.
 */
final class TargetsTaskTest extends CommandTestBed
{
    protected function setUp(): void
    {
        parent::setUp();
        chdir($this->tmp);
    }

    public function testInfoByBranch(): void
    {
        $tester = $this->runCommand($this->info(), ['branch' => 'wk-45']);
        $this->assertSame(0, $tester->getStatusCode());
        $data = json_decode($tester->getDisplay(), true);
        $this->assertSame('wk-45', $data['branch']);
        $this->assertSame('wallet-kit', $data['project']);
    }

    public function testInfoWithoutBranchOutsideWorktreeFails(): void
    {
        $tester = $this->runCommand($this->info(), [], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not a git worktree', $tester->getErrorOutput());
    }

    public function testInfoWithoutBranchUsesCwd(): void
    {
        chdir($this->wt);
        $tester = $this->runCommand($this->info());
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('wk-45', json_decode($tester->getDisplay(), true)['branch']);
    }

    public function testStateTakesBranchAfterState(): void
    {
        $tester = $this->runCommand(
            new StateCommand($this->stateChanger(), $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents),
            ['state' => 'request-changes', 'branch' => 'wk-45', '--project' => 'wallet-kit'],
        );
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(State::RequestChanges, $this->getTask()->state);
    }

    public function testPrecommitCheckByBranch(): void
    {
        $tester = $this->runCommand(new PrecommitCheckCommand($this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['branch' => 'wk-45']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertTrue(json_decode($tester->getDisplay(), true)['allowed']);
    }

    public function testWaitingByBranch(): void
    {
        $tester = $this->runCommand(new WaitingCommand($this->stateMachine, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['branch' => 'wk-45']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(State::Waiting, $this->getTask()->state);
    }

    public function testRelaunchByBranch(): void
    {
        $tester = $this->runCommand(
            new RelaunchCommand($this->stateMachine, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents),
            ['branch' => 'wk-45', '--only' => 'task-analyst'],
        );
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('wk-45: re-launched task-analyst', $tester->getDisplay());
    }

    public function testRetriggerCiByBranch(): void
    {
        $tester = $this->runCommand(new RetriggerCiCommand($this->gh, $this->repoSlug, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['branch' => 'wk-45']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('wk-45: re-triggered CI', $tester->getDisplay());
    }

    public function testSkipCiByBranch(): void
    {
        $task = $this->getTask();
        $task->state = State::CiRed;
        $this->store->save($task);
        $tester = $this->runCommand(new SkipCiCommand($this->stateChanger(), $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents), ['branch' => 'wk-45']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertNotSame(State::CiRed, $this->getTask()->state);
    }

    public function testWorktreeOptionStillWorksEverywhere(): void
    {
        $tester = $this->runCommand($this->info(), ['--worktree' => $this->wt]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('wk-45', json_decode($tester->getDisplay(), true)['branch']);
    }

    public function testWorktreeAndBranchAreExclusive(): void
    {
        $tester = $this->runCommand($this->info(), ['branch' => 'wk-45', '--worktree' => $this->wt], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--worktree cannot be combined', $tester->getErrorOutput());
    }

    public function testProjectNeedsBranch(): void
    {
        $tester = $this->runCommand($this->info(), ['--project' => 'wallet-kit'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--project needs a branch: pablo task:info <branch>', $tester->getErrorOutput());
    }

    public function testUnknownBranchInProject(): void
    {
        $tester = $this->runCommand($this->info(), ['branch' => 'wk-45', '--project' => 'nope'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("no PABLO task 'wk-45' for project 'nope'", $tester->getErrorOutput());
    }

    public function testAmbiguousBranchListsProjects(): void
    {
        $this->store->save(new Task('other-kit', 'wk-45', $this->tmp.'/wt2/wk-45', State::InProgress));
        $tester = $this->runCommand($this->info(), ['branch' => 'wk-45'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("'wk-45' exists in several projects (other-kit, wallet-kit) — add --project <name>", $tester->getErrorOutput());

        $tester = $this->runCommand($this->info(), ['branch' => 'wk-45', '--project' => 'wallet-kit']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('wallet-kit', json_decode($tester->getDisplay(), true)['project']);
    }

    private function info(): Command
    {
        return new TaskCommand($this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents);
    }
}
