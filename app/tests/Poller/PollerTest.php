<?php

declare(strict_types=1);

namespace Pablo\Tests\Poller;

use Pablo\Agents\SessionInfo;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Poller\Poller;
use Pablo\Provider\Gh\GhPr;
use Pablo\Provider\Gh\PrInfo;
use Pablo\Provider\Git\GitRepo;
use Pablo\Provider\Tracker\Provider;
use Pablo\Provider\Tracker\ProviderRegistry;
use Pablo\StateMachine\StateMachine;
use Pablo\StateMachine\TaskCtx;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Pablo\Tests\FakeAgents;
use PHPUnit\Framework\TestCase;

final class FakePollProvider implements Provider
{
    /** @var list<\DateTimeImmutable> */
    public array $events = [];

    public string $issueStatus = 'In Testing';

    public int $statusCalls = 0;

    /** @return list<\DateTimeImmutable> */
    public function failureSignalEvents(Task $task, ProjectConfig $cfg): array
    {
        return $this->events;
    }

    public function issueStatus(string $key, ProjectConfig $cfg): string
    {
        ++$this->statusCalls;

        return $this->issueStatus;
    }

    public function batchIssueStatus(array $pairs): array
    {
        $result = [];
        foreach ($pairs as [$key]) {
            $result[$key] = $this->issueStatus;
        }
        $this->statusCalls += \count($pairs);

        return $result;
    }

    public function name(): string
    {
        return 'github';
    }

    public function supportsSignalViaStatus(): bool
    {
        return true;
    }

    public function matchUrl(string $url, ProjectConfig $cfg): ?string
    {
        return null;
    }

    public function getIssue(string $ref, ProjectConfig $cfg): Issue
    {
        throw new \LogicException();
    }

    /** @return list<Issue> */
    public function listAssigned(ProjectConfig $cfg): array
    {
        return [];
    }

    public function cliName(): string
    {
        return 'gh';
    }

    public function authCheckCmd(): array
    {
        return [];
    }
}

final class PollerTest extends TestCase
{
    private FakePollProvider $provider;
    private FakeAgents $agents;

    /** @var array<string, mixed> */
    private array $stubs;

    protected function setUp(): void
    {
        $this->provider = new FakePollProvider();
        $this->agents = new FakeAgents();
        $this->stubs = [
            'ci' => 'pending',
            'merged' => false,
            'verdict' => null,
            'sessions' => [],
            'events' => [],
            'removed' => [],
            'launched' => [],
            'startups' => [],
            'display_names' => [],
        ];
        ProviderRegistry::setResolver(fn (string $name): Provider => $this->provider);
        RepoSlug::setFor(static fn () => 'acme/proj');
        GhPr::setCiStatus(fn () => $this->stubs['ci']);
        GhPr::setIsMerged(fn () => $this->stubs['merged']);
        GhPr::setPrForBranch(static fn () => null);
        GhPr::setMarkReady(static fn () => null);
        GhPr::setMarkDraft(static fn () => null);
        GhPr::setReadyAnchor(static fn () => new \DateTimeImmutable('2026-07-20T00:00:00+00:00'));
        GhPr::setFetchReviews(static fn () => ['korbeil', []]);
        GhPr::setEvaluateReviews(fn () => $this->stubs['verdict']);
        GitRepo::setRemoveWorktree(function (string $repo, string $path, string $branch): void {
            $this->stubs['removed'][] = $branch;
        });
        $this->agents->launch = &$this->stubs['launched'];
        $this->agents->displayNames = &$this->stubs['display_names'];
    }

    protected function tearDown(): void
    {
        ProviderRegistry::setResolver(null);
        RepoSlug::setFor(null);
        GhPr::setCiStatus(null);
        GhPr::setIsMerged(null);
        GhPr::setPrForBranch(null);
        GhPr::setMarkReady(null);
        GhPr::setMarkDraft(null);
        GhPr::setReadyAnchor(null);
        GhPr::setFetchReviews(null);
        GhPr::setEvaluateReviews(null);
        GitRepo::setRemoveWorktree(null);
    }

    /** @return array{tmp: string} */
    private function newEnv(): array
    {
        $tmp = sys_get_temp_dir().'/pablo-poll-'.uniqid();
        mkdir($tmp, 0o777, true);

        return ['tmp' => $tmp];
    }

    private function cfg(string $tmp): ProjectConfig
    {
        return new ProjectConfig(
            name: 'proj',
            type: 'work',
            repoPath: $tmp.'/repo',
            primaryBranch: 'main',
            worktreesRoot: $tmp.'/wt',
            provider: 'github',
            identity: 'korbeil',
            projectKey: 'PR',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: 'qa-failed',
            botWhitelist: [],
            ciIgnoreChecks: [],
        );
    }

