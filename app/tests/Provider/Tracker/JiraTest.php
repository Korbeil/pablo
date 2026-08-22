<?php

declare(strict_types=1);

namespace Pablo\Tests\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Tracker\Jira;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class JiraTest extends TestCase
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
        'key' => 'XXX-123',
        'fields' => ['summary' => 'Fix product import', 'status' => ['name' => 'In Progress']],
    ];

    private const ISSUE_JSON_DIFF_STATUS = [
        'key' => 'XXX-123',
        'fields' => ['summary' => 'Fix product import', 'status' => ['name' => 'A FIX']],
    ];

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-jira-'.uniqid();
        mkdir($this->tmp, 0o777, true);
    }

    /** @var array<string, mixed> view payload */
    private array $view = self::ISSUE_JSON;

    /** @var list<array<string, mixed>>|null */
    private ?array $search = null;

    /** @var list<array<int, string>> */
    private array $calls = [];

    /** @param array<string, mixed>|null $view
     * @param list<array<string, mixed>>|null $search
     */
    private function configure(?array $view = null, ?array $search = null): void
    {
        $this->view = $view ?? self::ISSUE_JSON;
        $this->search = $search;
        $this->startRunner(function (array $argv, bool $check = true, ?float $timeout = null): string {
            $this->calls[] = $argv;
            if (\in_array('search', $argv, true)) {
                return json_encode($this->search ?? [$this->view], \JSON_THROW_ON_ERROR);
            }
            if (\in_array('view', $argv, true)) {
                return json_encode($this->view, \JSON_THROW_ON_ERROR);
            }
            throw new \LogicException('unexpected acli argv');
        });
    }

    private function cfg(): ProjectConfig
    {
        return new ProjectConfig(
            name: 'acme-pim',
            type: 'work',
            repoPath: $this->tmp,
            primaryBranch: 'main',
            worktreesRoot: $this->tmp.'/wt',
            provider: 'jira',
            identity: 'acme@example.com',
            projectKey: 'XXX',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: 'A FIX',
            botWhitelist: [],
            ciIgnoreChecks: [],
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
            site: 'acme.atlassian.net',
        );
    }

    public function testMatchUrl(): void
    {
        $provider = $this->svc();
        $c = $this->cfg();
        $this->assertSame('XXX-123', $provider->matchUrl('https://acme.atlassian.net/browse/XXX-123', $c));
        $this->assertNull($provider->matchUrl('https://acme.atlassian.net/browse/YYY-9', $c));
        $this->assertNull($provider->matchUrl('https://github.com/a/b/issues/1', $c));
    }

    public function testGetIssueParsesFields(): void
    {
        $this->configure();
        $issue = $this->svc()->getIssue('XXX-123', $this->cfg());
        $this->assertSame('XXX-123', $issue->key);
        $this->assertSame('Fix product import', $issue->title);
        $this->assertSame('In Progress', $issue->status);
        $this->assertSame('XXX', $issue->projectKey);
        $this->assertStringContainsString('browse/XXX-123', $issue->url);
    }

    public function testListAssignedUsesJqlCurrentUser(): void
    {
        $this->configure(null, [self::ISSUE_JSON]);
        $issues = $this->svc()->listAssigned($this->cfg());
        $this->assertSame(['XXX-123'], array_map(static fn ($i) => $i->key, $issues));
        $argv = $this->calls[\count($this->calls) - 1];
        $this->assertContains('search', $argv);
        $jql = $argv[array_search('--jql', $argv, true) + 1];
        $this->assertStringContainsString('project = XXX', $jql);
        $this->assertStringContainsString('assignee = currentUser()', $jql);
        $this->assertSame('50', $argv[array_search('--limit', $argv, true) + 1]);
    }

    public function testIssueStatus(): void
    {
        $this->configure();
        $this->assertSame('In Progress', $this->svc()->issueStatus('XXX-123', $this->cfg()));
    }

    public function testFailureSignalEmptyWhenChangelogUnavailable(): void
    {
        $this->configure();
        $task = new Task('acme-pim', 'xxx-123', $this->tmp, State::NeedsTesting);
        $task->issue = $this->svc()->getIssue('XXX-123', $this->cfg());
        $this->assertSame([], $this->svc()->failureSignalEvents($task, $this->cfg()));
    }

    public function testFailureSignalEmptyEvenWhenStatusMatchesSignal(): void
    {
        $this->configure(self::ISSUE_JSON_DIFF_STATUS);
        $task = new Task('acme-pim', 'xxx-123', $this->tmp, State::NeedsTesting);
        $task->issue = $this->svc()->getIssue('XXX-123', $this->cfg());
        $this->assertSame([], $this->svc()->failureSignalEvents($task, $this->cfg()));
    }

    public function testSignalViaStatusFlag(): void
    {
        $this->assertTrue($this->svc()->supportsSignalViaStatus());
    }

    public function testCliNameIsAcli(): void
    {
        $this->assertSame('acli', $this->svc()->cliName());
    }

    public function testAuthCheckCmd(): void
    {
        $this->assertSame(['acli', 'jira', 'auth', 'status'], $this->svc()->authCheckCmd());
    }

    private function svc(): Jira
    {
        return new Jira($this->runner ?? new FakeProcessRunner());
    }
}
