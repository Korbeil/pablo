<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents;

use Pablo\Agents\LaunchContext;
use Pablo\Config\CiConfig;
use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TrackerConfig;
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

    /** A GitLab project: its context comes from glab, on its own host. */
    private function gitlab(): ProjectConfig
    {
        $git = new FakeGit();
        $git->originUrl = 'git@gitlab.acme.io:platform/wallet-kit.git';
        $this->context = new LaunchContext($this->runner, new RepoSlug($git));

        return new ProjectConfig(
            name: 'wallet-kit',
            type: 'work',
            repo: new RepoConfig(path: '/nonexistent', primaryBranch: 'main', worktreesRoot: '/nonexistent/wt'),
            tracker: new TrackerConfig(provider: 'gitlab', identity: 'me', projectKey: 'WK'),
            sync: new SyncConfig(strategy: 'rebase', autoApply: false, intervalMinutes: 30),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'm',
            prDescriptionLocale: 'en',
            ci: new CiConfig(ignoreChecks: ['danger']),
        );
    }

    public function testGitlabIssueBlockSkipsSystemNotes(): void
    {
        $cfg = $this->gitlab();
        $this->runner->onRun = static function (array $argv): string {
            $endpoint = (string) end($argv);
            if (str_contains($endpoint, '/notes')) {
                return (string) json_encode([
                    ['system' => true, 'body' => 'added ~bug label', 'author' => ['username' => 'bot'], 'created_at' => '2026-03-01'],
                    ['system' => false, 'body' => 'Repro on staging', 'author' => ['username' => 'qa'], 'created_at' => '2026-03-02'],
                ]);
            }

            return (string) json_encode(['title' => 'Fix callbacks', 'state' => 'opened', 'labels' => ['bug'], 'description' => 'Callbacks fire twice.']);
        };

        $prompt = $this->context->augment(Agent::TaskAnalyst, $this->task(new Issue('gitlab', '45', 'u', 'Fix callbacks', 'WK')), $cfg, 'Analyze.');

        $this->assertStringContainsString('### GitLab issue #45: Fix callbacks', $prompt);
        $this->assertStringContainsString('Labels: bug', $prompt);
        $this->assertStringContainsString('Repro on staging', $prompt);
        $this->assertStringNotContainsString('added ~bug label', $prompt);
        $this->assertSame(['glab', 'api', '--hostname', 'gitlab.acme.io', 'projects/platform%2Fwallet-kit/issues/45'], $this->runner->calls[0][0]);
    }

    public function testGitlabCiBlockListsBlockingFailedJobsWithTheirTrace(): void
    {
        $cfg = $this->gitlab();
        $this->runner->onRun = static function (array $argv): string {
            if (\in_array('graphql', $argv, true)) {
                return (string) json_encode(['data' => ['project' => ['mergeRequest' => ['headPipeline' => [
                    'webPath' => '/platform/wallet-kit/-/pipelines/77',
                    'jobs' => ['nodes' => [
                        ['id' => 'gid://gitlab/Ci::Build/501', 'name' => 'phpunit', 'allowFailure' => false, 'webPath' => '/platform/wallet-kit/-/jobs/501', 'stage' => ['name' => 'test']],
                        ['id' => 'gid://gitlab/Ci::Build/502', 'name' => 'audit', 'allowFailure' => true, 'webPath' => '/platform/wallet-kit/-/jobs/502', 'stage' => ['name' => 'test']],
                        ['id' => 'gid://gitlab/Ci::Build/503', 'name' => 'danger', 'allowFailure' => false, 'webPath' => '/platform/wallet-kit/-/jobs/503', 'stage' => ['name' => 'review']],
                    ]],
                ]]]]]);
            }

            return "section_start:1700000000:step_script\r\e[0KRunning tests\n\e[31;1mFAILED\e[0m tests/FooTest.php\nsection_end:1700000001:step_script\r\e[0K\nCleaning up project directory and file based variables\nnoise";
        };

        $prompt = $this->context->augment(Agent::CiAnalyst, $this->task(), $cfg, 'Fix CI.');

        $this->assertStringContainsString('### Failing jobs on MR !12 (pipeline https://gitlab.acme.io/platform/wallet-kit/-/pipelines/77)', $prompt);
        $this->assertStringContainsString('- test / phpunit — failed — https://gitlab.acme.io/platform/wallet-kit/-/jobs/501', $prompt);
        $this->assertStringNotContainsString('audit', $prompt, 'an allowed failure is no blocker');
        $this->assertStringNotContainsString('danger', $prompt, 'ci.ignore_checks applies');
        $this->assertStringContainsString('FAILED tests/FooTest.php', $prompt);
        $this->assertStringNotContainsString('section_start', $prompt);
        $this->assertStringNotContainsString('noise', $prompt);
        $this->assertSame('projects/platform%2Fwallet-kit/jobs/501/trace', end($this->runner->calls[1][0]));
    }

    public function testGitlabReviewBlockKeepsUnresolvedThreadsAndComments(): void
    {
        $cfg = $this->gitlab();
        $this->runner->onRun = static fn (): string => (string) json_encode([
            ['notes' => [['id' => 1, 'system' => true, 'body' => 'approved this merge request', 'author' => ['username' => 'alice']]]],
            ['notes' => [['id' => 2, 'system' => false, 'resolvable' => true, 'resolved' => false, 'body' => 'Rename this', 'author' => ['username' => 'alice'], 'position' => ['new_path' => 'src/A.php', 'new_line' => 7]]]],
            ['notes' => [['id' => 3, 'system' => false, 'resolvable' => true, 'resolved' => true, 'body' => 'Done already', 'author' => ['username' => 'bob']]]],
            ['notes' => [['id' => 4, 'system' => false, 'resolvable' => false, 'body' => 'Looks good overall', 'author' => ['username' => 'bob']]]],
        ]);

        $prompt = $this->context->augment(Agent::PrFeedback, $this->task(), $cfg, 'Plan.');

        $this->assertStringContainsString('### Open review feedback on MR !12', $prompt);
        $this->assertStringContainsString('1 unresolved threads, 1 comments', $prompt);
        $this->assertStringContainsString('`src/A.php:7`', $prompt);
        $this->assertStringContainsString('**alice** https://gitlab.acme.io/platform/wallet-kit/-/merge_requests/12#note_2', $prompt);
        $this->assertStringContainsString('Looks good overall', $prompt);
        $this->assertStringNotContainsString('Done already', $prompt);
        $this->assertStringNotContainsString('approved this merge request', $prompt);
    }

    /** A Gitea project: its context comes from gt api, on its own host. */
    private function gitea(): ProjectConfig
    {
        $git = new FakeGit();
        $git->originUrl = 'git@gitea.acme.io:platform/wallet-kit.git';
        $this->context = new LaunchContext($this->runner, new RepoSlug($git));

        return new ProjectConfig(
            name: 'wallet-kit',
            type: 'work',
            repo: new RepoConfig(path: '/nonexistent', primaryBranch: 'main', worktreesRoot: '/nonexistent/wt'),
            tracker: new TrackerConfig(provider: 'jira', identity: 'me', projectKey: 'WK'),
            sync: new SyncConfig(strategy: 'rebase', autoApply: false, intervalMinutes: 30),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'm',
            prDescriptionLocale: 'en',
            ci: new CiConfig(ignoreChecks: ['danger']),
        );
    }

    /** @param callable(string): mixed $answer endpoint => answer, JSON-encoded unless a string */
    private function gtAnswers(callable $answer): void
    {
        $this->runner->onRun = static function (array $argv) use ($answer): string {
            $out = $answer((string) end($argv));

            return \is_string($out) ? $out : (string) json_encode($out);
        };
    }

    public function testGiteaCiBlockListsFailingStatusesAndActionsLogs(): void
    {
        $cfg = $this->gitea();
        $this->gtAnswers(static fn (string $endpoint): mixed => match (true) {
            str_ends_with($endpoint, 'pulls/12') => ['number' => 12, 'head' => ['sha' => 'abc']],
            str_contains($endpoint, 'commits/abc/status') => ['statuses' => [
                ['context' => 'CI / phpunit (pull_request)', 'status' => 'failure', 'target_url' => 'https://gitea.acme.io/platform/wallet-kit/actions/runs/3/jobs/0'],
                ['context' => 'danger', 'status' => 'failure', 'target_url' => 'https://ci.example/1'],
                ['context' => 'CI / lint (pull_request)', 'status' => 'success', 'target_url' => ''],
            ]],
            str_contains($endpoint, 'actions/runs?') => ['workflow_runs' => [['id' => 31, 'conclusion' => 'failure'], ['id' => 30, 'conclusion' => 'success']]],
            str_contains($endpoint, 'actions/runs/31/jobs') => ['jobs' => [['id' => 501, 'name' => 'phpunit', 'conclusion' => 'failure'], ['id' => 502, 'name' => 'lint', 'conclusion' => 'success']]],
            str_contains($endpoint, 'actions/jobs/501/logs') => "Running tests\nFAILED tests/FooTest.php\n",
            default => throw new \LogicException($endpoint),
        });

        $prompt = $this->context->augment(Agent::CiAnalyst, $this->task(), $cfg, 'Fix CI.');

        $this->assertStringContainsString('### Failing checks on PR #12', $prompt);
        $this->assertStringContainsString('- CI / phpunit (pull_request) — failure — https://gitea.acme.io/platform/wallet-kit/actions/runs/3/jobs/0', $prompt);
        $this->assertStringNotContainsString('danger', $prompt, 'ci.ignore_checks applies');
        $this->assertStringContainsString('#### Log tail — phpunit (run 31, job 501)', $prompt);
        $this->assertStringContainsString('FAILED tests/FooTest.php', $prompt);
        $this->assertSame(['gt', 'api', '--host', 'gitea.acme.io', '--no-input', 'repos/platform/wallet-kit/pulls/12'], $this->runner->calls[0][0]);
    }

    public function testGiteaReviewBlockKeepsUnresolvedCommentsBodiesAndConversation(): void
    {
        $cfg = $this->gitea();
        $this->gtAnswers(static fn (string $endpoint): mixed => match (true) {
            str_contains($endpoint, 'reviews/7/comments') => [
                ['body' => 'Rename this', 'path' => 'src/A.php', 'position' => 7, 'resolver' => null, 'user' => ['login' => 'alice'], 'html_url' => 'https://gitea.acme.io/c/1'],
                ['body' => 'Done already', 'path' => 'src/B.php', 'position' => 2, 'resolver' => ['login' => 'me'], 'user' => ['login' => 'alice'], 'html_url' => 'https://gitea.acme.io/c/2'],
            ],
            str_contains($endpoint, '/reviews') => [
                ['id' => 7, 'state' => 'COMMENT', 'body' => '', 'comments_count' => 2, 'user' => ['login' => 'alice']],
                ['id' => 8, 'state' => 'REQUEST_CHANGES', 'body' => 'Needs tests', 'comments_count' => 0, 'user' => ['login' => 'bob'], 'html_url' => 'https://gitea.acme.io/r/8'],
                ['id' => 9, 'state' => 'PENDING', 'body' => 'unsent draft', 'comments_count' => 0, 'user' => ['login' => 'bob']],
            ],
            str_contains($endpoint, 'issues/12/comments') => [['body' => 'Any news?', 'user' => ['login' => 'carol'], 'html_url' => 'https://gitea.acme.io/i/1']],
            default => throw new \LogicException($endpoint),
        });

        $prompt = $this->context->augment(Agent::PrFeedback, $this->task(), $cfg, 'Plan.');

        $this->assertStringContainsString('### Open review feedback on PR #12', $prompt);
        $this->assertStringContainsString('1 unresolved threads, 1 non-empty review bodies, 1 conversation comments', $prompt);
        $this->assertStringContainsString('`src/A.php:7`', $prompt);
        $this->assertStringContainsString('Rename this', $prompt);
        $this->assertStringContainsString('**bob** (REQUEST_CHANGES) https://gitea.acme.io/r/8', $prompt);
        $this->assertStringContainsString('Any news?', $prompt);
        $this->assertStringNotContainsString('Done already', $prompt);
        $this->assertStringNotContainsString('unsent draft', $prompt);
    }

    /** @param list<string> $ciIgnoreChecks */
    private function cfg(array $ciIgnoreChecks = []): ProjectConfig
    {
        return new ProjectConfig(
            name: 'wallet-kit',
            type: 'work',
            repo: new RepoConfig(
                path: '/nonexistent',
                primaryBranch: 'main',
                worktreesRoot: '/nonexistent/wt',
            ),
            tracker: new TrackerConfig(
                provider: 'github',
                identity: 'octocat',
                projectKey: 'WK',
                repo: 'acme/wallet-kit',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 30,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
            ci: new CiConfig(
                ignoreChecks: $ciIgnoreChecks,
            ),
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
