<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents;

use Pablo\Agents\LaunchContext;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;
use Pablo\Tests\FakeGit;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class LaunchContextTest extends TestCase
{
    private FakeProcessRunner $runner;
    private LaunchContext $context;

    protected function setUp(): void
    {
        $this->runner = new FakeProcessRunner();
        $this->context = new LaunchContext($this->runner, new RepoSlug(new FakeGit()));
    }

    /** @param list<string> $ciIgnoreChecks */
    private function cfg(array $ciIgnoreChecks = []): ProjectConfig
    {
        return new ProjectConfig(
            name: 'wallet-kit',
            type: 'work',
            repoPath: '/nonexistent',
            primaryBranch: 'main',
            worktreesRoot: '/nonexistent/wt',
            provider: 'github',
            identity: 'octocat',
            projectKey: 'WK',
            issueRepo: 'acme/wallet-kit',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: null,
            botWhitelist: [],
            ciIgnoreChecks: $ciIgnoreChecks,
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
    }

    private function task(?Issue $issue = null, ?int $prNumber = 12): Task
    {
        $task = new Task('wallet-kit', 'wk-45', '/nonexistent/wt/wk-45', State::CiRed);
        $task->issue = $issue;
        $task->prNumber = $prNumber;

        return $task;
    }

    public function testCiBlockListsFailingChecksAndStripsLogPrefixes(): void
    {
        $this->runner->onRun = static function (array $argv): string {
            if (\in_array('checks', $argv, true)) {
                return (string) json_encode([
                    ['name' => 'phpunit', 'state' => 'FAILURE', 'bucket' => 'fail', 'workflow' => 'CI', 'link' => 'https://github.com/acme/wallet-kit/actions/runs/111/job/222'],
                    ['name' => 'lint', 'state' => 'SUCCESS', 'bucket' => 'pass', 'workflow' => 'CI', 'link' => 'https://x/actions/runs/1/job/2'],
                    ['name' => 'flaky', 'state' => 'FAILURE', 'bucket' => 'fail', 'workflow' => 'CI', 'link' => 'https://x/actions/runs/3/job/4'],
                ]);
            }
            if (\in_array('--log-failed', $argv, true)) {
                return "phpunit\tRun tests\t2026-10-14T10:00:00.1234567Z \e[31mFAILED\e[0m FooTest::testBar\n"
                    ."phpunit\tRun tests\t2026-10-14T10:00:01.1234567Z Tests: 3, Failures: 1\n"
                    ."phpunit\tPost Run\t2026-10-14T10:00:02.1234567Z Post job cleanup.\n"
                    ."phpunit\tPost Run\t2026-10-14T10:00:03.1234567Z Removing credentials config\n";
            }

            throw new \LogicException('unexpected '.implode(' ', $argv));
        };

        $prompt = $this->context->augment(Agent::CiAnalyst, $this->task(), $this->cfg(['flaky']), 'CI is failing on PR #12.');

        $this->assertStringStartsWith('CI is failing on PR #12.', $prompt);
        $this->assertStringContainsString('## Pre-fetched context (PABLO)', $prompt);
        $this->assertStringContainsString('- CI / phpunit — FAILURE — https://github.com/acme/wallet-kit/actions/runs/111/job/222', $prompt);
        $this->assertStringNotContainsString('lint', $prompt);
        $this->assertStringNotContainsString('flaky', $prompt, 'ignored checks are not pre-fetched');
        $this->assertStringContainsString("[error lines]\nFAILED FooTest::testBar\n", $prompt);
        $this->assertStringContainsString("Tests: 3, Failures: 1\n```", $prompt);
        $this->assertStringNotContainsString('Removing credentials', $prompt, 'post-job cleanup is cut');
    }

    public function testCiBlockSkipsNonActionsChecksLogs(): void
    {
        $this->runner->onRun = static function (array $argv): string {
            if (\in_array('checks', $argv, true)) {
                return (string) json_encode([
                    ['name' => 'ci/circleci: tests', 'state' => 'FAILURE', 'bucket' => 'fail', 'workflow' => '', 'link' => 'https://app.circleci.com/pipelines/github/acme/wk/1/workflows/u/jobs/9'],
                ]);
            }

            throw new \LogicException('logs must not be fetched for CircleCI');
        };

        $prompt = $this->context->augment(Agent::CiAnalyst, $this->task(), $this->cfg(), 'p');

        $this->assertStringContainsString('- ci/circleci: tests — FAILURE', $prompt);
        $this->assertStringNotContainsString('Log tail', $prompt);
    }

    public function testJiraIssueRendersAdfDescriptionAndComments(): void
    {
        $this->runner->onRun = static fn (array $argv): string => (string) json_encode(['fields' => [
            'summary' => 'Migrate notifications',
            'status' => ['name' => 'EN COURS'],
            'issuetype' => ['name' => 'Task'],
            'priority' => ['name' => 'Medium'],
            'labels' => ['backend'],
            'description' => ['type' => 'doc', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello '], ['type' => 'mention', 'attrs' => ['text' => '@Ana']]]],
                ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'step one']]]]]]],
            ]],
            'comment' => ['comments' => [
                ['author' => ['displayName' => 'QA Bot'], 'created' => '2026-10-01', 'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Broken on staging']]]]]],
            ]],
        ]]);

        $prompt = $this->context->augment(Agent::TaskAnalyst, $this->task(new Issue('jira', 'OMS-1', 'u', 'T')), $this->cfg(), 'Analyze issue OMS-1');

        $this->assertStringContainsString('### Jira OMS-1: Migrate notifications', $prompt);
        $this->assertStringContainsString('- Status: EN COURS', $prompt);
        $this->assertStringContainsString("Hello @Ana\n- step one", $prompt);
        $this->assertStringContainsString("**QA Bot** (2026-10-01):\nBroken on staging", $prompt);
        $this->assertSame(['acli', 'jira', 'workitem', 'view', 'OMS-1'], \array_slice($this->runner->calls[0][0], 0, 5));
    }

    public function testReviewBlockKeepsOnlyOpenFeedback(): void
    {
        $this->runner->onRun = static function (array $argv): string {
            if (\in_array('graphql', $argv, true)) {
                return (string) json_encode(['data' => ['repository' => ['pullRequest' => [
                    'reviews' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [
                        ['author' => ['login' => 'rev'], 'state' => 'CHANGES_REQUESTED', 'body' => 'Please rename Foo', 'url' => 'https://r/1'],
                        ['author' => ['login' => 'rev'], 'state' => 'APPROVED', 'body' => '', 'url' => 'https://r/2'],
                    ]],
                    'reviewThreads' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [
                        ['isResolved' => false, 'isOutdated' => true, 'path' => 'src/A.php', 'line' => 10, 'comments' => ['nodes' => [['author' => ['login' => 'rev'], 'body' => 'null check?', 'url' => 'https://t/1']]]],
                        ['isResolved' => true, 'isOutdated' => false, 'path' => 'src/B.php', 'line' => 3, 'comments' => ['nodes' => [['author' => ['login' => 'rev'], 'body' => 'done thing', 'url' => 'https://t/2']]]],
                    ]],
                ]]]]);
            }

            return (string) json_encode(['comments' => [['author' => ['login' => 'pm'], 'body' => 'Also update docs', 'url' => 'https://c/1']]]);
        };

        $prompt = $this->context->augment(Agent::PrFeedback, $this->task(), $this->cfg(), 'Review feedback on PR #12.');

        $this->assertStringContainsString('1 unresolved threads, 1 non-empty review bodies, 1 conversation comments.', $prompt);
        $this->assertStringContainsString('`src/A.php:10` (outdated)', $prompt);
        $this->assertStringNotContainsString('src/B.php', $prompt);
        $this->assertStringContainsString('Please rename Foo', $prompt);
        $this->assertStringContainsString('Also update docs', $prompt);
    }

    public function testFetchFailureLeavesPromptUnchanged(): void
    {
        $this->runner->onRun = static fn (): string => throw new PabloError('gh failed: boom');

        $this->assertSame('p', $this->context->augment(Agent::PrFeedback, $this->task(), $this->cfg(), 'p'));
    }

    public function testNothingToPrefetchLeavesPromptUnchanged(): void
    {
        $this->assertSame('p', $this->context->augment(Agent::TaskAnalyst, $this->task(null), $this->cfg(), 'p'));
        $this->assertSame('p', $this->context->augment(Agent::CiAnalyst, $this->task(null, null), $this->cfg(), 'p'));
        $this->assertSame('p', $this->context->augment(Agent::RebaseConflictResolver, $this->task(), $this->cfg(), 'p'));
        $this->assertSame([], $this->runner->calls);
    }

    public function testSectionIsCapped(): void
    {
        $this->runner->onRun = static fn (): string => (string) json_encode([
            'title' => 'Big', 'state' => 'OPEN', 'labels' => [], 'body' => str_repeat('x', 50_000),
            'comments' => array_fill(0, 40, ['author' => ['login' => 'a'], 'createdAt' => 'd', 'body' => str_repeat('y', 5_000)]),
        ]);

        $prompt = $this->context->augment(Agent::TaskFeedback, $this->task(new Issue('github', '45', 'u', 'T')), $this->cfg(), 'p');

        $this->assertLessThanOrEqual(LaunchContext::MAX_CHARS + 20, mb_strlen($prompt));
        $this->assertStringContainsString('[truncated]', $prompt);
        $this->assertStringContainsString('Comments (40, latest 15 shown', $prompt);
    }
}
