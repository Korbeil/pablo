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
use Pablo\Provider\Tracker\Gitlab;
use Pablo\Support\RepoSlug;
use Pablo\Tests\FakeGit;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class GitlabTest extends TestCase
{
    private FakeProcessRunner $runner;

    /** @var list<array<mixed>> */
    private array $argvs = [];

    /** @param callable(array<mixed>): mixed $answer */
    private function provider(callable $answer, string $origin = 'git@gitlab.acme.io:platform/wallet-kit.git'): Gitlab
    {
        $this->runner = new FakeProcessRunner();
        $this->runner->onRun = function (array $argv) use ($answer): string {
            $this->argvs[] = $argv;
            $out = $answer($argv);

            return \is_string($out) ? $out : (string) json_encode($out);
        };
        $git = new FakeGit();
        $git->originUrl = $origin;

        return new Gitlab($this->runner, new RepoSlug($git), new Time());
    }

    private function cfg(): ProjectConfig
    {
        return new ProjectConfig(
            name: 'wallet-kit',
            type: 'work',
            repo: new RepoConfig(path: '/tmp/wk', primaryBranch: 'main', worktreesRoot: '/tmp/wt'),
            tracker: new TrackerConfig(provider: 'gitlab', identity: 'me', projectKey: 'WK'),
            sync: new SyncConfig(strategy: 'rebase', autoApply: false, intervalMinutes: 30),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'm',
            prDescriptionLocale: 'en',
            testing: new TestingConfig(failureSignal: 'qa-failed'),
        );
    }

    public function testMatchUrlOnTheProjectsHostAndPath(): void
    {
        $provider = $this->provider(static fn (): array => []);

        $this->assertSame('45', $provider->matchUrl('https://gitlab.acme.io/platform/wallet-kit/-/issues/45', $this->cfg()));
        $this->assertSame('45', $provider->matchUrl('https://gitlab.acme.io/Platform/Wallet-Kit/-/work_items/45#note_1', $this->cfg()));
        $this->assertNull($provider->matchUrl('https://gitlab.com/platform/wallet-kit/-/issues/45', $this->cfg()), 'another host');
        $this->assertNull($provider->matchUrl('https://gitlab.acme.io/platform/other/-/issues/45', $this->cfg()), 'another project');
        $this->assertNull($provider->matchUrl('https://gitlab.acme.io/platform/wallet-kit/-/merge_requests/45', $this->cfg()));
    }

    public function testGetIssue(): void
    {
        $provider = $this->provider(static fn (): array => [
            'iid' => 45, 'title' => 'Fix callbacks', 'state' => 'opened', 'web_url' => 'https://gitlab.acme.io/platform/wallet-kit/-/issues/45',
        ]);

        $issue = $provider->getIssue('45', $this->cfg());

        $this->assertSame(['gitlab', '45', 'Fix callbacks', 'To Do', 'WK'], [$issue->provider, $issue->key, $issue->title, $issue->status, $issue->projectKey]);
        $this->assertSame(['glab', 'api', '--hostname', 'gitlab.acme.io', 'projects/platform%2Fwallet-kit/issues/45'], $this->argvs[0]);
    }

    public function testBatchIssueStatus(): void
    {
        $provider = $this->provider(static fn (array $argv): string => str_ends_with((string) end($argv), '/45') ? '{"state":"closed"}' : 'boom');

        $this->assertSame(['45' => 'Done', '46' => '?'], $provider->batchIssueStatus(['45' => $this->cfg(), '46' => $this->cfg()]));
    }

    public function testFailureSignalEventsReadTheMergeRequestsLabelEvents(): void
    {
        $provider = $this->provider(static fn (): array => [
            ['action' => 'add', 'label' => ['name' => 'qa-failed'], 'created_at' => '2026-03-05T10:00:00Z'],
            ['action' => 'remove', 'label' => ['name' => 'qa-failed'], 'created_at' => '2026-03-06T10:00:00Z'],
            ['action' => 'add', 'label' => ['name' => 'other'], 'created_at' => '2026-03-07T10:00:00Z'],
            ['action' => 'add', 'label' => ['name' => 'qa-failed'], 'created_at' => '2026-03-04T10:00:00Z'],
        ]);
        $task = new Task(project: 'wallet-kit', branch: 'wk-45', worktreePath: '/tmp/wt/wk-45', state: State::NeedsTesting, now: '2026-03-01T00:00:00Z');
        $task->prNumber = 12;

        $stamps = $provider->failureSignalEvents($task, $this->cfg());

        $this->assertSame(['2026-03-04T10:00:00+00:00', '2026-03-05T10:00:00+00:00'], array_map(static fn (\DateTimeImmutable $d): string => $d->format(\DATE_ATOM), $stamps));
        $this->assertStringStartsWith('projects/platform%2Fwallet-kit/merge_requests/12/resource_label_events?per_page=100&page=1', (string) end($this->argvs[0]));
    }
}
