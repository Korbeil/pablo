<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Tracker\ProviderRegistry;
use Pablo\StateMachine\StateMachine;
use Pablo\StateMachine\TaskCtx;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Support\Proc;
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

    /** @var array<int, int> */
    private array $drafts;

    /** @var array<int, int> */
    private array $readies;

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
        $this->drafts = [];
        $this->readies = [];
        RepoSlug::setFor(static fn () => 'acme/wallet-kit');
        Proc::setRunner(function (array $argv): string {
            // gh pr ready / ready --undo
            if ('gh' === $argv[0] && 'pr' === $argv[1] && 'ready' === $argv[2]) {
                if (\in_array('--undo', $argv, true)) {
                    $this->drafts[] = (int) $argv[3];
                } else {
                    $this->readies[] = (int) $argv[3];
                }
            }

            return '';
        });
    }

    protected function tearDown(): void
    {
        RepoSlug::setFor(null);
        Proc::setRunner(null);
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
        StateMachine::enterState($this->ctx, State::InProgress);
        $this->assertCount(1, $this->agents->launch);
        $this->assertSame('task-analyst', $this->agents->launch[0]);
        $this->assertTrue($this->task->taskAnalystRan);
        StateMachine::enterState($this->ctx, State::InProgress); // run-once
        $this->assertCount(1, $this->agents->launch);
    }

    public function testEnterInProgressStampsAnalystLaunch(): void
    {
        StateMachine::enterState($this->ctx, State::InProgress);
        $rec = $this->task->agentLaunches['task-analyst'];
        $this->assertNotNull($rec->launchedAt);
        $this->assertSame(1, $rec->attempts);
        StateMachine::enterState($this->ctx, State::InProgress);
        $this->assertSame(1, $this->task->agentLaunches['task-analyst']->attempts);
    }

    public function testEnterInProgressSkipsStartupScriptWhenUnset(): void
    {
        StateMachine::enterState($this->ctx, State::InProgress);
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
            startupScript: '/dev/null/setup.sh',
        );
        $this->ctx->cfg = $this->cfg;
        StateMachine::enterState($this->ctx, State::InProgress);
        $this->assertSame(['/dev/null/setup.sh'], $this->agents->startupScript);
        $this->assertTrue($this->task->startupScriptRan);
        $this->assertSame(1, $this->task->agentLaunches['startup-script']->attempts);
        StateMachine::enterState($this->ctx, State::InProgress);
        $this->assertCount(1, $this->agents->startupScript);
    }

    public function testWaitingSavesAndRestoresPreviousState(): void
    {
        $this->task->state = State::WaitingReview;
        StateMachine::toggleWaiting($this->ctx);
        $this->assertSame(State::Waiting, $this->task->state);
        $this->assertSame(State::WaitingReview, $this->task->stateBeforeWaiting);
        StateMachine::toggleWaiting($this->ctx);
        $this->assertSame(State::WaitingReview, $this->task->state);
        $this->assertNull($this->task->stateBeforeWaiting);
    }

    public function testWaitingForbiddenFromRequestChangesAndTestingFailed(): void
    {
        foreach ([State::RequestChanges, State::TestingFailed] as $forbidden) {
            $this->task->state = $forbidden;
            $this->expectToFailImpossible(
                fn () => StateMachine::toggleWaiting($this->ctx),
            );
            $this->task->state = $forbidden;
            $this->expectToFailImpossible(
                fn () => StateMachine::enterState($this->ctx, State::Waiting),
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
        StateMachine::enterState($this->ctx, State::RequestChanges, trigger: false);
        $this->assertSame([], $this->agents->launch);
        $this->assertSame([], $this->drafts);
        $this->task->state = State::Draft;
        StateMachine::enterState($this->ctx, State::Waiting, trigger: false);
        $this->assertSame(State::Draft, $this->task->stateBeforeWaiting);
    }

    public function testReadyToReviewChainsToWaitingReviewAndMarksReady(): void
    {
        $this->task->state = State::Draft;
        StateMachine::enterState($this->ctx, State::ReadyToReview);
        $this->assertSame([7], $this->readies);
        $this->assertSame(State::WaitingReview, $this->task->state);
    }

    public function testRequestChangesLaunchesPlannerThenDraftsPr(): void
    {
        $this->task->state = State::WaitingReview;
        StateMachine::enterState($this->ctx, State::RequestChanges);
        $this->assertSame('pr-feedback', $this->agents->launch[0]);
        $this->assertSame([7], $this->drafts);
        $this->assertSame(1, $this->task->agentLaunches['pr-feedback']->attempts);
    }

    public function testCiRedStampsLaunch(): void
    {
        $this->task->state = State::Draft;
        StateMachine::enterState($this->ctx, State::CiRed);
        $this->assertNotNull($this->task->agentLaunches['ci-analyst']->launchedAt);
        $this->assertSame(1, $this->task->agentLaunches['ci-analyst']->attempts);
    }

    public function testCiRedRunsAnalyst(): void
    {
        $this->task->state = State::Draft;
        StateMachine::enterState($this->ctx, State::CiRed);
        $this->assertSame('ci-analyst', $this->agents->launch[0]);
        $this->assertSame([], $this->drafts);
        StateMachine::enterState($this->ctx, State::ReadyToReview);
        StateMachine::enterState($this->ctx, State::CiRed);
        $this->assertCount(2, $this->agents->launch);
        $this->assertSame('ci-analyst', $this->agents->launch[1]);
    }

    public function testTestingFailedLaunchesFeedbackThenDraftsPr(): void
    {
        $this->task->state = State::NeedsTesting;
        StateMachine::enterState($this->ctx, State::TestingFailed);
        $this->assertSame('task-feedback', $this->agents->launch[0]);
        $this->assertSame([7], $this->drafts);
        $this->assertSame(1, $this->task->agentLaunches['task-feedback']->attempts);
    }

    public function testNeedsTestingStampsBaseline(): void
    {
        $this->task->state = State::WaitingReview;
        StateMachine::enterState($this->ctx, State::NeedsTesting);
        $this->assertNotNull($this->task->needsTestingEnteredAt);
    }

    public function testNeedsTestingRestoreFromWaitingKeepsBaseline(): void
    {
        $this->task->state = State::WaitingReview;
        StateMachine::enterState($this->ctx, State::NeedsTesting);
        $baseline = $this->task->needsTestingEnteredAt;
        StateMachine::toggleWaiting($this->ctx);
        StateMachine::toggleWaiting($this->ctx);
        $this->assertSame(State::NeedsTesting, $this->task->state);
        $this->assertSame($baseline, $this->task->needsTestingEnteredAt);
    }

    public function testEnterStatePersists(): void
    {
        StateMachine::enterState($this->ctx, State::Draft);
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
            [State::InProgress, State::CiRed, State::RequestChanges, State::TestingFailed],
            StateMachine::COMMIT_ALLOWED_FROM,
        );
    }

    public function testAnalystPromptForPromptTask(): void
    {
        $this->task->issue = null;
        $this->task->prompt = 'fix callback verification in the webhook handler';
        $this->task->taskAnalystRan = false;
        StateMachine::enterState($this->ctx, State::InProgress);
        $prompt = $this->agents->launchPrompts[0];
        $this->assertStringContainsString('fix callback verification in the webhook handler', $prompt);
    }

    public function testEnterDraftResetsCiIgnored(): void
    {
        $this->task->ciIgnored = true;
        $this->task->state = State::WaitingReview;
        StateMachine::enterState($this->ctx, State::Draft);
        $this->assertFalse($this->task->ciIgnored);
    }

    public function testNeedsTestingSeedsLastSeenStatus(): void
    {
        ProviderRegistry::setResolver(static fn (string $name): \Pablo\Provider\Tracker\Provider => new FakeStatusProvider('A FIX'));
        try {
            $this->task->state = State::WaitingReview;
            StateMachine::enterState($this->ctx, State::NeedsTesting);
            $this->assertSame('A FIX', $this->task->lastSeenIssueStatus);
        } finally {
            ProviderRegistry::setResolver(null);
        }
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

    public function batchIssueStatus(array $pairs): array
    {
        $result = [];
        foreach ($pairs as [$key]) {
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
