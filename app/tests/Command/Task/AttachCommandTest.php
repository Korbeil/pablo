<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Task;

use Pablo\Command\Task\AttachCommand;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Tests\Command\CommandTestBed;

final class AttachCommandTest extends CommandTestBed
{
    private string $wt77;

    protected function setUp(): void
    {
        parent::setUp();
        // A second, PABLO-foreign worktree (the pre-saved wk-45 task record
        // from the bed keeps $this->wt already attached).
        $this->wt77 = $this->tmp.'/wt/wk-77';
        (new \Symfony\Component\Process\Process(['git', 'init', '-q', $this->wt77]))->run();
        chdir($this->wt77);
        $this->fakeGit();
    }

    private function fakeGit(?string $branch = 'wk-77', ?string $toplevel = null): void
    {
        $this->git->gitHandler = fn (array $args): string => match (true) {
            ['rev-parse', '--show-toplevel'] === $args => $toplevel ?? $this->wt77,
            ['rev-parse', '--path-format=absolute', '--git-common-dir'] === $args => $this->tmp.'/repo/.git',
            ['rev-parse', '--abbrev-ref', 'HEAD'] === $args => $branch ?? '',
            default => '',
        };
    }

    private function command(): AttachCommand
    {
        return new AttachCommand(
            new \Pablo\Task\TaskAttacher($this->git, $this->stateMachine, $this->analytics, $this->store, $this->projectsLoader, $this->agents),
            $this->store,
            $this->projectsLoader,
            $this->agentLaunchers,
            $this->agents,
        );
    }

    public function testAttachesWithStateAndPureRecordWrite(): void
    {
        $tester = $this->runCommand($this->command(), ['state' => 'draft']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString("attached {$this->wt77} as task wallet-kit/wk-77 (state draft)", $tester->getDisplay());
        $task = $this->store->get('wallet-kit', 'wk-77');
        $this->assertNotNull($task);
        $this->assertSame(State::Draft, $task->state);
        $this->assertSame($this->wt77, $task->worktreePath);
        // Pure adopt: no on-enter side effects, not even the display cache.
        $this->assertFalse($task->taskAnalystRan);
        $this->assertNull($task->displayCache->at);
        $this->assertSame([], $this->agents->launch);
    }

    public function testAttachRecordsTheOpenEvent(): void
    {
        $this->runCommand($this->command(), ['state' => 'waiting']);
        $task = $this->store->get('wallet-kit', 'wk-77');
        $this->assertNotNull($task);
        // waiting's on-enter still records the resume point (trigger:false
        // only suppresses launch-style side effects).
        $this->assertSame(State::Waiting, $task->state);
        $this->assertSame(State::InProgress, $task->stateBeforeWaiting);
        $this->assertCount(1, $this->analytics->opened);
    }

    public function testRejectsInvalidState(): void
    {
        $tester = $this->runCommand($this->command(), ['state' => 'bogus'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("unknown state 'bogus'", $tester->getErrorOutput());
        $this->assertNull($this->store->get('wallet-kit', 'wk-77'));
    }

    public function testRejectsMainCheckout(): void
    {
        $this->git->gitHandler = fn (array $args): string => match (true) {
            ['rev-parse', '--show-toplevel'] === $args => $this->tmp.'/repo',
            ['rev-parse', '--path-format=absolute', '--git-common-dir'] === $args => $this->tmp.'/repo/.git',
            ['rev-parse', '--abbrev-ref', 'HEAD'] === $args => 'wk-77',
            default => '',
        };
        $tester = $this->runCommand($this->command(), ['state' => 'draft'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('main checkout', $tester->getErrorOutput());
    }

    public function testRejectsAlreadyAttachedWorktree(): void
    {
        chdir($this->wt);
        $this->fakeGit(branch: 'wk-45', toplevel: $this->wt);
        $tester = $this->runCommand($this->command(), ['state' => 'draft'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('already attached', $tester->getErrorOutput());
    }

    public function testRejectsWhenRecordAlreadyExists(): void
    {
        $existing = new Task('wallet-kit', 'wk-77', '/elsewhere/wk-77', State::InProgress);
        $this->store->save($existing);
        $tester = $this->runCommand($this->command(), ['state' => 'draft'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getErrorOutput());
    }

    public function testRejectsPrimaryBranch(): void
    {
        $this->fakeGit(branch: 'main');
        $tester = $this->runCommand($this->command(), ['state' => 'draft'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("branch main is the project's primary branch", $tester->getErrorOutput());
    }

    public function testRejectsDetachedHead(): void
    {
        $this->fakeGit(branch: 'HEAD');
        $tester = $this->runCommand($this->command(), ['state' => 'draft'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('detached-HEAD', $tester->getErrorOutput());
    }

    public function testRejectsUnknownProject(): void
    {
        $tester = $this->runCommand($this->command(), ['state' => 'draft', '--project' => 'nope'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("unknown project 'nope'", $tester->getErrorOutput());
    }

    public function testRejectsRepoWithoutMatchingProject(): void
    {
        $this->git->gitHandler = fn (array $args): string => match (true) {
            ['rev-parse', '--show-toplevel'] === $args => $this->wt77,
            ['rev-parse', '--path-format=absolute', '--git-common-dir'] === $args => $this->tmp.'/wt/.git',
            ['rev-parse', '--abbrev-ref', 'HEAD'] === $args => 'wk-77',
            default => '',
        };
        $tester = $this->runCommand($this->command(), ['state' => 'draft'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('no configured project matches', $tester->getErrorOutput());
    }

    public function testProjectOptionOverridesAutoDetection(): void
    {
        $this->git->gitHandler = fn (array $args): string => match (true) {
            ['rev-parse', '--show-toplevel'] === $args => $this->wt77,
            ['rev-parse', '--path-format=absolute', '--git-common-dir'] === $args => $this->tmp.'/wt/.git',
            ['rev-parse', '--abbrev-ref', 'HEAD'] === $args => 'wk-77',
            default => '',
        };
        $tester = $this->runCommand($this->command(), ['state' => 'draft', '--project' => 'wallet-kit']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertNotNull($this->store->get('wallet-kit', 'wk-77'));
    }

    public function testRejectsOutsideGitWorktree(): void
    {
        $this->git->gitThrows = 'fatal: not a git repository';
        $tester = $this->runCommand($this->command(), ['state' => 'draft'], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not inside a git worktree', $tester->getErrorOutput());
    }
}
