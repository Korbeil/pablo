<?php

declare(strict_types=1);

namespace Pablo\Tests\Command;

use Pablo\Agents\AgentTemplates;
use Pablo\Command\Command;
use Pablo\Command\Report\StatsCommand;
use Pablo\Command\Report\TasksCommand;
use Pablo\Command\Sync\RebaseLogCommand;
use Pablo\Command\Sync\SyncCommand;
use Pablo\Command\System\GenerateAgentsCommand;
use Pablo\Command\System\InstallCommand;
use Pablo\Command\System\ProjectRemoveCommand;
use Pablo\Command\Task\AttachCommand;
use Pablo\Command\Task\CloseCommand;
use Pablo\Command\Task\PrecommitCheckCommand;
use Pablo\Command\Task\RelaunchCommand;
use Pablo\Command\Task\RetriggerCiCommand;
use Pablo\Command\Task\SkipCiCommand;
use Pablo\Command\Task\StateCommand;
use Pablo\Command\Task\TaskCommand;
use Pablo\Command\Task\WaitingCommand;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Install\Scheduler\SchedulerFactory;
use Pablo\Provider\Git\Sync;
use Pablo\Task\TaskAttacher;
use Symfony\Component\Console\Tester\CommandCompletionTester;

/**
 * Shell completion (`pablo completion`): the values each command suggests,
 * read from the bed's task records and project files only.
 */
final class CompletionTest extends CommandTestBed
{
    protected function setUp(): void
    {
        parent::setUp();
        file_put_contents($this->projectsDir.'/api.yaml', "name: api\n");
        $this->store->save(new Task('api', 'api-3', $this->tmp.'/wt/api-3', State::Draft));
        $this->store->save(new Task('api', 'wk-45', $this->tmp.'/wt/api-wk-45', State::Draft));
    }

    private function closeCommand(): CloseCommand
    {
        return new CloseCommand($this->taskCloser(), $this->store, $this->projectsLoader, $this->agents);
    }

    public function testTaskCloseSuggestsTheBranchesOfEveryProject(): void
    {
        $tester = new CommandCompletionTester($this->closeCommand());

        $this->assertSame(['api-3', 'wk-45'], $tester->complete(['']));
    }

    public function testTaskCloseNarrowsTheBranchesToTheGivenProject(): void
    {
        $tester = new CommandCompletionTester($this->closeCommand());

        $this->assertSame(['wk-45'], $tester->complete(['--project', 'wallet-kit', '']));
        $this->assertSame(['api-3', 'wk-45'], $tester->complete(['--project', 'api', '']));
    }

    public function testProjectOptionsAndArgumentsSuggestTheConfiguredProjects(): void
    {
        $expected = ['api', 'wallet-kit'];
        $this->assertSame($expected, (new CommandCompletionTester($this->closeCommand()))->complete(['--project', '']));

        $sync = new Sync($this->git, $this->gh, $this->repoSlug, $this->time, $this->runtimes);
        $this->assertSame($expected, (new CommandCompletionTester(new SyncCommand($sync, new \Pablo\Dispatch\DispatchHealth(), $this->store, $this->projectsLoader, $this->agents)))->complete(['']));
        $this->assertSame($expected, (new CommandCompletionTester(new RebaseLogCommand($sync, $this->store, $this->projectsLoader)))->complete(['']));
        $this->assertSame($expected, (new CommandCompletionTester(new ProjectRemoveCommand($this->store, $this->projectsLoader)))->complete(['']));
    }

    public function testFormatOptionsSuggestTheirFormats(): void
    {
        $sync = new Sync($this->git, $this->gh, $this->repoSlug, $this->time, $this->runtimes);
        $this->assertSame(['table', 'json'], (new CommandCompletionTester(new SyncCommand($sync, new \Pablo\Dispatch\DispatchHealth(), $this->store, $this->projectsLoader, $this->agents)))->complete(['--format', '']));
        $this->assertSame(['table', 'json'], (new CommandCompletionTester(new RebaseLogCommand($sync, $this->store, $this->projectsLoader)))->complete(['--format', '']));
    }

    public function testStateArgumentsSuggestEveryState(): void
    {
        $states = array_map(static fn (State $s): string => $s->value, State::cases());

        $state = new StateCommand($this->stateChanger(), $this->store, $this->projectsLoader, $this->agents);
        $this->assertSame($states, (new CommandCompletionTester($state))->complete(['']));

        $attach = new AttachCommand(
            new TaskAttacher($this->git, $this->stateMachine, $this->analytics, $this->store, $this->projectsLoader, $this->agents),
            $this->store,
            $this->projectsLoader,
        );
        $this->assertSame($states, (new CommandCompletionTester($attach))->complete(['']));
        $this->assertSame(['api', 'wallet-kit'], (new CommandCompletionTester($attach))->complete(['draft', '--project', '']));
    }

