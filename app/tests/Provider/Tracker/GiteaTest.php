<?php

declare(strict_types=1);

namespace Pablo\Tests\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TestingConfig;
use Pablo\Config\TrackerConfig;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Provider\Tracker\Gitea;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;
use Pablo\Tests\FakeGit;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class GiteaTest extends TestCase
{
    private FakeProcessRunner $runner;

    /** @var list<array<mixed>> */
    private array $argvs = [];

    /** @param callable(array<mixed>): mixed $answer */
    private function provider(callable $answer, string $origin = 'git@gitea.acme.io:platform/wallet-kit.git'): Gitea
    {
        $this->runner = new FakeProcessRunner();
        $this->runner->onRun = function (array $argv) use ($answer): string {
            $this->argvs[] = $argv;
            $out = $answer($argv);

            return \is_string($out) ? $out : (string) json_encode($out);
        };
        $git = new FakeGit();
        $git->originUrl = $origin;

        return new Gitea($this->runner, new RepoSlug($git), new Time());
    }

    private function cfg(): ProjectConfig
    {
        return new ProjectConfig(
            name: 'wallet-kit',
            type: 'work',
            repo: new RepoConfig(path: '/tmp/wk', primaryBranch: 'main', worktreesRoot: '/tmp/wt'),
            tracker: new TrackerConfig(provider: 'gitea', identity: 'me', projectKey: 'WK'),
            sync: new SyncConfig(strategy: 'rebase', autoApply: false, intervalMinutes: 30),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'm',
            prDescriptionLocale: 'en',
            testing: new TestingConfig(failureSignal: 'qa-failed'),
        );
    }

    public function testMatchUrlOnTheProjectsHostAndRepository(): void
    {
        $provider = $this->provider(static fn (): array => []);

        $this->assertSame('45', $provider->matchUrl('https://gitea.acme.io/platform/wallet-kit/issues/45', $this->cfg()));
        $this->assertSame('45', $provider->matchUrl('https://Gitea.Acme.io/Platform/Wallet-Kit/issues/45#issuecomment-3', $this->cfg()));
        $this->assertNull($provider->matchUrl('https://gitea.com/platform/wallet-kit/issues/45', $this->cfg()), 'another host');
        $this->assertNull($provider->matchUrl('https://gitea.acme.io/platform/other/issues/45', $this->cfg()), 'another repository');
        $this->assertNull($provider->matchUrl('https://gitea.acme.io/platform/wallet-kit/pulls/45', $this->cfg()));
    }

    public function testMatchUrlUnderAnInstanceSubPath(): void
    {
        $provider = $this->provider(static fn (): array => [], 'https://gitea.acme.io/git/platform/wallet-kit.git');

        $this->assertSame('7', $provider->matchUrl('https://gitea.acme.io/git/platform/wallet-kit/issues/7', $this->cfg()));
    }

    public function testGetIssue(): void
    {
        $provider = $this->provider(static fn (): array => [
            'number' => 45, 'title' => 'Fix callbacks', 'state' => 'open', 'html_url' => 'https://gitea.acme.io/platform/wallet-kit/issues/45',
        ]);

        $issue = $provider->getIssue('#45', $this->cfg());

        $this->assertSame(['gitea', '45', 'Fix callbacks', 'To Do', 'WK'], [$issue->provider, $issue->key, $issue->title, $issue->status, $issue->projectKey]);
        $this->assertSame('https://gitea.acme.io/platform/wallet-kit/issues/45', $issue->url);
        $this->assertSame(['gt', 'api', '--host', 'gitea.acme.io', '--no-input', 'repos/platform/wallet-kit/issues/45'], $this->argvs[0]);
    }

    public function testGetIssueFailsOnGiteasErrorBody(): void
    {
        $provider = $this->provider(static fn (): array => ['message' => 'issue does not exist', 'url' => 'https://gitea.acme.io/api/swagger']);

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('issue does not exist');
        $provider->getIssue('99', $this->cfg());
    }

    public function testBatchIssueStatus(): void
    {
        $provider = $this->provider(static fn (array $argv): string => match (true) {
            str_ends_with((string) end($argv), '/45') => '{"number":45,"state":"closed"}',
            str_ends_with((string) end($argv), '/47') => '{"message":"issue does not exist"}',
            default => 'boom',
        });

        $this->assertSame(
            ['45' => 'Done', '46' => '?', '47' => '?'],
            $provider->batchIssueStatus(['45' => $this->cfg(), '46' => $this->cfg(), '47' => $this->cfg()]),
        );
    }

    public function testFailureSignalEventsReadThePullRequestsLabelTimeline(): void
    {
        $provider = $this->provider(static fn (): array => [
            ['type' => 'label', 'body' => '1', 'label' => ['name' => 'qa-failed'], 'created_at' => '2026-03-05T10:00:00Z'],
            ['type' => 'label', 'body' => '', 'label' => ['name' => 'qa-failed'], 'created_at' => '2026-03-06T10:00:00Z'],
            ['type' => 'label', 'body' => '1', 'label' => ['name' => 'other'], 'created_at' => '2026-03-07T10:00:00Z'],
            ['type' => 'comment', 'body' => '1', 'label' => null, 'created_at' => '2026-03-07T11:00:00Z'],
            ['type' => 'label', 'body' => '1', 'label' => ['name' => 'qa-failed'], 'created_at' => '2026-03-04T10:00:00Z'],
        ]);
        $task = new Task(project: 'wallet-kit', branch: 'wk-45', worktreePath: '/tmp/wt/wk-45', state: State::NeedsTesting, now: '2026-03-01T00:00:00Z');
        $task->prNumber = 12;

        $stamps = $provider->failureSignalEvents($task, $this->cfg());

        $this->assertSame(['2026-03-04T10:00:00+00:00', '2026-03-05T10:00:00+00:00'], array_map(static fn (\DateTimeImmutable $d): string => $d->format(\DATE_ATOM), $stamps));
        $this->assertSame('repos/platform/wallet-kit/issues/12/timeline?limit=50&page=1', (string) end($this->argvs[0]));
    }

    public function testNoFailureSignalWithoutAPullRequest(): void
    {
        $provider = $this->provider(static fn (): never => throw new \LogicException('no call expected'));
        $task = new Task(project: 'wallet-kit', branch: 'wk-45', worktreePath: '/tmp/wt/wk-45', state: State::NeedsTesting, now: '2026-03-01T00:00:00Z');

        $this->assertSame([], $provider->failureSignalEvents($task, $this->cfg()));
    }
}
