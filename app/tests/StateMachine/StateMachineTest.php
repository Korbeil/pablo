<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\StateMachine\StateMachine;
use Pablo\StateMachine\TaskCtx;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase
{
    private string $root;
    private ProjectConfig $cfg;
    private Task $task;
    private Store $store;
    private FakeAgents $agents;
    private TaskCtx $ctx;
    private StateMachine $sm;
    private FakeGhPr $gh;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/pablo-state-'.uniqid();
        $this->cfg = new ProjectConfig(
            name: 'wallet-kit',
            type: 'open-source',
            repoPath: '/dev/null/repo',
            primaryBranch: 'main',
            worktreesRoot: '/dev/null/wt',
            provider: 'github',
            identity: 'octocat',
            projectKey: 'WK',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: 'qa-failed',
            botWhitelist: [],
            ciIgnoreChecks: [],
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
        $this->task = new Task(
            project: 'wallet-kit',
            branch: 'wk-45',
            worktreePath: '/dev/null/wt/wk-45',
            state: State::InProgress,
        );
        $this->task->prNumber = 7;
        $this->task->issue = new Issue('github', '45', 'u', 'T', 'WK');
        $this->store = new Store($this->root.'/state');
        $this->agents = new FakeAgents();
        $this->ctx = new TaskCtx(task: $this->task, cfg: $this->cfg, store: $this->store, agents: $this->agents);
        $this->gh = new FakeGhPr();
        $providers = new class implements \Pablo\Provider\Tracker\ProviderRegistryInterface {
            public function get(string $name): \Pablo\Provider\Tracker\Provider
            {
                throw new \LogicException('not used');
            }
        };
        $git = new FakeGit();
        $git->originUrl = 'git@github.com:acme/wallet-kit.git';
        $repoSlug = new RepoSlug($git);
        $this->sm = new StateMachine($this->gh, $providers, $repoSlug, new Time());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testEnterInProgressRunsAnalystOnce(): void
    {
        $this->sm->enterState($this->ctx, State::InProgress);
        $this->assertCount(1, $this->agents->launch);
        $this->assertSame('task-analyst', $this->agents->launch[0]);
        $this->assertTrue($this->task->taskAnalystRan);
        $this->sm->enterState($this->ctx, State::InProgress); // run-once
        $this->assertCount(1, $this->agents->launch);
    }

    public function testEnterInProgressStampsAnalystLaunch(): void
    {
        $this->sm->enterState($this->ctx, State::InProgress);
        $rec = $this->task->agentLaunches['task-analyst'];
        $this->assertNotNull($rec->launchedAt);
        $this->assertSame(1, $rec->attempts);
        $this->sm->enterState($this->ctx, State::InProgress);
        $this->assertSame(1, $this->task->agentLaunches['task-analyst']->attempts);
    }

    public function testEnterInProgressSkipsStartupScriptWhenUnset(): void
    {
        $this->sm->enterState($this->ctx, State::InProgress);
        $this->assertSame([], $this->agents->startupScript);
        $this->assertFalse($this->task->startupScriptRan);
    }

    public function testEnterInProgressRunsStartupScriptOnce(): void
    {
        $this->cfg = new ProjectConfig(
            name: $this->cfg->name,
            type: $this->cfg->type,
            repoPath: $this->cfg->repoPath,
            primaryBranch: $this->cfg->primaryBranch,
            worktreesRoot: $this->cfg->worktreesRoot,
            provider: $this->cfg->provider,
            identity: $this->cfg->identity,
            projectKey: $this->cfg->projectKey,
            syncStrategy: $this->cfg->syncStrategy,
            syncAutoApply: $this->cfg->syncAutoApply,
            syncInterval: $this->cfg->syncInterval,
            pollInterval: $this->cfg->pollInterval,
            failureSignal: $this->cfg->failureSignal,
            botWhitelist: $this->cfg->botWhitelist,
            ciIgnoreChecks: $this->cfg->ciIgnoreChecks,
            defaultModel: $this->cfg->defaultModel,
            prDescriptionLocale: $this->cfg->prDescriptionLocale,
            startupScript: '/dev/null/setup.sh',
        );
        $this->ctx->cfg = $this->cfg;
        $this->sm->enterState($this->ctx, State::InProgress);
        $this->assertSame(['/dev/null/setup.sh'], $this->agents->startupScript);
        $this->assertTrue($this->task->startupScriptRan);
        $this->assertSame(1, $this->task->agentLaunches['startup-script']->attempts);
        $this->sm->enterState($this->ctx, State::InProgress);
        $this->assertCount(1, $this->agents->startupScript);
    }

    public function testWaitingSavesAndRestoresPreviousState(): void
    {
        $this->task->state = State::WaitingReview;
        $this->sm->toggleWaiting($this->ctx);
        $this->assertSame(State::Waiting, $this->task->state);
        $this->assertSame(State::WaitingReview, $this->task->stateBeforeWaiting);
        $this->sm->toggleWaiting($this->ctx);
        $this->assertSame(State::WaitingReview, $this->task->state);
        $this->assertNull($this->task->stateBeforeWaiting);
    }

    public function testWaitingForbiddenFromRequestChangesAndTestingFailed(): void
    {
        foreach ([State::RequestChanges, State::TestingFailed] as $forbidden) {
            $this->task->state = $forbidden;
            $this->expectToFailImpossible(
                fn () => $this->sm->toggleWaiting($this->ctx),
            );
            $this->task->state = $forbidden;
            $this->expectToFailImpossible(
                fn () => $this->sm->enterState($this->ctx, State::Waiting),
            );
        }
    }

    private function expectToFailImpossible(callable $fn): void
    {
        try {
            $fn();
            $this->fail('expected PabloError');
        } catch (PabloError $e) {
            $this->assertStringContainsString('impossible', $e->getMessage());
        }
    }

    public function testNoTriggerSkipsActionsExceptWaiting(): void
    {
        $this->sm->enterState($this->ctx, State::RequestChanges, trigger: false);
        $this->assertSame([], $this->agents->launch);
        $this->assertSame([], $this->gh->drafts);
        $this->task->state = State::Draft;
        $this->sm->enterState($this->ctx, State::Waiting, trigger: false);
        $this->assertSame(State::Draft, $this->task->stateBeforeWaiting);
    }

    public function testReadyToReviewChainsToWaitingReviewAndMarksReady(): void
    {
        $this->task->state = State::Draft;
        $this->sm->enterState($this->ctx, State::ReadyToReview);
        $this->assertSame([7], $this->gh->readies);
        $this->assertSame(State::WaitingReview, $this->task->state);
    }

    public function testRequestChangesLaunchesPlannerThenDraftsPr(): void
    {
        $this->task->state = State::WaitingReview;
        $this->sm->enterState($this->ctx, State::RequestChanges);
        $this->assertSame('pr-feedback', $this->agents->launch[0]);
        $this->assertSame([7], $this->gh->drafts);
        $this->assertSame(1, $this->task->agentLaunches['pr-feedback']->attempts);
    }

    public function testCiRedStampsLaunch(): void
    {
        $this->task->state = State::Draft;
        $this->sm->enterState($this->ctx, State::CiRed);
        $this->assertNotNull($this->task->agentLaunches['ci-analyst']->launchedAt);
        $this->assertSame(1, $this->task->agentLaunches['ci-analyst']->attempts);
    }

    public function testCiRedRunsAnalyst(): void
    {
        $this->task->state = State::Draft;
        $this->sm->enterState($this->ctx, State::CiRed);
        $this->assertSame('ci-analyst', $this->agents->launch[0]);
        $this->assertSame([], $this->gh->drafts);
        $this->sm->enterState($this->ctx, State::ReadyToReview);
        $this->sm->enterState($this->ctx, State::CiRed);
        $this->assertCount(2, $this->agents->launch);
        $this->assertSame('ci-analyst', $this->agents->launch[1]);
    }

    public function testTestingFailedLaunchesFeedbackThenDraftsPr(): void
    {
        $this->task->state = State::NeedsTesting;
        $this->sm->enterState($this->ctx, State::TestingFailed);
        $this->assertSame('task-feedback', $this->agents->launch[0]);
        $this->assertSame([7], $this->gh->drafts);
        $this->assertSame(1, $this->task->agentLaunches['task-feedback']->attempts);
    }

    public function testNeedsTestingStampsBaseline(): void
    {
        $this->task->state = State::WaitingReview;
        $this->sm->enterState($this->ctx, State::NeedsTesting);
        $this->assertNotNull($this->task->needsTestingEnteredAt);
    }

    public function testNeedsTestingRestoreFromWaitingKeepsBaseline(): void
    {
        $this->task->state = State::WaitingReview;
        $this->sm->enterState($this->ctx, State::NeedsTesting);
        $baseline = $this->task->needsTestingEnteredAt;
        $this->sm->toggleWaiting($this->ctx);
        $this->sm->toggleWaiting($this->ctx);
        $this->assertSame(State::NeedsTesting, $this->task->state);
        $this->assertSame($baseline, $this->task->needsTestingEnteredAt);
    }

    // -------------------------------------------------- testing disabled ---

    private function ctxWithTestingDisabled(): TaskCtx
    {
        $cfg = new ProjectConfig(
            name: $this->cfg->name,
            type: $this->cfg->type,
            repoPath: $this->cfg->repoPath,
            primaryBranch: $this->cfg->primaryBranch,
            worktreesRoot: $this->cfg->worktreesRoot,
            provider: $this->cfg->provider,
            identity: $this->cfg->identity,
            projectKey: $this->cfg->projectKey,
            syncStrategy: $this->cfg->syncStrategy,
            syncAutoApply: $this->cfg->syncAutoApply,
            syncInterval: $this->cfg->syncInterval,
            pollInterval: $this->cfg->pollInterval,
            failureSignal: $this->cfg->failureSignal,
            testingEnabled: false,
            botWhitelist: $this->cfg->botWhitelist,
            ciIgnoreChecks: $this->cfg->ciIgnoreChecks,
            defaultModel: $this->cfg->defaultModel,
            prDescriptionLocale: $this->cfg->prDescriptionLocale,
        );

        return new TaskCtx(task: $this->task, cfg: $cfg, store: $this->store, agents: $this->agents);
    }

    public function testApprovedChainsToNeedsTestingWhenTestingEnabled(): void
    {
        $this->task->state = State::WaitingReview;
        $this->sm->enterState($this->ctx, State::Approved);
        $this->assertSame(State::NeedsTesting, $this->task->state);
        $this->assertNotNull($this->task->needsTestingEnteredAt);
    }

    public function testApprovedIsTerminalWhenTestingDisabled(): void
    {
        $ctx = $this->ctxWithTestingDisabled();
        $this->task->state = State::WaitingReview;
        $this->sm->enterState($ctx, State::Approved);
        $this->assertSame(State::Approved, $this->task->state);
        $this->assertNull($this->task->needsTestingEnteredAt);
    }

    public function testNeedsTestingRefusedWhenTestingDisabled(): void
    {
        $ctx = $this->ctxWithTestingDisabled();
        $this->task->state = State::WaitingReview;
        try {
            $this->sm->enterState($ctx, State::NeedsTesting);
            $this->fail('expected PabloError');
        } catch (PabloError $e) {
            $this->assertStringContainsString('testing.enabled: false', $e->getMessage());
        }
        $this->assertSame(State::WaitingReview, $this->task->state);
    }

    public function testNeedsTestingRestoreFromWaitingRefusedWhenTestingDisabled(): void
    {
        $ctx = $this->ctxWithTestingDisabled();
        $this->task->state = State::NeedsTesting;
        $this->task->stateBeforeWaiting = State::NeedsTesting;
        $this->task->state = State::Waiting;
        try {
            $this->sm->toggleWaiting($ctx);
            $this->fail('expected PabloError');
        } catch (PabloError $e) {
            $this->assertStringContainsString('testing.enabled: false', $e->getMessage());
        }
        $this->assertSame(State::Waiting, $this->task->state);
    }

    public function testEnterStatePersists(): void
    {
        $this->sm->enterState($this->ctx, State::Draft);
        $saved = $this->store->get('wallet-kit', 'wk-45');
        $this->assertNotNull($saved);
        $this->assertSame(State::Draft, $saved->state);
    }

    public function testUnknownStateRaises(): void
    {
        $bad = uniqid('not-a-state-', true);
        $this->assertNull(State::tryFrom($bad));
        $this->assertSame(State::InProgress, State::tryFrom('in-progress'));
    }

    public function testCommitAllowedSet(): void
    {
        $this->assertSame(
            [State::InProgress, State::CiRed, State::RequestChanges, State::TestingFailed, State::WaitingReview, State::Approved],
            StateMachine::COMMIT_ALLOWED_FROM,
        );
    }

    public function testAnalystPromptForPromptTask(): void
    {
        $this->task->issue = null;
        $this->task->prompt = 'fix callback verification in the webhook handler';
        $this->task->taskAnalystRan = false;
        $this->sm->enterState($this->ctx, State::InProgress);
        $prompt = $this->agents->launchPrompts[0];
        $this->assertStringContainsString('fix callback verification in the webhook handler', $prompt);
    }

    public function testEnterDraftResetsCiIgnored(): void
    {
        $this->task->ciIgnored = true;
        $this->task->state = State::WaitingReview;
        $this->sm->enterState($this->ctx, State::Draft);
        $this->assertFalse($this->task->ciIgnored);
    }

    public function testNeedsTestingSeedsLastSeenStatus(): void
    {
        $providers = new class implements \Pablo\Provider\Tracker\ProviderRegistryInterface {
            public function get(string $name): \Pablo\Provider\Tracker\Provider
            {
                return new FakeStatusProvider('A FIX');
            }
        };
        $git = new FakeGit();
        $git->originUrl = 'git@github.com:acme/wallet-kit.git';
        $this->sm = new StateMachine(new FakeGhPr(), $providers, new RepoSlug($git), new Time());
        $this->task->state = State::WaitingReview;
        $this->sm->enterState($this->ctx, State::NeedsTesting);
        $this->assertSame('A FIX', $this->task->lastSeenIssueStatus);
    }
}

final class FakeStatusProvider implements \Pablo\Provider\Tracker\Provider
{
    public function __construct(private string $status = 'ok')
    {
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

    public function listAssigned(ProjectConfig $cfg): array
    {
        return [];
    }

    public function issueStatus(string $key, ProjectConfig $cfg): string
    {
        return $this->status;
    }

    public function batchIssueStatus(array $issues): array
    {
        $result = [];
        foreach ($issues as $key => $_) {
            $result[$key] = $this->status;
        }

        return $result;
    }

    public function failureSignalEvents(Task $task, ProjectConfig $cfg): array
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
