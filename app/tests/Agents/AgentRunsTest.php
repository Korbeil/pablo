<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentRuns;
use Pablo\Agents\DetachedRun;
use Pablo\Config\GlobalConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Store\Store;
use Pablo\Support\Files;
use Pablo\Tests\FakeAgents;
use Pablo\Tests\FakeGit;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;

/**
 * Active runs come from launch records + pidfiles, liveness and stops go
 * through the launcher (FakeAgents here: nothing real is listed or stopped).
 */
final class AgentRunsTest extends TestCase
{
    use UsesGlobalConfig;

    private string $tmp;
    private Store $store;
    private FakeAgents $agents;
    private FakeGit $git;
    private AgentRuns $runs;

    /** @var list<?string> the ADE of every launcher asked for */
    private array $ades = [];

    protected function setUp(): void
    {
        $this->writeGlobalConfig("{}\n");
        $this->tmp = sys_get_temp_dir().'/pablo-runs-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $this->store = new Store($this->tmp.'/state');
        $this->agents = new FakeAgents();
        $this->git = new FakeGit();
        $this->runs = new AgentRuns(
            $this->store,
            new AgentLauncherFactory(new GlobalConfig(), new \Pablo\Tests\FakeProcessRunner()),
            $this->git,
            new Time(),
            function (?string $ade): FakeAgents {
                $this->ades[] = $ade;

                return $this->agents;
            },
        );
    }

    protected function tearDown(): void
    {
        $this->unsetGlobalConfig();
        Files::remove($this->tmp);
    }

    /** @param array<string, AgentLaunch> $launches */
    private function task(string $project, string $branch, array $launches = []): Task
    {
        $task = new Task($project, $branch, $this->tmp.'/wt/'.$branch, State::InProgress);
        $task->agentLaunches = $launches;
        $this->store->save($task);

        return $task;
    }

    private function launch(Agent $agent, ?string $handle, ?string $finishedAt = null): AgentLaunch
    {
        $launch = new AgentLaunch($agent, '2026-01-01T00:00:00+00:00', 1, $finishedAt);

        return null !== $handle ? $launch->withHandle($handle, 'orca', 'opencode') : $launch;
    }

    public function testListsRecordedRunsAtWorkAndPidfileRunsOnTaskWorktrees(): void
    {
        $this->task('acme', 'a-1', [
            Agent::TaskAnalyst->value => $this->launch(Agent::TaskAnalyst, 'term_live'),
            Agent::CiAnalyst->value => $this->launch(Agent::CiAnalyst, 'term_done'),
            Agent::PrFeedback->value => $this->launch(Agent::PrFeedback, 'term_x', '2026-01-01T00:10:00+00:00'),
            Agent::TaskFeedback->value => $this->launch(Agent::TaskFeedback, null),
        ]);
        $this->task('acme', 'a-2');
        $this->agents->running = ['term_live' => true, 'term_x' => true];
        $this->agents->detached = [
            // Sync's conflict resolver: no launch record, only its pidfile.
            new DetachedRun('pid:42', $this->tmp.'/wt/a-2', 'rebase-conflict-resolver', '2026-01-01T00:05:00+00:00', 'bg', 'opencode', 'ses_42'),
            // Not a PABLO task's worktree: never listed.
            new DetachedRun('pid:43', '/elsewhere', 'task-analyst', null, 'bg'),
        ];

        $runs = $this->runs->active();

        $this->assertSame(
            [['a-1', 'task-analyst', 'orca', 'term_live', true, null], ['a-2', 'rebase-conflict-resolver', 'bg', 'pid:42', false, 'opencode -s ses_42']],
            array_map(static fn ($r) => [$r->branch, $r->agent, $r->ade, $r->handle, $r->recorded, $r->resume], $runs),
        );
        $this->assertSame('opencode', $runs[0]->runtime);
        $this->assertGreaterThan(0, $runs[0]->elapsedS);
        $this->assertContains('orca', $this->ades, 'liveness is asked from the run\'s own ADE');
    }

