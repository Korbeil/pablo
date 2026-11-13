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
use Pablo\Provider\Tracker\Linear;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class LinearTest extends TestCase
{
    private FakeProcessRunner $runner;

    /** Registers a canned runner and returns it. */
    private function startRunner(callable $fn): FakeProcessRunner
    {
        $r = new FakeProcessRunner();
        $r->onRun = $fn;
        $this->runner = $r;

        return $r;
    }
    private const ISSUE_JSON = [
        'identifier' => 'STA-335',
        'title' => 'Test issue',
        'url' => 'https://linear.app/stably/issue/STA-335/test-issue',
        'state' => ['name' => 'In Progress'],
        'history' => [
            ['createdAt' => '2026-07-20T10:00:00.000Z', 'toState' => ['name' => 'Testing Failed']],
            ['createdAt' => '2026-07-21T10:00:00.000Z', 'toState' => ['name' => 'Done']],
            ['createdAt' => '2026-07-19T10:00:00.000Z', 'toState' => ['name' => 'Testing Failed']],
        ],
    ];

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-lin-'.uniqid();
        mkdir($this->tmp, 0o777, true);
    }

    /** @var array<string, string> */
    private array $canned = [];

    /** @param array<string, string> $responses */
    private function configure(array $responses): void
    {
        $this->canned = $responses;
        $this->startRunner(function (array $argv, bool $check = true, ?float $timeout = null): string {
            $joined = implode(' ', $argv);
            foreach ($this->canned as $key => $out) {
                if (str_contains($joined, $key)) {
                    return $out;
                }
            }
            throw new \LogicException('unexpected CLI call: '.$joined);
        });
    }

    private function cfg(): ProjectConfig
    {
        return new ProjectConfig(
            name: 'stably',
            type: 'personal',
            repo: new RepoConfig(
                path: $this->tmp,
                primaryBranch: 'main',
                worktreesRoot: $this->tmp.'/wt',
            ),
            tracker: new TrackerConfig(
                provider: 'linear',
                identity: 'octocat',
                projectKey: 'STA',
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
                failureSignal: 'Testing Failed',
            ),
        );
    }

    public function testMatchUrl(): void
    {
        $provider = $this->svc();
        $c = $this->cfg();
        $this->assertSame('STA-335', $provider->matchUrl('https://linear.app/stably/issue/STA-335/test-issue', $c));
        $this->assertNull($provider->matchUrl('https://linear.app/stably/issue/OTH-1/x', $c));
        $this->assertNull($provider->matchUrl('https://example.com', $c));
    }

    public function testGetIssueParses(): void
    {
        $this->configure(['issue view STA-335' => json_encode(self::ISSUE_JSON, \JSON_THROW_ON_ERROR)]);
        $issue = $this->svc()->getIssue('STA-335', $this->cfg());
        $this->assertSame('STA-335', $issue->key);
        $this->assertSame('Test issue', $issue->title);
        $this->assertSame('STA', $issue->projectKey);
    }

    public function testIssueStatus(): void
    {
        $this->configure(['issue view STA-335' => json_encode(self::ISSUE_JSON, \JSON_THROW_ON_ERROR)]);
        $this->assertSame('In Progress', $this->svc()->issueStatus('STA-335', $this->cfg()));
    }

    public function testFailureSignalEvents(): void
    {
        $this->configure(['issue view STA-335' => json_encode(self::ISSUE_JSON, \JSON_THROW_ON_ERROR)]);
        $task = new Task('stably', 'sta-335', $this->tmp, State::NeedsTesting);
        $task->issue = $this->svc()->getIssue('STA-335', $this->cfg());
        $stamps = $this->svc()->failureSignalEvents($task, $this->cfg());
        $this->assertCount(2, $stamps);
        $this->assertEquals(new \DateTimeImmutable('2026-07-20T10:00:00.000Z'), $stamps[\count($stamps) - 1]);
    }

    public function testEveryCliCallHasATimeout(): void
    {
        $this->configure([
            'issue view' => json_encode(self::ISSUE_JSON, \JSON_THROW_ON_ERROR),
        ]);
        $svc = $this->svc();
        $cfg = $this->cfg();
        $svc->issueStatus('STA-335', $cfg);
        $task = new Task('stably', 'sta-335', $this->tmp, State::NeedsTesting);
        $task->issue = $svc->getIssue('STA-335', $cfg);
        $svc->failureSignalEvents($task, $cfg);
        $this->assertSame(
            ['STA-335' => 'In Progress', 'STA-336' => 'In Progress'],
            $svc->batchIssueStatus(['STA-335' => $cfg, 'STA-336' => $cfg]),
        );

        $this->assertCount(5, $this->runner->calls);
        foreach ($this->runner->calls as [$argv, , $timeout]) {
            $this->assertSame('linear', $argv[0]);
            $this->assertSame(30.0, $timeout, implode(' ', $argv));
        }
    }

    private function svc(): Linear
    {
        return new Linear($this->runner ?? new FakeProcessRunner(), new \Pablo\Domain\Time());
    }
}
