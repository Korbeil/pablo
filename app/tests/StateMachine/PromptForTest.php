<?php

declare(strict_types=1);

namespace Pablo\Tests\StateMachine;

use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TrackerConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\StateMachine\StateMachine;
use Pablo\Support\RepoSlug;
use Pablo\Tests\FakeForge;
use Pablo\Tests\FakeGit;
use Pablo\Tests\Registries;
use Pablo\Tests\StubProviders;
use PHPUnit\Framework\TestCase;

/** A GitLab project's agents are told about a merge request, as GitLab names it. */
final class PromptForTest extends TestCase
{
    private function prompt(?string $origin, Agent $agent): string
    {
        $git = new FakeGit();
        $git->originUrl = $origin;
        $sm = new StateMachine(new FakeForge(), new StubProviders(), new RepoSlug($git), new Time(), Registries::hooks());
        $task = new Task('p', 'wk-45', '/tmp/wk-45', State::CiRed);
        $task->prNumber = 12;
        $cfg = new ProjectConfig(
            name: 'p',
            type: 'work',
            repo: new RepoConfig(path: '/tmp/p', primaryBranch: 'main', worktreesRoot: '/tmp/wt'),
            tracker: new TrackerConfig(provider: 'jira', identity: 'me', projectKey: 'P'),
            sync: new SyncConfig(strategy: 'rebase', autoApply: false, intervalMinutes: 30),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'm',
            prDescriptionLocale: 'en',
        );

        return $sm->promptFor($agent, $task, $cfg);
    }

    public function testGitlabSaysMergeRequest(): void
    {
        $this->assertSame('CI is failing on merge request !12. Analyze the failing checks and produce a fix plan.', $this->prompt('git@gitlab.com:acme/app.git', Agent::CiAnalyst));
        $this->assertStringContainsString('left on merge request !12.', $this->prompt('git@gitlab.com:acme/app.git', Agent::PrFeedback));
    }

    public function testGithubAndUnknownSayPr(): void
    {
        $this->assertStringContainsString('on PR #12.', $this->prompt('git@github.com:acme/app.git', Agent::CiAnalyst));
        $this->assertStringContainsString('on PR #12.', $this->prompt(null, Agent::CiAnalyst));
    }
}