    public function testARecordedBackgroundRunIsListedOnceWithItsPidfileDetails(): void
    {
        $this->task('acme', 'a-1', [Agent::TaskAnalyst->value => $this->launch(Agent::TaskAnalyst, 'pid:7')]);
        $this->agents->detached = [new DetachedRun('pid:7', $this->tmp.'/wt/a-1', 'task-analyst', null, 'bg', 'opencode', 'ses_7')];

        $runs = $this->runs->active();

        $this->assertCount(1, $runs);
        $this->assertSame(['bg', true, 'opencode -s ses_7'], [$runs[0]->ade, $runs[0]->recorded, $runs[0]->resume]);
    }

    public function testFiltersByProject(): void
    {
        $this->task('acme', 'a-1', [Agent::TaskAnalyst->value => $this->launch(Agent::TaskAnalyst, 'term_a')]);
        $this->task('other', 'o-1', [Agent::TaskAnalyst->value => $this->launch(Agent::TaskAnalyst, 'term_o')]);
        $this->agents->running = ['term_a' => true, 'term_o' => true];

        $this->assertSame(['o-1'], array_map(static fn ($r) => $r->branch, $this->runs->active('other')));
    }

    public function testStopEndsTheRunsAndMarksTheirRecordsStoppedButUnreported(): void
    {
        $task = $this->task('acme', 'a-1', [
            Agent::TaskAnalyst->value => $this->launch(Agent::TaskAnalyst, 'term_1'),
            Agent::CiAnalyst->value => $this->launch(Agent::CiAnalyst, 'term_2'),
        ]);
        $this->agents->running = ['term_1' => true, 'term_2' => true];

        $reports = $this->runs->stop($task, 'ci-analyst');

        $this->assertCount(1, $reports);
        $this->assertTrue($reports[0]->outcome->stopped);
        $this->assertNull($reports[0]->cleanup);
        $this->assertSame(['term_2'], $this->agents->stops);
        $saved = $this->store->get('acme', 'a-1');
        $this->assertNotNull($saved);
        $ci = $saved->agentLaunches['ci-analyst'];
        $this->assertNotNull($ci->finishedAt);
        $this->assertSame(AgentLaunch::STOPPED, $ci->stopReason);
        $this->assertFalse($ci->reported, 'the watcher or the sweep still reports it');
        $this->assertNull($saved->agentLaunches['task-analyst']->finishedAt, 'the other run is untouched');
    }

    public function testARunTheAdeCannotStopKeepsItsRecord(): void
    {
        $task = $this->task('acme', 'a-1', [Agent::TaskAnalyst->value => $this->launch(Agent::TaskAnalyst, 'ses_1')]);
        $this->agents->running = ['ses_1' => true];
        $this->agents->canStop = false;

        $reports = $this->runs->stop($task);

        $this->assertFalse($reports[0]->outcome->stopped);
        $this->assertNull($this->store->get('acme', 'a-1')?->agentLaunches['task-analyst']->finishedAt);
    }

    public function testStoppingTheConflictResolverMidRebaseAbortsIt(): void
    {
        $task = $this->task('acme', 'a-1');
        $gitDir = $this->tmp.'/gitdir';
        mkdir($gitDir.'/rebase-merge', 0o777, true);
        $this->git->gitHandler = static fn (array $args): string => ['rev-parse', '--absolute-git-dir'] === $args ? $gitDir : '';
        $this->agents->detached = [new DetachedRun('pid:42', $task->worktreePath, 'rebase-conflict-resolver', null, 'bg')];

        $reports = $this->runs->stop($task);

        $this->assertSame("aborted the rebase in progress in {$task->worktreePath}", $reports[0]->cleanup);
        $this->assertContains([$task->worktreePath, ['rebase', '--abort']], $this->git->gitCalls);
    }

    public function testStoppingTheConflictResolverOnACleanWorktreeTouchesNothing(): void
    {
        $task = $this->task('acme', 'a-1');
        $gitDir = $this->tmp.'/gitdir';
        mkdir($gitDir, 0o777, true);
        $this->git->gitHandler = static fn (array $args): string => ['rev-parse', '--absolute-git-dir'] === $args ? $gitDir : '';
        $this->agents->detached = [new DetachedRun('pid:42', $task->worktreePath, 'rebase-conflict-resolver', null, 'bg')];

        $reports = $this->runs->stop($task);

        $this->assertNull($reports[0]->cleanup);
        $this->assertSame([[$task->worktreePath, ['rev-parse', '--absolute-git-dir']]], $this->git->gitCalls);
    }
}
