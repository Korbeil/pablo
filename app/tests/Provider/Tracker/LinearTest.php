<?php

declare(strict_types=1);

namespace Pablo\Tests\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Tracker\Linear;
use Pablo\Support\Proc;
use PHPUnit\Framework\TestCase;

final class LinearTest extends TestCase
{
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

    protected function tearDown(): void
    {
        Proc::setRunner(null);
    }

    /** @var array<string, string> */
    private array $canned = [];

    /** @param array<string, string> $responses */
    private function configure(array $responses): void
    {
        $this->canned = $responses;
        Proc::setRunner(function (array $argv, bool $check = true, ?float $timeout = null): string {
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
            repoPath: $this->tmp,
            primaryBranch: 'main',
            worktreesRoot: $this->tmp.'/wt',
            provider: 'linear',
            identity: 'korbeil',
            projectKey: 'STA',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: 'Testing Failed',
            botWhitelist: [],
            ciIgnoreChecks: [],
        );
    }

    public function testMatchUrl(): void
    {
        $provider = new Linear();
        $c = $this->cfg();
        $this->assertSame('STA-335', $provider->matchUrl('https://linear.app/stably/issue/STA-335/test-issue', $c));
        $this->assertNull($provider->matchUrl('https://linear.app/stably/issue/OTH-1/x', $c));
        $this->assertNull($provider->matchUrl('https://example.com', $c));
    }

    public function testGetIssueParses(): void
    {
        $this->configure(['issue view STA-335' => json_encode(self::ISSUE_JSON, \JSON_THROW_ON_ERROR)]);
        $issue = (new Linear())->getIssue('STA-335', $this->cfg());
        $this->assertSame('STA-335', $issue->key);
        $this->assertSame('Test issue', $issue->title);
        $this->assertSame('STA', $issue->projectKey);
    }

    public function testIssueStatus(): void
    {
        $this->configure(['issue view STA-335' => json_encode(self::ISSUE_JSON, \JSON_THROW_ON_ERROR)]);
        $this->assertSame('In Progress', (new Linear())->issueStatus('STA-335', $this->cfg()));
    }

    public function testListAssignedParses(): void
    {
        $this->configure(['issue list' => json_encode([
            ['identifier' => 'STA-1', 'title' => 'A', 'url' => 'u1', 'state' => ['name' => 'Todo']],
            ['identifier' => 'STA-2', 'title' => 'B', 'url' => 'u2', 'state' => ['name' => 'Done']],
        ], \JSON_THROW_ON_ERROR)]);
        $issues = (new Linear())->listAssigned($this->cfg());
        $this->assertSame(['STA-1', 'STA-2'], array_map(static fn ($i) => $i->key, $issues));
    }

    public function testFailureSignalEvents(): void
    {
        $this->configure(['issue view STA-335' => json_encode(self::ISSUE_JSON, \JSON_THROW_ON_ERROR)]);
        $task = new Task('stably', 'sta-335', $this->tmp, State::NeedsTesting);
        $task->issue = (new Linear())->getIssue('STA-335', $this->cfg());
        $stamps = (new Linear())->failureSignalEvents($task, $this->cfg());
        $this->assertCount(2, $stamps);
        $this->assertEquals(new \DateTimeImmutable('2026-07-20T10:00:00.000Z'), $stamps[\count($stamps) - 1]);
    }
}