    private function task(string $tmp, State $state = State::Draft): Task
    {
        $t = new Task('proj', 'pr-1', $tmp.'/wt/pr-1', $state);
        $t->prNumber = 7;
        $t->issue = new Issue('github', '1', 'u', 'T', 'PR');

        return $t;
    }

    /** @return array<int, string> */
    private function poll(ProjectConfig $cfg, Store $store): array
    {
        return Poller::pollProject($cfg, $store, $this->agents);
    }

    private function getTask(Store $store): ?Task
    {
        return $store->get('proj', 'pr-1');
    }

    private function taskOrFail(Store $store): Task
    {
        $task = $this->getTask($store);
        if (null === $task) {
            $this->fail('expected task pr-1');
        }

        return $task;
    }

    /** @param array<string, mixed> $fields */
    private function setState(Store $store, State $state, array $fields = []): Task
    {
        $task = $this->taskOrFail($store);
        $task->state = $state;
        foreach ($fields as $k => $v) {
            $task->{$k} = $v;
        }
        $store->save($task);

        return $task;
    }

    private function freshTs(int $secondsAgo): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$secondsAgo} seconds")
            ->format('c');
    }

    private function setLaunch(Store $store, string $label, int $attempts = 1, int $ago = 0): void
    {
        $task = $this->taskOrFail($store);
        $agent = Agent::tryByName($label) ?? Agent::TaskAnalyst;
        $task->agentLaunches[$agent->value] = new AgentLaunch($agent, $this->freshTs($ago), $attempts);
        $store->save($task);
    }

    public function testDraftToCiRed(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->stubs['ci'] = 'red';
        $this->poll($cfg, $store);
        $this->assertSame(State::CiRed, $this->taskOrFail($store)->state);
        $this->assertSame(['ci-analyst'], $this->stubs['launched']);
    }

    public function testDraftPendingStays(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->poll($cfg, $store);
        $this->assertSame(State::Draft, $this->taskOrFail($store)->state);
    }

    public function testCiRedToReadyChainsToWaitingReview(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::CiRed));
        $this->stubs['ci'] = 'green';
        $this->poll($cfg, $store);
        $this->assertSame(State::WaitingReview, $this->taskOrFail($store)->state);
    }

    public function testWaitingReviewCiRedTakesPriority(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::WaitingReview));
        $this->stubs['ci'] = 'red';
        $this->stubs['verdict'] = 'approved';
        $this->poll($cfg, $store);
        $this->assertSame(State::CiRed, $this->taskOrFail($store)->state);
        $this->assertSame(['ci-analyst'], $this->stubs['launched']);
    }

    public function testWaitingReviewApprovedToNeedsTesting(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::WaitingReview));
        $this->stubs['ci'] = 'green';
        $this->stubs['verdict'] = 'approved';
        $this->poll($cfg, $store);
        $this->assertSame(State::NeedsTesting, $this->taskOrFail($store)->state);
        $this->assertNotNull($this->taskOrFail($store)->needsTestingEnteredAt);
    }

    public function testWaitingReviewChangesToRequestChanges(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::WaitingReview));
        $this->stubs['ci'] = 'green';
        $this->stubs['verdict'] = 'changes';
        $this->poll($cfg, $store);
        $this->assertSame(State::RequestChanges, $this->taskOrFail($store)->state);
        $this->assertSame(['pr-feedback'], $this->stubs['launched']);
    }

    public function testNeedsTestingSignalBaselineAndHandledDedupe(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::NeedsTesting));
        $this->setState($store, State::NeedsTesting, ['needsTestingEnteredAt' => '2026-07-22T00:00:00+00:00']);
        $old = new \DateTimeImmutable('2026-07-21T00:00:00+00:00');
        $this->provider->events = [$old];
        $this->poll($cfg, $store);
        $this->assertSame(State::NeedsTesting, $this->taskOrFail($store)->state);

        $new = new \DateTimeImmutable('2026-07-23T00:00:00+00:00');
        $this->provider->events = [$old, $new];
        $this->poll($cfg, $store);
        $this->assertSame(State::TestingFailed, $this->taskOrFail($store)->state);
        $this->assertSame($new->format('c'), $this->taskOrFail($store)->lastHandledSignalAt);
        $this->assertSame(['task-feedback'], $this->stubs['launched']);

        $this->setState($store, State::NeedsTesting, ['needsTestingEnteredAt' => '2026-07-22T00:00:00+00:00']);
        $this->poll($cfg, $store);
        $this->assertSame(State::NeedsTesting, $this->taskOrFail($store)->state);
    }

    public function testStatusTransitionTriggersTestingFailed(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::NeedsTesting));
        $this->setState($store, State::NeedsTesting, [
            'needsTestingEnteredAt' => '2026-07-22T00:00:00+00:00',
            'lastSeenIssueStatus' => 'In Testing',
        ]);
        $this->provider->issueStatus = 'qa-failed';
        $this->poll($cfg, $store);
        $this->assertSame(State::TestingFailed, $this->taskOrFail($store)->state);
        $this->assertNotNull($this->taskOrFail($store)->lastHandledSignalAt);
        $this->assertSame(['task-feedback'], $this->stubs['launched']);
    }

    public function testStatusAlreadyFailedAtEntryDoesNotTrigger(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::NeedsTesting));
        $this->setState($store, State::NeedsTesting, [
            'needsTestingEnteredAt' => '2026-07-22T00:00:00+00:00',
            'lastSeenIssueStatus' => 'qa-failed',
        ]);
        $this->provider->issueStatus = 'qa-failed';
        $this->poll($cfg, $store);
        $this->assertSame(State::NeedsTesting, $this->taskOrFail($store)->state);
    }

    public function testStatusPersistingDoesNotRetrigger(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::NeedsTesting));
        $this->setState($store, State::NeedsTesting, [
            'needsTestingEnteredAt' => '2026-07-22T00:00:00+00:00',
            'lastSeenIssueStatus' => 'In Testing',
        ]);
        $this->provider->issueStatus = 'qa-failed';
        $this->poll($cfg, $store);
        $this->assertSame(State::TestingFailed, $this->taskOrFail($store)->state);
        $this->setState($store, State::NeedsTesting, ['needsTestingEnteredAt' => '2026-07-23T00:00:00+00:00']);
        $this->poll($cfg, $store);
        $this->assertSame(State::NeedsTesting, $this->taskOrFail($store)->state);
    }

    public function testChangelogPathStillPreferred(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::NeedsTesting));
        $this->setState($store, State::NeedsTesting, [
            'needsTestingEnteredAt' => '2026-07-22T00:00:00+00:00',
            'lastSeenIssueStatus' => 'In Testing',
        ]);
        $this->provider->events = [new \DateTimeImmutable('2026-07-23T00:00:00+00:00')];
        $this->provider->issueStatus = 'In Testing';
        $this->poll($cfg, $store);
        $this->assertSame(State::TestingFailed, $this->taskOrFail($store)->state);
        $this->assertSame(1, $this->provider->statusCalls);
    }

    public function testCiRedDuringNeedsTestingIsAcceptedGap(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::NeedsTesting));
        $this->setState($store, State::NeedsTesting, ['needsTestingEnteredAt' => '2026-07-22T00:00:00+00:00']);
        $this->stubs['ci'] = 'red';
        $this->poll($cfg, $store);
        $this->assertSame(State::NeedsTesting, $this->taskOrFail($store)->state);
    }

    public function testWaitingSkipsTransitionsButNotMerge(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::Waiting));
        $this->setState($store, State::Waiting, ['stateBeforeWaiting' => State::WaitingReview]);
        $this->stubs['ci'] = 'red';
        $this->stubs['verdict'] = 'changes';
        $this->poll($cfg, $store);
        $this->assertSame(State::Waiting, $this->taskOrFail($store)->state);

        $this->stubs['merged'] = true;
        $this->poll($cfg, $store);
        $this->assertNull($this->getTask($store));
        $this->assertSame(['pr-1'], $this->stubs['removed']);
    }

    public function testMergeWithActiveAgentsDefersClose(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->stubs['merged'] = true;
        $this->agents->active = [new SessionInfo('a', 'running')];
        $this->poll($cfg, $store);
        $this->assertTrue($this->taskOrFail($store)->merged);

        $this->stubs['ci'] = 'red';
        $this->poll($cfg, $store);
        $this->assertSame(State::Draft, $this->taskOrFail($store)->state);

        $this->agents->active = [];
        $this->poll($cfg, $store);
        $this->assertNull($this->getTask($store));
        $this->assertSame(['pr-1'], $this->stubs['removed']);
    }

    public function testPrNumberDiscoveredForDraft(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::Draft));
        $this->setState($store, State::Draft, ['prNumber' => null]);
        GhPr::setPrForBranch(static fn () => new PrInfo(9, 't', 'OPEN', true, 'u', null));
        $this->stubs['ci'] = 'pending';
        $this->poll($cfg, $store);
        $this->assertSame(9, $this->taskOrFail($store)->prNumber);
    }

    public function testInProgressTaskUntouched(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        $this->poll($cfg, $store);
        $this->assertSame(State::InProgress, $this->taskOrFail($store)->state);
    }

    public function testPollPersistsDisplayCache(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        GhPr::setPrForBranch(static fn () => new PrInfo(7, 't', 'OPEN', true, 'u', null));
        $this->provider->issueStatus = 'In Progress';
        $this->agents->active = [new SessionInfo('a', 'running')];
        $this->poll($cfg, $store);
        $task = $this->taskOrFail($store);
        $this->assertSame('In Progress', $task->displayCache->trackerStatus);
        $this->assertSame('📪 draft #7', $task->displayCache->prState);
        $this->assertSame(1, $task->displayCache->agentCount);
        $this->assertSame('🏃 1', $task->displayCache->agentActivity);
        $this->assertNotNull($task->displayCache->at);
    }

    public function testPollDisplayCacheSurvivesClosedTask(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->stubs['merged'] = true;
        $this->poll($cfg, $store);
        $this->assertNull($this->getTask($store));
    }

    public function testNoStateChangeWhenSessionPresent(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        $this->agents->active = [new SessionInfo('a', 'running')];
        $this->setLaunch($store, 'task-analyst', 1, 301);
        $this->poll($cfg, $store);
        $this->assertSame([], $this->stubs['launched']);
    }

    public function testCapsAtMaxAttempts(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        $this->setLaunch($store, 'task-analyst', Poller::LAUNCH_MAX_ATTEMPTS, 301);
        $this->poll($cfg, $store);
        $this->assertSame([], $this->stubs['launched']);
    }

    public function testRelaunchesMissingAgentPastWindow(): void
    {
        foreach ([
            [State::InProgress, 'task-analyst', null],
            [State::CiRed, 'ci-analyst', 7],
            [State::RequestChanges, 'pr-feedback', 7],
            [State::TestingFailed, 'task-feedback', 7],
        ] as [$state, $label, $pr]) {
            $this->stubs['launched'] = [];
            $e = $this->newEnv();
            $cfg = $this->cfg($e['tmp']);
            $store = new Store($e['tmp'].'/state');
            $store->save($this->task($e['tmp'], $state));
            $this->setState($store, $state, ['prNumber' => $pr]);
            $this->setLaunch($store, $label, 1, 301);
            $this->poll($cfg, $store);
            $this->assertSame([$label], $this->stubs['launched']);
            $task = $this->taskOrFail($store);
            $agent = Agent::tryByName($label) ?? Agent::TaskAnalyst;
            $this->assertSame(2, $task->agentLaunches[$agent->value]->attempts);
        }
    }

    public function testInProgressRelaunchesStartupPastWindow(): void
    {
        $e = $this->newEnv();
        $cfg = new ProjectConfig(
            name: 'proj',
            type: 'work',
            repoPath: $e['tmp'].'/repo',
            primaryBranch: 'main',
            worktreesRoot: $e['tmp'].'/wt',
            provider: 'github',
            identity: 'korbeil',
            projectKey: 'PR',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: 'qa-failed',
            botWhitelist: [],
            ciIgnoreChecks: [],
            startupScript: '/setup.sh',
        );
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        $this->setLaunch($store, 'task-analyst', 1, 301);
        $this->setLaunch($store, 'startup-script', 1, 301);
        $this->agents->startupScript = &$this->stubs['startups'];
        $this->poll($cfg, $store);
        $this->assertSame(['task-analyst'], $this->stubs['launched']);
        $this->assertSame(['/setup.sh'], $this->stubs['startups']);
    }

    public function testNoRelaunchWithinLaunchWindow(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::CiRed));
        $this->setState($store, State::CiRed, ['prNumber' => 7]);
        $this->setLaunch($store, 'ci-analyst', 1, 10);
        $this->poll($cfg, $store);
        $this->assertSame([], $this->stubs['launched']);
    }

    public function testDisplayNameReSetDuringSelfHeal(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        $this->setLaunch($store, 'task-analyst', 1, 301);
        $this->poll($cfg, $store);
        $this->assertSame(['1'], $this->stubs['display_names']);
    }

    public function testDisplayNameNotReSetWhenSessionsExist(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        $this->agents->active = [new SessionInfo('a', 'running')];
        $this->setLaunch($store, 'task-analyst', 1, 301);
        $this->poll($cfg, $store);
        $this->assertSame([], $this->stubs['display_names']);
    }

    public function testSkipCiIgnoresRedCi(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::WaitingReview));
        $this->setState($store, State::WaitingReview, ['ciIgnored' => true]);
        $this->stubs['ci'] = 'red';
        $this->stubs['verdict'] = null;
        $this->poll($cfg, $store);
        $this->assertSame(State::WaitingReview, $this->taskOrFail($store)->state);
        $this->assertSame([], $this->stubs['launched']);
    }

    public function testCiIgnoredResetOnDraft(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::WaitingReview));
        $task = $this->taskOrFail($store);
        $task->ciIgnored = true;
        $store->save($task);
        $ctx = new TaskCtx($task, $cfg, $store, $this->agents);
        StateMachine::enterState($ctx, State::Draft);
        $this->assertFalse($task->ciIgnored);
    }
}
