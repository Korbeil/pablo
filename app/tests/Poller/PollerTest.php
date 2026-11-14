<?php

declare(strict_types=1);

namespace Pablo\Tests\Poller;

use Pablo\Agents\SessionInfo;
use Pablo\Config\HooksConfig;
use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TestingConfig;
use Pablo\Config\TrackerConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\DisplayCache;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Poller\Poller;
use Pablo\Provider\Gh\CiCheck;
use Pablo\Provider\Gh\PrInfo;
use Pablo\Provider\Gh\PrReviews;
use Pablo\Provider\Gh\PrSnapshot;
use Pablo\Provider\Gh\ReviewNode;
use Pablo\Provider\Gh\ReviewSnapshot;
use Pablo\Provider\Tracker\Provider;
use Pablo\StateMachine\StateMachine;
use Pablo\StateMachine\TaskCtx;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Pablo\Tests\FakeAgents;
use Pablo\Tests\FakeAnalytics;
use Pablo\Tests\TempDir;
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

    public function batchIssueStatus(array $issues): array
    {
        $result = [];
        foreach ($issues as $key => $_) {
            $result[$key] = $this->issueStatus;
        }
        $this->statusCalls += \count($issues);

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
}

final class PollerTest extends TestCase
{
    private FakePollProvider $provider;

    private function providerRegistry(): \Pablo\Provider\Tracker\ProviderRegistryInterface
    {
        return new class($this->provider) implements \Pablo\Provider\Tracker\ProviderRegistryInterface {
            public function __construct(private readonly Provider $provider)
            {
            }

            public function get(string $name): Provider
            {
                return $this->provider;
            }
        };
    }
    private FakeAgents $agents;
    private \Pablo\Tests\FakeGhPr $gh;
    private \Pablo\Tests\FakeGit $git;
    private Poller $poller;
    private \Pablo\Provider\Tracker\ProviderRegistryInterface $providers;
    private \Pablo\Tests\FakeProcessRunner $runner;

    /** @var array<string, mixed> */
    private array $stubs;

    protected function setUp(): void
    {
        $this->provider = new FakePollProvider();
        $this->agents = new FakeAgents();
        $this->stubs = [
            'ci' => 'pending',
            'sessions' => [],
            'events' => [],
            'removed' => [],
            'launched' => [],
            'startups' => [],
            'display_names' => [],
        ];
        $this->gh = new \Pablo\Tests\FakeGhPr();
        $this->gh->onCiStatus = fn () => $this->stubs['ci'];
        $this->git = new \Pablo\Tests\FakeGit();
        $this->runner = new \Pablo\Tests\FakeProcessRunner();
        $this->providers = new class($this->provider) implements \Pablo\Provider\Tracker\ProviderRegistryInterface {
            public function __construct(private readonly Provider $provider)
            {
            }

            public function get(string $name): Provider
            {
                return $this->provider;
            }
        };
        $git = new \Pablo\Tests\FakeGit();
        $git->originUrl = 'git@github.com:acme/proj.git';
        $repoSlug = new RepoSlug($git);
        $time = new \Pablo\Domain\Time();
        $sm = new StateMachine($this->gh, $this->providers, $repoSlug, $time, \Pablo\Tests\Registries::hooks());
        $this->poller = new Poller($this->gh, $this->git, $this->providers, $repoSlug, $sm, $time, new \Pablo\Agents\Runtime\OpenCode\OpenCodeUsage($this->runner), \Pablo\Tests\Registries::hooks());
    }

    /** A poller whose analytics/usage collaborators are test doubles. */
    private function analyticsPoller(FakeAnalytics $analytics): Poller
    {
        $git = new \Pablo\Tests\FakeGit();
        $git->originUrl = 'git@github.com:acme/proj.git';
        $repoSlug = new RepoSlug($git);
        $time = new \Pablo\Domain\Time();
        $sm = new StateMachine($this->gh, $this->providers, $repoSlug, $time, \Pablo\Tests\Registries::hooks());

        return new Poller($this->gh, $this->git, $this->providers, $repoSlug, $sm, $time, new \Pablo\Agents\Runtime\OpenCode\OpenCodeUsage($this->runner), \Pablo\Tests\Registries::hooks(), $analytics);
    }

    protected function tearDown(): void
    {
    }

    /** @return array{tmp: string} */
    private function newEnv(): array
    {
        $tmp = TempDir::create('poll');

        return ['tmp' => $tmp];
    }

