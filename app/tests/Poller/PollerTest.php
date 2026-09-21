<?php

declare(strict_types=1);

namespace Pablo\Tests\Poller;

use Pablo\Agents\SessionInfo;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\DisplayCache;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Poller\Poller;
use Pablo\Provider\Gh\PrInfo;
use Pablo\Provider\Tracker\Provider;
use Pablo\StateMachine\StateMachine;
use Pablo\StateMachine\TaskCtx;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Pablo\Tests\FakeAgents;
use Pablo\Tests\FakeAnalytics;
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
            'merged' => false,
            'verdict' => null,
            'sessions' => [],
            'events' => [],
            'removed' => [],
            'launched' => [],
            'startups' => [],
            'display_names' => [],
        ];
        $this->gh = new \Pablo\Tests\FakeGhPr();
        $this->gh->onCiStatus = fn () => $this->stubs['ci'];
        $this->gh->isMergedOverride = fn () => $this->stubs['merged'];
        $this->gh->evaluateReviewsOverride = fn () => $this->stubs['verdict'];
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
        $sm = new StateMachine($this->gh, $this->providers, $repoSlug, $time);
        $this->poller = new Poller($this->gh, $this->git, $this->providers, $repoSlug, $sm, $time);
    }

    /** A poller whose analytics/usage collaborators are test doubles. */
    private function analyticsPoller(FakeAnalytics $analytics): Poller
    {
        $git = new \Pablo\Tests\FakeGit();
        $git->originUrl = 'git@github.com:acme/proj.git';
        $repoSlug = new RepoSlug($git);
        $time = new \Pablo\Domain\Time();
        $sm = new StateMachine($this->gh, $this->providers, $repoSlug, $time);

        return new Poller($this->gh, $this->git, $this->providers, $repoSlug, $sm, $time, $analytics, new \Pablo\Analytics\OpenCodeUsage($this->runner));
    }

    protected function tearDown(): void
    {
    }

    /** @return array{tmp: string} */
    private function newEnv(): array
    {
        $tmp = sys_get_temp_dir().'/pablo-poll-'.uniqid();
        mkdir($tmp, 0o777, true);

        return ['tmp' => $tmp];
    }

    private function cfg(string $tmp, bool $testingEnabled = true): ProjectConfig
    {
        return new ProjectConfig(
            name: 'proj',
            type: 'work',
            repoPath: $tmp.'/repo',
            primaryBranch: 'main',
            worktreesRoot: $tmp.'/wt',
            provider: 'github',
            identity: 'octocat',
            projectKey: 'PR',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: 'qa-failed',
            testingEnabled: $testingEnabled,
            botWhitelist: [],
            ciIgnoreChecks: [],
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
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
        $agent = Agent::tryByName($label) ?? Agent::TaskAnalyst;
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
        $this->stubs['verdict'] = 'approved';
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
        $this->stubs['verdict'] = 'approved';
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
        $this->stubs['verdict'] = 'approved';
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
        $this->stubs['verdict'] = 'approved';
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
        $this->stubs['verdict'] = 'changes';
        $this->poll($cfg, $store);
        $this->assertSame(State::RequestChanges, $this->taskOrFail($store)->state);
        $this->assertSame(['pr-feedback'], $this->agents->launch);
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
        $this->stubs['verdict'] = 'changes';
        $this->poll($cfg, $store);
        $this->assertSame(State::Waiting, $this->taskOrFail($store)->state);

        $this->stubs['merged'] = true;
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
        $this->stubs['merged'] = true;
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
        $this->assertSame(['pr-1'], array_column($this->git->removed, 1));
    }

    public function testMergeImmediateCloseRecordsMergedTrue(): void
    {
        $e = $this->newEnv();
        $cfg = $this->cfg($e['tmp']);
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp']));
        $this->stubs['merged'] = true;

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
        $this->assertSame('In Progress', $this->taskOrFail($store)->displayCache->trackerStatus);

        $this->provider->issueStatus = '';
        $this->poll($cfg, $store);
        $this->assertSame('In Progress', $this->taskOrFail($store)->displayCache->trackerStatus);
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
            identity: 'octocat',
            projectKey: 'PR',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: 'qa-failed',
            botWhitelist: [],
            ciIgnoreChecks: [],
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
            startupScript: '/setup.sh',
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

    public function testNoRelaunchWhenFinishedAgentInOrca(): void
    {
        $e = $this->newEnv();
        $cfg = new ProjectConfig(
            name: 'proj',
            type: 'work',
            repoPath: $e['tmp'].'/repo',
            primaryBranch: 'main',
            worktreesRoot: $e['tmp'].'/wt',
            provider: 'github',
            identity: 'octocat',
            projectKey: 'PR',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: 'qa-failed',
            botWhitelist: [],
            ciIgnoreChecks: [],
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
            startupScript: '/setup.sh',
        );
        $store = new Store($e['tmp'].'/state');
        $store->save($this->task($e['tmp'], State::InProgress));
        $this->setState($store, State::InProgress, ['prNumber' => null]);
        $this->setLaunch($store, 'task-analyst', 1, 301);
        $this->setLaunch($store, 'startup-script', 1, 301);
        $this->agents->startupScript = &$this->stubs['startups'];
        $this->agents->hasAnyOrcaAgent = true;
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
        $this->stubs['verdict'] = null;
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
        $sm = new StateMachine($this->gh, $this->providerRegistry(), new RepoSlug($this->git), new \Pablo\Domain\Time());
        $sm->enterState($ctx, State::Draft);
        $this->assertFalse($task->ciIgnored);
    }
}