    /** @return iterable<string, array{\Closure(self): Command, list<string>}> every command using TargetsTask, with the arguments before <branch> */
    public static function taskTargetCommands(): iterable
    {
        yield 'task:waiting' => [static fn (self $t): Command => new WaitingCommand($t->stateMachine, $t->store, $t->projectsLoader, $t->agents), []];
        yield 'task:state' => [static fn (self $t): Command => new StateCommand($t->stateChanger(), $t->store, $t->projectsLoader, $t->agents), ['draft']];
        yield 'task:relaunch' => [static fn (self $t): Command => new RelaunchCommand($t->stateMachine, $t->ades, $t->store, $t->projectsLoader, $t->agents), []];
        yield 'task:retrigger-ci' => [static fn (self $t): Command => new RetriggerCiCommand($t->gh, $t->repoSlug, $t->store, $t->projectsLoader, $t->agents), []];
        yield 'task:skip-ci' => [static fn (self $t): Command => new SkipCiCommand($t->stateChanger(), $t->store, $t->projectsLoader, $t->agents), []];
        yield 'task:close' => [static fn (self $t): Command => $t->closeCommand(), []];
        yield 'task:precommit-check' => [static fn (self $t): Command => new PrecommitCheckCommand($t->store, $t->projectsLoader, $t->agents), []];
    }

    /**
     * @param \Closure(self): Command $build
     * @param list<string>            $before
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('taskTargetCommands')]
    public function testEveryTaskTargetCompletesBranchAndProject(\Closure $build, array $before): void
    {
        $tester = new CommandCompletionTester($build($this));

        $this->assertSame(['api-3', 'wk-45'], $tester->complete([...$before, '']));
        $this->assertSame(['wk-45'], $tester->complete([...$before, '--project', 'wallet-kit', '']));
        $this->assertSame(['api', 'wallet-kit'], $tester->complete([...$before, '--project', '']));
    }

    public function testTaskInfoSuggestsItsCurrentAliasThenTheBranches(): void
    {
        $tester = new CommandCompletionTester(new TaskCommand($this->store, $this->projectsLoader, $this->agents));

        $this->assertSame(['current', 'api-3', 'wk-45'], $tester->complete(['']));
        $this->assertSame(['current', 'wk-45'], $tester->complete(['--project', 'wallet-kit', '']));
        $this->assertSame(['api', 'wallet-kit'], $tester->complete(['--project', '']));
    }

    public function testTaskListSuggestsProjectsStatesAndTypes(): void
    {
        $listing = new \Pablo\Listing\Listing(new \Pablo\Dispatch\Stamps(), $this->gh, $this->providers, $this->repoSlug, $this->time);
        $tester = new CommandCompletionTester(new TasksCommand($listing, $this->store, $this->projectsLoader, $this->agents));

        $this->assertSame(['api', 'wallet-kit'], $tester->complete(['--project', '']));
        $this->assertSame(array_map(static fn (State $s): string => $s->value, State::cases()), $tester->complete(['--state', '']));
        $this->assertSame(['work', 'open-source', 'personal'], $tester->complete(['--type', '']));
        $this->assertSame(['table', 'json'], $tester->complete(['--format', '']));
    }

    public function testInstallSuggestsRuntimesAndAdes(): void
    {
        $install = new InstallCommand($this->globalConfig, new SchedulerFactory($this->runner), $this->runtimes, $this->ades, $this->projectsLoader);
        $tester = new CommandCompletionTester($install);

        $this->assertSame(['opencode', 'claude-code', 'copilot'], $tester->complete(['--runtime', '']));
        $this->assertSame(['orca', 'openchamber', 'headless'], $tester->complete(['--backend', '']));

        $generate = new GenerateAgentsCommand(new AgentTemplates($this->projectsLoader), $this->runtimes, $this->projectsLoader);
        $this->assertSame(['opencode', 'claude-code', 'copilot'], (new CommandCompletionTester($generate))->complete(['--runtime', '']));
    }

    public function testBrokenConfigGivesNoSuggestionsAndNoError(): void
    {
        file_put_contents($this->projectsDir.'/wallet-kit.yaml', "name: [\n");
        file_put_contents($this->projectsDir.'/api.yaml', ": nope\n  - x\n");

        $this->assertSame([], (new CommandCompletionTester($this->closeCommand()))->complete(['--project', '']));
    }

    public function testAnUnreadableTaskRecordIsSkippedSilently(): void
    {
        file_put_contents($this->tmp.'/state/api/broken.json', '{');
        $stderr = fopen('php://memory', 'w+');
        $this->assertIsResource($stderr);
        $this->store->stderr = $stderr;

        $this->assertSame(['api-3', 'wk-45'], (new CommandCompletionTester($this->closeCommand()))->complete(['']));
        rewind($stderr);
        $this->assertSame('', stream_get_contents($stderr), 'completion never writes a warning into the shell');
        $this->assertSame($stderr, $this->store->stderr);
    }

    public function testStatsProjectOption(): void
    {
        $stats = new StatsCommand(
            new \Pablo\Analytics\AnalyticsReader(),
            new \Pablo\Analytics\AnalyticsAggregator(),
            new \Pablo\Listing\Listing(new \Pablo\Dispatch\Stamps(), $this->gh, $this->providers, $this->repoSlug, $this->time),
            $this->time,
            $this->store,
            $this->projectsLoader,
        );

        $this->assertSame(['api', 'wallet-kit'], (new CommandCompletionTester($stats))->complete(['--project', '']));
        $this->assertSame(['table', 'json', 'csv'], (new CommandCompletionTester($stats))->complete(['--format', '']));
    }
}