    private function cfg(string $tmp, bool $testingEnabled = true): ProjectConfig
    {
        return new ProjectConfig(
            name: 'proj',
            type: 'work',
            repo: new RepoConfig(
                path: $tmp.'/repo',
                primaryBranch: 'main',
                worktreesRoot: $tmp.'/wt',
            ),
            tracker: new TrackerConfig(
                provider: 'github',
                identity: 'octocat',
                projectKey: 'PR',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 30,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
            testing: new TestingConfig(
                enabled: $testingEnabled,
                failureSignal: 'qa-failed',
            ),
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
        return $this->poller->pollProject($cfg, $store, $this->agents);
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

    private function setLaunch(Store $store, string $label, int $attempts = 1, int $ago = 0, ?string $finishedAt = null, bool $reported = false): void
    {
        $task = $this->taskOrFail($store);
        $agent = Agent::tryFrom($label) ?? Agent::TaskAnalyst;
        $task->agentLaunches[$agent->value] = new AgentLaunch($agent, $this->freshTs($ago), $attempts, $finishedAt, reported: $reported);
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
        $this->assertSame(['ci-analyst'], $this->agents->launch);
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
        $this->gh->verdict = 'approved';
        $this->poll($cfg, $store);
        $this->assertSame(State::CiRed, $this->taskOrFail($store)->state);
        $this->assertSame(['ci-analyst'], $this->agents->launch);
    }

    public function testWaitingReviewApprovedToNeedsTesting(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::WaitingReview));
        $this->stubs['ci'] = 'green';
        $this->gh->verdict = 'approved';
        $this->poll($cfg, $store);
        $this->assertSame(State::NeedsTesting, $this->taskOrFail($store)->state);
        $this->assertNotNull($this->taskOrFail($store)->needsTestingEnteredAt);
    }

    public function testWaitingReviewApprovedStaysInApprovedWhenTestingDisabled(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp'], testingEnabled: false);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::WaitingReview));
        $this->stubs['ci'] = 'green';
        $this->gh->verdict = 'approved';
        $this->poll($cfg, $store);
        $this->assertSame(State::Approved, $this->taskOrFail($store)->state);
        $this->assertNull($this->taskOrFail($store)->needsTestingEnteredAt);
    }

    public function testApprovedWithRedCiMovesToCiRedWhenTestingDisabled(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp'], testingEnabled: false);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::WaitingReview));
        $this->stubs['ci'] = 'green';
        $this->gh->verdict = 'approved';
        $this->poll($cfg, $store);
        $this->assertSame(State::Approved, $this->taskOrFail($store)->state);

        $this->stubs['ci'] = 'red';
        $this->poll($cfg, $store);
        $this->assertSame(State::CiRed, $this->taskOrFail($store)->state);
    }

    public function testLegacyNeedsTestingKeepsSignalWhenTestingDisabled(): void
    {
        // Only *entry* is gated: a task already in needs-testing keeps
        // polling testing-failed normally even with testing disabled.
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp'], testingEnabled: false);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::NeedsTesting));
        $this->setState($store, State::NeedsTesting, ['needsTestingEnteredAt' => '2026-07-22T00:00:00+00:00']);
        $this->provider->events = [new \DateTimeImmutable('2026-07-23T00:00:00+00:00')];
        $this->poll($cfg, $store);
        $this->assertSame(State::TestingFailed, $this->taskOrFail($store)->state);
    }

    public function testWaitingReviewChangesToRequestChanges(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::WaitingReview));
        $this->stubs['ci'] = 'green';
        $this->gh->verdict = 'changes';
        $this->poll($cfg, $store);
        $this->assertSame(State::RequestChanges, $this->taskOrFail($store)->state);
        $this->assertSame(['pr-feedback'], $this->agents->launch);
    }

    public function testRequestChangesSelfHealsToWaitingReviewWhenFeedbackAddressed(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::RequestChanges));
        $this->setLaunch($store, 'pr-feedback', finishedAt: '2026-07-22T00:00:00+00:00', reported: true);
        $this->gh->verdict = null; // comment review cleared: all threads resolved
        $this->poll($cfg, $store);
        // ready-to-review is momentary: PR marked ready, task settles in waiting-review.
        $this->assertSame(State::WaitingReview, $this->taskOrFail($store)->state);
        $this->assertSame([7], $this->gh->readies);
    }

    public function testRequestChangesStaysWhileYoloIsFixingIt(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::RequestChanges));
        $this->setLaunch($store, 'pr-feedback', finishedAt: '2026-07-22T00:00:00+00:00', reported: true);
        $this->setState($store, State::RequestChanges, ['yolo' => true, 'yoloStep' => Agent::YoloImplement]);
        $this->gh->verdict = null;
        $this->poll($cfg, $store);
        // Its commit moves the task on, not the resolved threads.
        $this->assertSame(State::RequestChanges, $this->taskOrFail($store)->state);
        $this->assertSame([], $this->gh->readies);
    }

    public function testTheSweepFinishesAYoloStepWhoseWatcherDied(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $task = $this->setState($store, State::InProgress, ['prNumber' => null, 'yolo' => true, 'yoloStep' => Agent::YoloImplement, 'yoloRounds' => 1]);
        $task->agentLaunches['yolo-implement'] = new AgentLaunch(Agent::YoloImplement, $this->freshTs(\Pablo\Agents\Ade\AbstractAgentLauncher::WAIT_TIMEOUT_S + Poller::LAUNCH_WINDOW_S + 1), 1, sessionId: 'ses_plan');
        $store->save($task);

        $events = $this->poll($cfg, $store);

        $this->assertContains('pr-1: yolo-implement finished → yolo-commit', $events);
        $this->assertSame('yolo-commit', $this->agents->followUps[0]['step']);
        $this->assertSame('ses_plan', $this->agents->followUps[0]['session']);
        $task = $this->taskOrFail($store);
        $this->assertTrue($task->agentLaunches['yolo-implement']->reported);
        $this->assertSame(Agent::YoloCommit, $task->yoloStep);
    }

    public function testTheSweepLeavesARunningYoloStepAlone(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $task = $this->setState($store, State::InProgress, ['prNumber' => null, 'yolo' => true, 'yoloStep' => Agent::YoloImplement]);
        $task->agentLaunches['yolo-implement'] = new AgentLaunch(Agent::YoloImplement, $this->freshTs(Poller::LAUNCH_WINDOW_S * 2), 1, sessionId: 'ses_plan');
        $store->save($task);

        $this->poll($cfg, $store);

        $this->assertSame([], $this->agents->followUps);
        $this->assertNull($this->taskOrFail($store)->agentLaunches['yolo-implement']->finishedAt);
    }

    public function testRequestChangesStaysWhenPrFeedbackStillRunning(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::RequestChanges));
        $this->setLaunch($store, 'pr-feedback', finishedAt: null);
        $this->gh->verdict = null;
        $this->poll($cfg, $store);
        $this->assertSame(State::RequestChanges, $this->taskOrFail($store)->state);
        $this->assertSame([], $this->gh->readies);
    }

    public function testRequestChangesStaysWhenUnresolvedFeedbackRemains(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::RequestChanges));
        $this->setLaunch($store, 'pr-feedback', finishedAt: '2026-07-22T00:00:00+00:00', reported: true);
        $this->gh->verdict = 'changes';
        $this->poll($cfg, $store);
        $this->assertSame(State::RequestChanges, $this->taskOrFail($store)->state);
        $this->assertSame([], $this->gh->readies);
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
        $this->assertSame(['task-feedback'], $this->agents->launch);

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
        $this->assertSame(['task-feedback'], $this->agents->launch);
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
        $this->gh->verdict = 'changes';
        $this->poll($cfg, $store);
        $this->assertSame(State::Waiting, $this->taskOrFail($store)->state);

        $this->gh->merged = true;
        $this->poll($cfg, $store);
        $this->assertNull($this->getTask($store));
        $this->assertSame(['pr-1'], array_column($this->git->removed, 1));
    }

    public function testCloseSurvivesMissingWorktree(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->gh->merged = true;
        $this->git->removeThrows = 'git worktree remove failed: fatal: not a working tree';
        $events = $this->poll($cfg, $store);
        $this->assertNull($this->getTask($store));
        $this->assertStringContainsString('worktree already gone', implode("\n", $events));
    }

    public function testMergeWithActiveAgentsDefersClose(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->gh->merged = true;
        $this->agents->active = [new SessionInfo('a', 'running')];
        $this->poll($cfg, $store);
        $this->assertTrue($this->taskOrFail($store)->merged);

        $this->stubs['ci'] = 'red';
        $this->poll($cfg, $store);
        $this->assertSame(State::Draft, $this->taskOrFail($store)->state);

        $this->agents->active = [];
        $this->poll($cfg, $store);
        $this->assertNull($this->getTask($store));
        $this->assertSame(['pr-1'], array_column($this->git->removed, 1));
    }

    public function testMergeImmediateCloseRecordsMergedTrue(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->gh->merged = true;

        // No active agent sessions: the poller takes the immediate-close
        // branch, and the emitted task_closed event must carry merged=true.
        $analytics = new FakeAnalytics();
        $poller = $this->analyticsPoller($analytics);
        $events = $poller->pollProject($cfg, $store, $this->agents);

        $this->assertNull($this->getTask($store));
        $this->assertStringContainsString('PR merged', implode("\n", $events));
        $this->assertCount(1, $analytics->closed);
        $this->assertTrue($analytics->closed[0]->merged);
        $this->assertSame(7, $analytics->closed[0]->prNumber);
    }

    public function testClosedPrParksTaskInClosedAndKeepsWorktree(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::Waiting));
        $this->gh->prStateOverride = static fn (): string => 'CLOSED';
        // Running agents don't matter: nothing is removed.
        $this->agents->active = [new SessionInfo('a', 'running')];

        $analytics = new FakeAnalytics();
        $events = $this->analyticsPoller($analytics)->pollProject($cfg, $store, $this->agents);

        $task = $this->taskOrFail($store);
        $this->assertSame(State::Closed, $task->state);
        $this->assertFalse($task->merged);
        $this->assertSame([], $this->git->removed);
        $this->assertSame([], $analytics->closed);
        $this->assertStringContainsString('PR closed → closed', implode("\n", $events));

        // A second poll is a no-op: the state is not re-entered.
        $entered = $task->stateEnteredAt;
        $this->assertSame([], array_values(array_filter(
            $this->poll($cfg, $store),
            static fn (string $ev): bool => str_contains($ev, '→'),
        )));
        $this->assertSame($entered, $this->taskOrFail($store)->stateEnteredAt);
    }

    public function testReopenedPrSendsClosedTaskBackToDraft(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::Closed));
        $this->gh->prStateOverride = static fn (): string => 'OPEN';

        $events = $this->poll($cfg, $store);

        $this->assertSame(State::Draft, $this->taskOrFail($store)->state);
        $this->assertStringContainsString('PR reopened → draft', implode("\n", $events));
    }

    public function testClosedTaskStillAutoClosesOnMerge(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::Closed));
        $this->gh->merged = true;

        $this->poll($cfg, $store);

        $this->assertNull($this->getTask($store));
        $this->assertSame(['pr-1'], array_column($this->git->removed, 1));
    }

    public function testPrNumberDiscoveredForDraft(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::Draft));
        $this->setState($store, State::Draft, ['prNumber' => null]);
        $this->gh->onPrForBranch = static fn (): PrInfo => new PrInfo(9, 't', 'OPEN', true, 'u', null);
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
        $this->gh->onPrForBranch = static fn (): PrInfo => new PrInfo(7, 't', 'OPEN', true, 'u', null);
        $this->provider->issueStatus = 'In Progress';
        $this->agents->active = [new SessionInfo('a', 'running')];
        $this->poll($cfg, $store);
        $task = $this->taskOrFail($store);
        $this->assertSame('In Progress', $task->displayCache->trackerStatus);
        $this->assertSame('📝 draft #7', $task->displayCache->prState);
        $this->assertSame(1, $task->displayCache->agentCount);
        $this->assertSame('🏃 1', $task->displayCache->agentActivity);
        $this->assertNotNull($task->displayCache->at);
    }

    public function testPollKeepsCachedTrackerStatusWhenLookupIsUnresolved(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $task = $this->task($e['tmp']);
        $task->displayCache = new DisplayCache(trackerStatus: 'In Progress');
        $store->save($task);
        $this->provider->issueStatus = '?';
        $this->poll($cfg, $store);
        $unresolved = $this->taskOrFail($store);
        $this->assertSame('In Progress', $unresolved->displayCache->trackerStatus);

        $this->provider->issueStatus = '';
        $this->poll($cfg, $store);
        $empty = $this->taskOrFail($store);
        $this->assertSame('In Progress', $empty->displayCache->trackerStatus);
    }

    public function testPollOverwritesCachedTrackerStatusWhenLookupSucceeds(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $task = $this->task($e['tmp']);
        $task->displayCache = new DisplayCache(trackerStatus: 'In Progress');
        $store->save($task);
        $this->provider->issueStatus = 'QA Approved';
        $this->poll($cfg, $store);
        $this->assertSame('QA Approved', $this->taskOrFail($store)->displayCache->trackerStatus);
    }

    public function testPollDisplayCacheSurvivesClosedTask(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->gh->merged = true;
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
        $this->assertSame([], $this->agents->launch);
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
        $this->assertSame([], $this->agents->launch);
    }

    public function testHealedLaunchFiresTheAgentFinishedHookOnce(): void
    {
        $e = $this->newEnv();
        $base = $this->cfg($e['tmp']);
        $cfg = new ProjectConfig(
            name: $base->name,
            type: $base->type,
            repo: $base->repo,
            tracker: $base->tracker,
            sync: $base->sync,
            statePolling: new StatePollingConfig($base->statePolling->intervalMinutes),
            defaultModel: $base->defaultModel,
            prDescriptionLocale: 'en',
            testing: $base->testing,
            hooks: new HooksConfig(
                command: 'notify',
            ),
        );
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        $this->setLaunch($store, 'task-analyst', Poller::LAUNCH_MAX_ATTEMPTS, 301);

        $runner = new \Pablo\Tests\FakeHookRunner();
        $git = new \Pablo\Tests\FakeGit();
        $git->originUrl = 'git@github.com:acme/proj.git';
        $repoSlug = new RepoSlug($git);
        $time = new \Pablo\Domain\Time();
        $hooks = new \Pablo\Hooks\Hooks($runner, $repoSlug);
        $poller = new Poller($this->gh, $this->git, $this->providers, $repoSlug, new StateMachine($this->gh, $this->providers, $repoSlug, $time, $hooks, new FakeAnalytics()), $time, new \Pablo\Agents\Runtime\OpenCode\OpenCodeUsage($this->runner), $hooks, new FakeAnalytics());

        $poller->pollProject($cfg, $store, $this->agents);
        $this->assertSame(['agent-finished'], $runner->events());
        $this->assertSame('task-analyst', $runner->runs[0]['env']['PABLO_AGENT']);
        $this->assertTrue($this->taskOrFail($store)->agentLaunches['task-analyst']->reported);

        // Already reported: the next cycle stays silent.
        $poller->pollProject($cfg, $store, $this->agents);
        $this->assertCount(1, $runner->runs);
    }

    public function testHealedLaunchIsReportedExactlyOnce(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        $this->setLaunch($store, 'task-analyst', Poller::LAUNCH_MAX_ATTEMPTS, 301);

        $analytics = new FakeAnalytics();
        $poller = $this->analyticsPoller($analytics);
        $events = $poller->pollProject($cfg, $store, $this->agents);

        // The heal message is preserved...
        $healed = array_filter($events, static fn (string $e) => str_contains($e, 'finished → waiting for feedback'));
        $this->assertNotEmpty($healed);
        // ...and exactly one agent_run_finished event is emitted.
        $this->assertCount(1, $analytics->runsFinished);
        $record = $analytics->runsFinished[0];
        $this->assertSame('task-analyst', $record->agent);
        $this->assertSame('unknown', $record->backend);
        $this->assertNotNull($record->startedAt);
        $this->assertSame(301, $record->durationS);

        $launch = $this->taskOrFail($store)->agentLaunches['task-analyst'];
        $this->assertTrue($launch->reported);
        $this->assertNotNull($launch->runId);
        $this->assertNotNull($launch->finishedAt);

        // Second cycle: no duplicate event.
        $poller->pollProject($cfg, $store, $this->agents);
        $this->assertCount(1, $analytics->runsFinished);
    }

    public function testFinishedButUnreportedLaunchIsBackfilledWithoutRestamp(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        // A watcher died after stamping finishedAt but before reporting.
        $this->setLaunch($store, 'task-analyst', 1, 0, finishedAt: '2026-08-01T05:00:00+00:00');
        $seeded = $this->taskOrFail($store)->agentLaunches['task-analyst'];

        $analytics = new FakeAnalytics();
        $poller = $this->analyticsPoller($analytics);
        $events = $poller->pollProject($cfg, $store, $this->agents);

        $this->assertCount(1, $analytics->runsFinished);
        $record = $analytics->runsFinished[0];
        $this->assertSame('2026-08-01T05:00:00+00:00', $record->finishedAt);
        $this->assertSame($seeded->launchedAt, $record->startedAt);
        $this->assertIsInt($record->durationS);
        $this->assertTrue($this->taskOrFail($store)->agentLaunches['task-analyst']->reported);
        // The original stamp must not be rewritten by the backfill.
        $this->assertSame($seeded->finishedAt, $this->taskOrFail($store)->agentLaunches['task-analyst']->finishedAt);
        // And no heal message: the run was already stamped before.
        $this->assertSame([], array_values(array_filter($events, static fn (string $e) => str_contains($e, 'waiting for feedback'))));
    }

    public function testReportedLaunchIsNeverReReportedBySweep(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        // The watcher already reported this launch (reported=true): the sweep
        // must not emit a second event for the same run.
        $this->setLaunch($store, 'task-analyst', 1, 0, finishedAt: '2026-08-01T05:00:00+00:00', reported: true);

        $analytics = new FakeAnalytics();
        $poller = $this->analyticsPoller($analytics);
        $poller->pollProject($cfg, $store, $this->agents);

        $this->assertSame([], $analytics->runsFinished);
    }

    public function testRelaunchesMissingAgentPastWindow(): void
    {
        foreach ([
            [State::InProgress, 'task-analyst', null],
            [State::CiRed, 'ci-analyst', 7],
            [State::RequestChanges, 'pr-feedback', 7],
            [State::TestingFailed, 'task-feedback', 7],
        ] as [$state, $label, $pr]) {
            $this->agents->launch = [];
            $e = $this->newEnv();
            $cfg = $this->cfg($e['tmp']);
            $store = new Store($e['tmp'].'/state');
            $store->save($this->task($e['tmp'], $state));
            $this->setState($store, $state, ['prNumber' => $pr]);
            $this->setLaunch($store, $label, 1, 301);
            $this->poll($cfg, $store);
            $this->assertSame([$label], $this->agents->launch);
            $task = $this->taskOrFail($store);
            $agent = Agent::from($label);
            $this->assertSame(2, $task->agentLaunches[$agent->value]->attempts);
        }
    }

    public function testInProgressRelaunchesStartupPastWindow(): void
    {
        $e = $this->newEnv();
        $cfg = new ProjectConfig(
            name: 'proj',
            type: 'work',
            repo: new RepoConfig(
                path: $e['tmp'].'/repo',
                primaryBranch: 'main',
                worktreesRoot: $e['tmp'].'/wt',
                startupScript: '/setup.sh',
            ),
            tracker: new TrackerConfig(
                provider: 'github',
                identity: 'octocat',
                projectKey: 'PR',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 30,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
            testing: new TestingConfig(
                failureSignal: 'qa-failed',
            ),
        );
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        $this->setLaunch($store, 'task-analyst', 1, 301);
        $this->setLaunch($store, 'startup-script', 1, 301);
        $this->agents->startupScript = &$this->stubs['startups'];
        $this->poll($cfg, $store);
        $this->assertSame(['task-analyst'], $this->agents->launch);
        $this->assertSame(['/setup.sh'], $this->stubs['startups']);
    }

    /**
     * The watcher stamps the startup script finished once it exits: with no
     * session left, the poller re-fires only the launch that never showed up.
     */
    public function testFinishedStartupScriptIsNotReRun(): void
    {
        $e = $this->newEnv();
        $cfg = new ProjectConfig(
            name: 'proj',
            type: 'work',
            repo: new RepoConfig(
                path: $e['tmp'].'/repo',
                primaryBranch: 'main',
                worktreesRoot: $e['tmp'].'/wt',
                startupScript: '/setup.sh',
            ),
            tracker: new TrackerConfig(
                provider: 'github',
                identity: 'octocat',
                projectKey: 'PR',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 30,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
            testing: new TestingConfig(
                failureSignal: 'qa-failed',
            ),
        );
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        $this->setLaunch($store, 'task-analyst', 1, 301);
        $this->setLaunch($store, 'startup-script', 1, 301, $this->freshTs(200), reported: true);
        $this->agents->startupScript = &$this->stubs['startups'];
        $this->poll($cfg, $store);
        $this->assertSame(['task-analyst'], $this->agents->launch);
        $this->assertSame([], $this->stubs['startups']);
        $this->assertSame(1, $this->taskOrFail($store)->agentLaunches['startup-script']->attempts);
    }

    public function testNoStartupScriptRelaunchOnceUnconfigured(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']); // no startup_script any more
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        $this->setLaunch($store, 'startup-script', 1, 301);
        $this->agents->startupScript = &$this->stubs['startups'];
        $this->poll($cfg, $store);
        $this->assertSame([], $this->stubs['startups']);
        $this->assertSame(1, $this->taskOrFail($store)->agentLaunches['startup-script']->attempts);
    }

    public function testNoRelaunchWhenFinishedAgentInOrca(): void
    {
        $e = $this->newEnv();
        $cfg = new ProjectConfig(
            name: 'proj',
            type: 'work',
            repo: new RepoConfig(
                path: $e['tmp'].'/repo',
                primaryBranch: 'main',
                worktreesRoot: $e['tmp'].'/wt',
                startupScript: '/setup.sh',
            ),
            tracker: new TrackerConfig(
                provider: 'github',
                identity: 'octocat',
                projectKey: 'PR',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 30,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
            testing: new TestingConfig(
                failureSignal: 'qa-failed',
            ),
        );
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        $this->setLaunch($store, 'task-analyst', 1, 301);
        $this->setLaunch($store, 'startup-script', 1, 301);
        $this->agents->startupScript = &$this->stubs['startups'];
        $this->agents->hasAnyAgent = true;
        $this->poll($cfg, $store);
        $this->assertSame([], $this->agents->launch);
        $this->assertSame([], $this->stubs['startups']);
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
        $this->assertSame([], $this->agents->launch);
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
        $this->assertSame(['1'], $this->agents->displayNames);
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
        $this->assertSame([], $this->agents->displayNames);
    }

    public function testSkipCiIgnoresRedCi(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::WaitingReview));
        $this->setState($store, State::WaitingReview, ['ciIgnored' => true]);
        $this->stubs['ci'] = 'red';
        $this->gh->verdict = null;
        $this->poll($cfg, $store);
        $this->assertSame(State::WaitingReview, $this->taskOrFail($store)->state);
        $this->assertSame([], $this->agents->launch);
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
        $sm = new StateMachine($this->gh, $this->providerRegistry(), new RepoSlug($this->git), new \Pablo\Domain\Time(), \Pablo\Tests\Registries::hooks());
        $sm->enterState($ctx, State::Draft);
        $this->assertFalse($task->ciIgnored);
    }

    /** @return list<list<string>> the git() argument lists of every rev-parse */
    private function revParses(): array
    {
        return array_values(array_filter(
            array_column($this->git->gitCalls, 1),
            static fn (array $args): bool => 'rev-parse' === ($args[0] ?? null),
        ));
    }

    public function testATaskResolvesItsBranchOnceAndReadsItsPrOnce(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->git->gitOutput = 'pr-1';
        $this->gh->prByBranch['pr-1'] = new PrInfo(7, 't', 'OPEN', true, 'u', null);

        $this->poll($cfg, $store);

        // Before: rev-parse twice, prState + prForBranch for the same PR.
        $this->assertCount(1, $this->revParses());
        $this->assertSame(['prForBranch:pr-1', 'ciStatus:7'], $this->gh->calls);
        $this->assertSame('📝 draft #7', $this->taskOrFail($store)->displayCache->prState);
    }

    public function testTheBranchPrAnswersTheMergeCheck(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->git->gitOutput = 'pr-1';
        $this->gh->prByBranch['pr-1'] = new PrInfo(7, 't', 'OPEN', false, 'u', '2026-07-22T00:00:00Z');

        $this->poll($cfg, $store);

        // A mergedAt makes it MERGED whatever its state says, as prState() does.
        $this->assertNull($this->getTask($store));
        $this->assertSame(['prForBranch:pr-1'], $this->gh->calls);
    }

    public function testAnotherPrOnTheBranchStillAsksForTheTasksPrState(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->git->gitOutput = 'pr-1';
        // The branch's newest PR is not the task's: its state says nothing of #7.
        $this->gh->prByBranch['pr-1'] = new PrInfo(9, 't', 'MERGED', false, 'u', '2026-07-22T00:00:00Z');

        $this->poll($cfg, $store);

        $this->assertSame(['prForBranch:pr-1', 'prState:7', 'ciStatus:7'], $this->gh->calls);
        $this->assertSame(State::Draft, $this->taskOrFail($store)->state);
        $this->assertSame('✅ merged #9', $this->taskOrFail($store)->displayCache->prState);
    }

    public function testAnUndiscoveredPrIsLookedUpOnce(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $task = $this->task($e['tmp']);
        $task->prNumber = null;
        $store->save($task);
        $this->git->gitOutput = 'pr-1';
        $this->gh->prByBranch['pr-1'] = new PrInfo(9, 't', 'OPEN', true, 'u', null);

        $this->poll($cfg, $store);

        $this->assertSame(9, $this->taskOrFail($store)->prNumber);
        $this->assertSame(['prForBranch:pr-1', 'ciStatus:9'], $this->gh->calls);
        $this->assertSame('📝 draft #9', $this->taskOrFail($store)->displayCache->prState);
    }

    public function testTheDisplayCacheReadsThePrAgainAfterATransition(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::CiRed));
        $this->git->gitOutput = 'pr-1';
        $this->stubs['ci'] = 'green';
        // Draft on GitHub until ready-to-review marks it ready.
        $this->gh->onPrForBranch = fn (): PrInfo => new PrInfo(7, 't', 'OPEN', [] === $this->gh->readies, 'u', null);

        $this->poll($cfg, $store);

        $task = $this->taskOrFail($store);
        $this->assertSame(State::WaitingReview, $task->state);
        $this->assertSame(['prForBranch:pr-1', 'ciStatus:7', 'prForBranch:pr-1'], $this->gh->calls);
        $this->assertSame('📖 open #7', $task->displayCache->prState);
    }

    public function testAFailedBranchLookupFallsBackToPrStateAndIsRetriedForTheDisplay(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $task = $this->task($e['tmp']);
        $task->displayCache = new DisplayCache(prState: '📝 draft #7');
        $store->save($task);
        $this->git->gitOutput = 'pr-1';
        $this->gh->onPrForBranch = static function (): never {
            throw new \Pablo\Support\PabloError('gh timed out');
        };
        $this->stubs['ci'] = 'red';

        $events = $this->poll($cfg, $store);

        $this->assertSame(State::CiRed, $this->taskOrFail($store)->state);
        $this->assertSame(['prForBranch:pr-1', 'prState:7', 'ciStatus:7', 'prForBranch:pr-1'], $this->gh->calls);
        $this->assertSame(0, $this->poller->lastCheckFailures());
        $this->assertSame('📝 draft #7', $this->taskOrFail($store)->displayCache->prState);
        $this->assertStringNotContainsString('failed', implode("\n", $events));
    }

    public function testAnUnresolvableRepositoryFailsEachTaskAsBefore(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $second = new Task('proj', 'pr-2', $e['tmp'].'/wt/pr-2', State::WaitingReview);
        $second->prNumber = 8;
        $store->save($second);
        $noOrigin = new \Pablo\Tests\FakeGit(); // no origin remote: no slug
        $repoSlug = new RepoSlug($noOrigin);
        $time = new \Pablo\Domain\Time();
        $poller = new Poller($this->gh, $this->git, $this->providers, $repoSlug, new StateMachine($this->gh, $this->providers, $repoSlug, $time, \Pablo\Tests\Registries::hooks()), $time, new \Pablo\Agents\Runtime\OpenCode\OpenCodeUsage($this->runner), \Pablo\Tests\Registries::hooks());

        $events = $poller->pollProject($cfg, $store, $this->agents);

        // Each task fails on its own, both its checks and its display cache.
        $this->assertSame(2, $poller->lastCheckFailures());
        foreach (['pr-1', 'pr-2'] as $branch) {
            $this->assertContains("{$branch}: poll check failed: proj: repo at {$e['tmp']}/repo has no origin remote", $events);
            $this->assertContains("{$branch}: display cache refresh failed: proj: repo at {$e['tmp']}/repo has no origin remote", $events);
        }
        $this->assertSame([], $this->gh->calls);
    }

    /** @param list<CiCheck> $rollup */
    private function snapshotOf(PrInfo $branchPr, array $rollup, ?ReviewSnapshot $reviews = null): PrSnapshot
    {
        return new PrSnapshot(
            branchPrs: ['pr-1' => $branchPr],
            states: [$branchPr->number => $branchPr->state],
            rollups: [$branchPr->number => $rollup],
            reviews: null !== $reviews ? [$branchPr->number => $reviews] : [],
        );
    }

    public function testTheBatchAnswersEveryReadOfATask(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->git->gitOutput = 'pr-1';
        $this->gh->snapshot = $this->snapshotOf(
            new PrInfo(7, 't', 'OPEN', true, 'u', null),
            [new CiCheck('test', failed: false, pending: true, runKey: "CI\0test")],
        );

        $this->poll($cfg, $store);

        $this->assertSame([['branches' => ['pr-1'], 'prs' => [7], 'reviews' => []]], $this->gh->snapshotCalls);
        $this->assertSame([], $this->gh->calls); // no per-task gh call at all
        $this->assertCount(1, $this->revParses());
        $this->assertSame(State::Draft, $this->taskOrFail($store)->state);
        $this->assertSame('📝 draft #7', $this->taskOrFail($store)->displayCache->prState);
    }

    public function testTheBatchedRollupHonoursIgnoreChecks(): void
    {
        $e = $this->newEnv();
        $base = $this->cfg($e['tmp']);
        $cfg = new ProjectConfig(
            name: $base->name, type: $base->type, repo: $base->repo, tracker: $base->tracker, sync: $base->sync,
            statePolling: $base->statePolling, defaultModel: $base->defaultModel, prDescriptionLocale: 'en',
            testing: $base->testing, ci: new \Pablo\Config\CiConfig(ignoreChecks: ['hold']),
        );
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::CiRed));
        $this->git->gitOutput = 'pr-1';
        $this->gh->snapshot = $this->snapshotOf(new PrInfo(7, 't', 'OPEN', true, 'u', null), [
            new CiCheck('ci/circleci: hold', failed: false, pending: true),
            new CiCheck('build', failed: true, pending: false, runKey: "CI\0build", startedAt: '2026-07-22T10:00:00Z'),
            new CiCheck('build', failed: false, pending: false, runKey: "CI\0build", startedAt: '2026-07-22T10:05:00Z'),
        ]);

        $this->poll($cfg, $store);

        // The superseded failed run and the ignored hold are left out: green.
        $this->assertSame(State::WaitingReview, $this->taskOrFail($store)->state);
        $this->assertSame([7], $this->gh->readies);
        // Only the display reads the PR again, after the transition.
        $this->assertSame(['prForBranch:pr-1'], $this->gh->calls);
    }

    public function testTheBatchedReviewsDecideTheReviewCheck(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::WaitingReview));
        $this->git->gitOutput = 'pr-1';
        $anchor = new \DateTimeImmutable('2026-07-20T12:00:00Z');
        $this->gh->snapshot = $this->snapshotOf(
            new PrInfo(7, 't', 'OPEN', false, 'u', null),
            [],
            new ReviewSnapshot(new PrReviews('me', [
                new ReviewNode('bob', 'User', 'CHANGES_REQUESTED', '2026-07-10T00:00:00Z', 'r1'), // previous round
                new ReviewNode('alice', 'User', 'APPROVED', '2026-07-21T00:00:00Z', 'r2'),
            ]), $anchor),
        );

        $this->poll($cfg, $store);

        $this->assertSame([7], $this->gh->snapshotCalls[0]['reviews']);
        $this->assertSame(State::NeedsTesting, $this->taskOrFail($store)->state);
        $this->assertSame(['prForBranch:pr-1'], $this->gh->calls);
    }

    public function testOnlyTheStatesThatReadReviewsAskForThem(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $finished = new AgentLaunch(Agent::PrFeedback, $this->freshTs(600), 1, $this->freshTs(300), reported: true);
        $running = new AgentLaunch(Agent::PrFeedback, $this->freshTs(60), 1);
        foreach ([
            ['a', State::Draft, 1, false, []],
            ['b', State::WaitingReview, 2, false, []],
            ['c', State::RequestChanges, 3, false, [Agent::PrFeedback->value => $finished]],
            ['d', State::RequestChanges, 4, false, [Agent::PrFeedback->value => $running]],
            ['e', State::Approved, 5, false, []],
            ['f', State::WaitingReview, 6, true, []],   // merged: only its display is read
            ['g', State::InProgress, null, false, []],
        ] as [$branch, $state, $pr, $merged, $launches]) {
            $task = new Task('proj', $branch, $e['tmp'].'/wt/'.$branch, $state);
            $task->prNumber = $pr;
            $task->merged = $merged;
            $task->agentLaunches = $launches;
            $store->save($task);
        }
        $this->git->gitHandler = static fn (): string => 'checked-out';
        $this->agents->active = [new SessionInfo('a', 'running')]; // keeps f from closing

        $this->poll($cfg, $store);

        $this->assertCount(1, $this->gh->snapshotCalls);
        $call = $this->gh->snapshotCalls[0];
        $this->assertCount(7, $call['branches']);
        $this->assertSame([1, 2, 3, 4, 5], $call['prs']);
        $this->assertSame([2, 3], $call['reviews']);
    }

    public function testAPrMissingFromTheBatchIsReadOnItsOwn(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::WaitingReview));
        $this->git->gitOutput = 'pr-1';
        // The batch only resolved the branch: no state, rollup or reviews for #7.
        $this->gh->snapshot = new PrSnapshot(branchPrs: ['pr-1' => new PrInfo(7, 't', 'OPEN', false, 'u', null)]);
        $this->stubs['ci'] = 'green';
        $this->gh->verdict = 'changes';

        $this->poll($cfg, $store);

        $this->assertSame(State::RequestChanges, $this->taskOrFail($store)->state);
        // prState still comes from the branch's PR; CI and reviews per PR; the display re-reads after the transition.
        $this->assertSame(['ciStatus:7', 'reviewVerdict:7', 'prForBranch:pr-1'], $this->gh->calls);
    }

    public function testAFailedBatchFallsBackToThePerTaskCalls(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->git->gitOutput = 'pr-1';
        $this->gh->prByBranch['pr-1'] = new PrInfo(7, 't', 'OPEN', true, 'u', null);
        $this->gh->onPollSnapshot = static function (): never {
            throw new \RuntimeException('gh exploded');
        };

        $events = $this->poll($cfg, $store);

        $this->assertSame(['prForBranch:pr-1', 'ciStatus:7'], $this->gh->calls);
        $this->assertSame(0, $this->poller->lastCheckFailures());
        $this->assertSame([], $events);
        $this->assertSame('📝 draft #7', $this->taskOrFail($store)->displayCache->prState);
    }

    public function testATransitionDropsTheBatchedAnswersOfThatPr(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::CiRed));
        // A second task on the same PR (a mis-attached worktree): polled after
        // the first one marked the PR ready, it must not read the old batch.
        $twin = new Task('proj', 'pr-2', $e['tmp'].'/wt/pr-2', State::Draft);
        $twin->prNumber = 7;
        $store->save($twin);
        $this->git->gitOutput = 'pr-1';
        $this->gh->snapshot = $this->snapshotOf(new PrInfo(7, 't', 'OPEN', true, 'u', null), []);
        $this->gh->onPrForBranch = fn (): PrInfo => new PrInfo(7, 't', 'OPEN', [] === $this->gh->readies, 'u', null);
        $this->stubs['ci'] = 'pending';

        $this->poll($cfg, $store);

        $this->assertSame(State::WaitingReview, $this->taskOrFail($store)->state);
        $twin = $store->get('proj', 'pr-2');
        $this->assertNotNull($twin);
        $this->assertSame(State::Draft, $twin->state); // pending, read live
        // pr-2's CI is read live (the batch said green), its branch PR is the one re-read after pr-1's transition.
        $this->assertSame(['prForBranch:pr-1', 'ciStatus:7'], $this->gh->calls);
        $this->assertSame('📖 open #7', $twin->displayCache->prState);
    }

    public function testCountsFailedTaskChecks(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $task = $this->task($e['tmp']);
        $task->prNumber = null;
        $store->save($task);
        $this->gh->onPrForBranch = static function (): never {
            throw new \RuntimeException('gh exploded');
        };

        $events = $this->poll($cfg, $store);

        $this->assertStringContainsString('pr-1: poll check failed: gh exploded', implode("\n", $events));
        $this->assertSame(1, $this->poller->lastCheckFailures());

        $this->gh->onPrForBranch = null;
        $this->poll($cfg, $store);
        $this->assertSame(0, $this->poller->lastCheckFailures());
    }
}
