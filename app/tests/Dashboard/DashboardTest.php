<?php

declare(strict_types=1);

namespace Pablo\Tests\Dashboard;

use Pablo\Config\ProjectConfig;
use Pablo\Dashboard\Dashboard;
use Pablo\Dashboard\PollSchedule;
use Pablo\Dashboard\TaskView;
use Pablo\Domain\DisplayCache;
use Pablo\Domain\Issue;
use Pablo\Domain\PrBadgeKind;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase
{
    private string $tmp;
    private Store $store;
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-dash-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $this->store = new Store($this->tmp.'/state');
        $this->dashboard = new Dashboard($this->store, new PollSchedule());
        RepoSlug::setFor(static fn (ProjectConfig $cfg) => 'acme/'.$cfg->name);
    }

    protected function tearDown(): void
    {
        RepoSlug::setFor(null);
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    private function cfg(string $name = 'wallet-kit', string $type = 'work'): ProjectConfig
    {
        return new ProjectConfig(
            name: $name,
            type: $type,
            repoPath: $this->tmp.'/'.$name,
            primaryBranch: 'main',
            worktreesRoot: $this->tmp.'/wt/'.$name,
            provider: 'github',
            identity: 'octocat',
            projectKey: 'WK',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 720,
            pollInterval: 10,
            failureSignal: null,
            botWhitelist: [],
            ciIgnoreChecks: [],
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
    }

    /** @return array<string, ProjectConfig> */
    private function projects(string ...$names): array
    {
        $out = [];
        foreach ([] !== $names ? $names : ['wallet-kit'] as $n) {
            $out[$n] = $this->cfg($n);
        }

        return $out;
    }

    private function seed(
        string $branch,
        State $state,
        string $project = 'wallet-kit',
        ?DisplayCache $cache = null,
        string $stateEnteredAt = '2026-08-01T10:00:00+00:00',
        ?int $prNumber = null,
    ): Task {
        $task = new Task($project, $branch, $this->tmp.'/wt/'.$branch, $state);
        $task->stateEnteredAt = $stateEnteredAt;
        $task->prNumber = $prNumber;
        $task->displayCache = $cache ?? DisplayCache::empty();
        $this->store->save($task);

        return $task;
    }

    /**
     * @param list<TaskView> $views
     *
     * @return list<string>
     */
    private function branches(array $views): array
    {
        return array_map(static fn (TaskView $v) => $v->branch, $views);
    }

    private function waiting(): DisplayCache
    {
        return new DisplayCache(null, null, 1, '💭 1', '2026-08-06T10:00:00+00:00');
    }

    private function running(): DisplayCache
    {
        return new DisplayCache(null, null, 1, '🏃 1', '2026-08-06T10:00:00+00:00');
    }

    /**
     * The split mirrors the terminal listing exactly: a state in
     * WAITING_FEEDBACK_STATES *and* an agent actually blocked on the user.
     * needs-testing and waiting-review never qualify — the ball is with QA or
     * with reviewers, not with you.
     */
    public function testOnlyWaitingFeedbackStatesWithAWaitingAgentGetAttention(): void
    {
        $this->seed('a-testing-failed', State::TestingFailed, cache: $this->waiting());
        $this->seed('b-request-changes', State::RequestChanges, cache: $this->waiting());
        $this->seed('c-ci-red', State::CiRed, cache: $this->waiting());
        $this->seed('g-in-progress', State::InProgress, cache: $this->waiting());
        // eligible states, but nothing is blocked on the user
        $this->seed('c2-ci-red-no-agent', State::CiRed);
        // ineligible states, even with a waiting agent
        $this->seed('d-needs-testing', State::NeedsTesting, cache: $this->waiting());
        $this->seed('f-waiting-review', State::WaitingReview, cache: $this->waiting());
        $this->seed('e-draft', State::Draft, cache: $this->waiting());
        $this->seed('h-waiting', State::Waiting, cache: $this->waiting());

        $board = $this->dashboard->board($this->projects());

        $this->assertSame(
            ['a-testing-failed', 'b-request-changes', 'c-ci-red', 'g-in-progress'],
            $this->branches($board['attention']),
        );
        $this->assertSame(
            ['d-needs-testing', 'f-waiting-review', 'c2-ci-red-no-agent', 'e-draft', 'h-waiting'],
            $this->branches($board['rest']),
        );
    }

    /** A running agent is not a blocked one. */
    public function testRunningAgentDoesNotEarnAttention(): void
    {
        $this->seed('quiet-ci-red', State::CiRed, cache: $this->running());
        $this->seed('blocked-ci-red', State::CiRed, cache: $this->waiting());

        $board = $this->dashboard->board($this->projects());

        $this->assertSame(['blocked-ci-red'], $this->branches($board['attention']));
        $this->assertSame(['quiet-ci-red'], $this->branches($board['rest']));
    }

    public function testSortIsByStateRankThenStateEnteredAt(): void
    {
        $this->seed('newer', State::CiRed, cache: $this->waiting(), stateEnteredAt: '2026-08-05T10:00:00+00:00');
        $this->seed('older', State::CiRed, cache: $this->waiting(), stateEnteredAt: '2026-08-01T10:00:00+00:00');

        $board = $this->dashboard->board($this->projects());

        $this->assertSame(['older', 'newer'], $this->branches($board['attention']));
    }

    public function testTasksOfUnconfiguredProjectsAreSkipped(): void
    {
        $this->seed('kept', State::Draft, project: 'wallet-kit');
        $this->seed('orphan', State::Draft, project: 'deleted-project');

        $board = $this->dashboard->board($this->projects('wallet-kit'));

        $this->assertSame(['kept'], $this->branches($board['rest']));
    }

    public function testPrBadgeAndUrlComeFromTheDisplayCache(): void
    {
        $this->seed('has-pr', State::Draft, cache: new DisplayCache(null, '📖 open #42', 0, '-', '2026-08-06T10:00:00+00:00'));

        $view = $this->dashboard->board($this->projects())['rest'][0];

        $this->assertSame(PrBadgeKind::Open, $view->pr->kind);
        $this->assertSame(42, $view->pr->number);
        $this->assertSame('https://github.com/acme/wallet-kit/pull/42', $view->prUrl);
    }

    /** Never polled: fall back to what the task record itself knows. */
    public function testUncachedTaskFallsBackToTheTaskRecord(): void
    {
        $this->seed('never-polled', State::Draft, prNumber: 7);

        $view = $this->dashboard->board($this->projects())['rest'][0];

        $this->assertSame(PrBadgeKind::Unknown, $view->pr->kind);
        $this->assertSame(7, $view->pr->number);
        $this->assertNull($view->polledAt);
    }

    public function testStatePresentationComesFromTheStateMachine(): void
    {
        $this->seed('x', State::NeedsTesting);

        $view = $this->dashboard->board($this->projects())['rest'][0];

        $this->assertSame('🧪', $view->stateEmoji);
        $this->assertSame('needs-testing', $view->stateLabel);
        $this->assertSame('lucide:flask-conical', $view->stateIcon());
    }

    public function testLabelPrefersTheIssueThenTheSummary(): void
    {
        $withIssue = $this->seed('with-issue', State::Draft);
        $withIssue->issue = new Issue('github', 'WK-1', 'https://x/1', 'Fix the thing', null, 'In progress');
        $this->store->save($withIssue);

        $withSummary = $this->seed('with-summary', State::Draft);
        $withSummary->summary = 'tidy the callbacks';
        $this->store->save($withSummary);

        $this->seed('bare', State::Draft);

        $labels = [];
        foreach ($this->dashboard->board($this->projects())['rest'] as $v) {
            $labels[$v->branch] = $v->label();
        }

        $this->assertSame('WK-1 Fix the thing', $labels['with-issue']);
        $this->assertSame('tidy the callbacks', $labels['with-summary']);
        $this->assertSame('-', $labels['bare']);
    }

    public function testEmptyStoreYieldsTwoEmptyTables(): void
    {
        $board = $this->dashboard->board($this->projects());

        $this->assertSame([], $board['attention']);
        $this->assertSame([], $board['rest']);
    }

    /** board() already takes the projects array, so a caller can filter it. */
    public function testBoardFiltersTasksByTheProjectsPassedIn(): void
    {
        $this->seed('work-task', State::Draft, project: 'wallet-kit');
        $this->seed('oss-task', State::Draft, project: 'bookkeeper');
        $this->seed('personal-task', State::Draft, project: 'blog');

        $ossOnly = ['bookkeeper' => $this->cfg('bookkeeper', 'open-source')];

        $board = $this->dashboard->board($ossOnly);

        $this->assertSame(['oss-task'], $this->branches($board['rest']));
    }
}
