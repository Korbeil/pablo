<?php

declare(strict_types=1);

namespace Pablo\Tests\Hooks;

use Pablo\Config\HooksConfig;
use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TrackerConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Hooks\Hooks;
use Pablo\Support\RepoSlug;
use Pablo\Tests\FakeGit;
use Pablo\Tests\FakeHookRunner;
use PHPUnit\Framework\TestCase;

final class HooksTest extends TestCase
{
    private FakeHookRunner $runner;
    private Hooks $hooks;
    private Task $task;

    protected function setUp(): void
    {
        $this->runner = new FakeHookRunner();
        $git = new FakeGit();
        $git->originUrl = 'git@github.com:acme/wallet-kit.git';
        $this->hooks = new Hooks($this->runner, new RepoSlug($git));
        $this->task = new Task('wallet-kit', 'wk-45', '/tmp/wt/wk-45', State::RequestChanges);
        $this->task->prNumber = 7;
        $this->task->issue = new Issue('github', 'WK-45', 'u', 'T', 'WK');
    }

    /** @param list<State>|null $states */
    private function cfg(?string $command = 'notify', ?array $states = null, bool $agentFinished = true): ProjectConfig
    {
        return new ProjectConfig(
            name: 'wallet-kit',
            type: 'open-source',
            repo: new RepoConfig(
                path: '/dev/null/repo',
                primaryBranch: 'main',
                worktreesRoot: '/dev/null/wt',
            ),
            tracker: new TrackerConfig(
                provider: 'github',
                identity: 'octocat',
                projectKey: 'WK',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 30,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
            hooks: new HooksConfig(
                command: $command,
                states: $states,
                agentFinished: $agentFinished,
            ),
        );
    }

    public function testTransitionRunsTheCommandWithItsEnvironment(): void
    {
        $this->hooks->transition($this->cfg(), $this->task, State::WaitingReview, [State::RequestChanges]);

        $this->assertCount(1, $this->runner->runs);
        $run = $this->runner->runs[0];
        $this->assertSame('notify', $run['command']);
        $this->assertSame('/tmp/wt/wk-45', $run['cwd']);
        $this->assertSame([
            'PABLO_EVENT' => 'transition',
            'PABLO_PROJECT' => 'wallet-kit',
            'PABLO_TASK' => 'wk-45',
            'PABLO_STATE' => 'request-changes',
            'PABLO_FROM' => 'waiting-review',
            'PABLO_TO' => 'request-changes',
            'PABLO_AGENT' => '',
            'PABLO_PR_URL' => 'https://github.com/acme/wallet-kit/pull/7',
            'PABLO_ISSUE_KEY' => 'WK-45',
            'PABLO_WORKTREE' => '/tmp/wt/wk-45',
        ], $run['env']);
    }

    public function testNothingRunsWithoutACommand(): void
    {
        $this->hooks->transition($this->cfg(null), $this->task, State::WaitingReview, [State::RequestChanges]);
        $this->hooks->agentFinished($this->cfg(null), $this->task, Agent::PrFeedback);

        $this->assertSame([], $this->runner->runs);
    }

    public function testNothingRunsWhenTheStateDidNotChange(): void
    {
        $this->hooks->transition($this->cfg(), $this->task, State::RequestChanges, [State::RequestChanges]);

        $this->assertSame([], $this->runner->runs);
    }

    public function testStatesFilterMatchesAnyEnteredState(): void
    {
        $cfg = $this->cfg(states: [State::Approved]);
        $this->task->state = State::NeedsTesting;

        // approved is a pass-through on the way to needs-testing: it counts.
        $this->hooks->transition($cfg, $this->task, State::WaitingReview, [State::Approved, State::NeedsTesting]);
        $this->assertCount(1, $this->runner->runs);
        $this->assertSame('needs-testing', $this->runner->runs[0]['env']['PABLO_TO']);

        // A transition into a state outside the filter stays silent.
        $this->task->state = State::CiRed;
        $this->hooks->transition($cfg, $this->task, State::Draft, [State::CiRed]);
        $this->assertCount(1, $this->runner->runs);
    }

    public function testAgentFinished(): void
    {
        $this->hooks->agentFinished($this->cfg(states: [State::CiRed]), $this->task, Agent::PrFeedback);

        // The states filter only applies to transitions.
        $this->assertSame(['agent-finished'], $this->runner->events());
        $env = $this->runner->runs[0]['env'];
        $this->assertSame('pr-feedback', $env['PABLO_AGENT']);
        $this->assertSame('request-changes', $env['PABLO_STATE']);
        $this->assertSame('', $env['PABLO_FROM']);
        $this->assertSame('', $env['PABLO_TO']);
    }

    public function testAgentFinishedCanBeTurnedOffAndSkipsTheStartupScript(): void
    {
        $this->hooks->agentFinished($this->cfg(agentFinished: false), $this->task, Agent::PrFeedback);
        $this->hooks->agentFinished($this->cfg(), $this->task, Agent::StartupScript);

        $this->assertSame([], $this->runner->runs);
    }

    public function testMissingPrAndIssueGiveEmptyValues(): void
    {
        $this->task->prNumber = null;
        $this->task->issue = null;
        $this->hooks->transition($this->cfg(), $this->task, null, [State::RequestChanges]);

        $env = $this->runner->runs[0]['env'];
        $this->assertSame('', $env['PABLO_PR_URL']);
        $this->assertSame('', $env['PABLO_ISSUE_KEY']);
        $this->assertSame('', $env['PABLO_FROM']);
    }

    public function testAFailingRunnerNeverThrows(): void
    {
        $this->runner->throw = true;
        $this->hooks->transition($this->cfg(), $this->task, State::WaitingReview, [State::RequestChanges]);
        $this->hooks->agentFinished($this->cfg(), $this->task, Agent::PrFeedback);

        $this->assertSame([], $this->runner->runs);
    }
}
