<?php

declare(strict_types=1);

namespace Pablo\Tests\Task;

use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Time;
use Pablo\Provider\Tracker\Provider;
use Pablo\Provider\Tracker\ProviderRegistryInterface;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Pablo\Support\Naming;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;
use Pablo\Support\TaskSummarizer;
use Pablo\Task\TaskStarter;
use Pablo\Tests\FakeAgents;
use Pablo\Tests\FakeAnalytics;
use Pablo\Tests\FakeGhPr;
use Pablo\Tests\FakeGit;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;

final class StartIssueProvider implements Provider
{
    public function __construct(private readonly ?Issue $issue = null)
    {
    }

    public function name(): string
    {
        return 'github';
    }

    public function supportsSignalViaStatus(): bool
    {
        return false;
    }

    public function matchUrl(string $url, ProjectConfig $cfg): ?string
    {
        return null !== $this->issue && $url === $this->issue->url ? $this->issue->key : null;
    }

    public function getIssue(string $ref, ProjectConfig $cfg): Issue
    {
        return $this->issue ?? throw new \LogicException('no issue');
    }

    public function listAssigned(ProjectConfig $cfg): array
    {
        return [];
    }

    public function issueStatus(string $key, ProjectConfig $cfg): string
    {
        return 'todo';
    }

    public function batchIssueStatus(array $issues): array
    {
        return array_map(static fn (): string => 'todo', $issues);
    }

    public function failureSignalEvents(\Pablo\Domain\Task $task, ProjectConfig $cfg): array
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

final class TaskStarterTest extends TestCase
{
    use UsesGlobalConfig;

    private string $tmp;
    private string $projectsDir;
    private Store $store;
    private FakeAgents $agents;
    private FakeGit $git;
    private FakeAnalytics $analytics;
    private ProviderRegistryInterface $providers;

    private TaskStarter $starter;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-starter-'.uniqid();
        $this->projectsDir = $this->tmp.'/projects';
        @mkdir($this->projectsDir, 0o777, true);
        $this->store = new Store($this->tmp.'/state');
        $this->agents = new FakeAgents();
        $this->git = new FakeGit();
        $this->git->originUrl = 'git@github.com:acme/wallet-kit.git';
        $this->git->allBranchNames = [];
        $this->git->createWorktree = static function (string $repo, string $root, string $branch, string $base): string {
            $path = rtrim($root, '/').'/'.$branch;
            @mkdir(\dirname($path), 0o777, true);

            return $path;
        };
        $this->analytics = new FakeAnalytics();
        $this->providers = new class implements ProviderRegistryInterface {
            public function get(string $name): Provider
            {
                return new StartIssueProvider();
            }
        };
        $this->writeProject('wallet-kit', 'WK');
        $this->starter = $this->makeStarter();
        putenv('PABLO_PROJECTS_DIR='.$this->projectsDir);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
        $this->unsetGlobalConfig();
    }

    private function writeProject(string $name, string $projKey): void
    {
        file_put_contents($this->projectsDir.'/'.$name.'.yaml', \sprintf(
            <<<'YAML'
name: %s
type: work
repo:
  path: %s/repo
  primary_branch: main
worktrees_root: %s/wt
issue_tracker:
  provider: github
  identity: octocat
  project_key: %s
YAML,
            $name,
            $this->tmp,
            $this->tmp,
            $projKey,
        ));
    }

    private function makeStarter(): TaskStarter
    {
        $this->writeGlobalConfig(<<<'YAML'
sync:
  strategy: rebase
  auto_apply: false
  interval_minutes: 30
state_polling:
  interval_minutes: 10
review:
  bot_whitelist: []
ci:
  ignore_checks: []
default_model: openrouter/test/model
pr_description_locale: en
YAML);

        return new TaskStarter(
            $this->providers,
            new Naming(),
            $this->git,
            new StateMachine(new FakeGhPr(), $this->providers, new RepoSlug($this->git), new Time()),
            new TaskSummarizer(new FakeProcessRunner()),
            $this->analytics,
            $this->store,
            new Config(new GlobalConfig()),
            $this->agents,
        );
    }

    public function testPromptStartReturnsStartResultAndOwnsTheTask(): void
    {
        $result = $this->starter->start('fix callback verification quickly now', 'wallet-kit');

        $this->assertSame('wallet-kit', $result->project);
        $this->assertSame('wk-fix-callback-verification-quickly', $result->branch);
        $this->assertSame($this->tmp.'/wt/wk-fix-callback-verification-quickly', $result->worktreePath);
        $this->assertNull($result->issueKey);
        $this->assertFalse($result->reused);

        $task = $this->store->get('wallet-kit', $result->branch);
        $this->assertNotNull($task);
        $this->assertSame(State::InProgress, $task->state);
        $this->assertSame('fix callback verification quickly now', $task->prompt);
        $this->assertSame([$result->branch], $this->agents->displayNames);
        $this->assertCount(1, $this->analytics->opened);
    }

    public function testIssueStartCarriesIssueFields(): void
    {
        $issue = new Issue('github', '45', 'https://github.com/acme/wallet-kit/issues/45', 'Fix callback verification', 'WK');
        $this->providers = new class($issue) implements ProviderRegistryInterface {
            public function __construct(private readonly Issue $issue)
            {
            }

            public function get(string $name): Provider
            {
                return new StartIssueProvider($this->issue);
            }
        };
        $this->starter = $this->makeStarter();

        $result = $this->starter->start($issue->url, null);

        $this->assertSame('45', $result->issueKey);
        $this->assertSame($issue->title, $result->issueTitle);
        $this->assertSame('wk-45', $result->branch);
        $this->assertSame($this->tmp.'/wt/wk-45', $result->worktreePath);
        $this->assertFalse($result->reused);
    }

    public function testReusedIssueTaskMarksResultReused(): void
    {
        $issue = new Issue('github', '45', 'https://github.com/acme/wallet-kit/issues/45', 'Fix callback verification', 'WK');
        $this->providers = new class($issue) implements ProviderRegistryInterface {
            public function __construct(private readonly Issue $issue)
            {
            }

            public function get(string $name): Provider
            {
                return new StartIssueProvider($this->issue);
            }
        };
        $this->starter = $this->makeStarter();

        $this->starter->start($issue->url, null);
        $result = $this->starter->start($issue->url, null);

        $this->assertTrue($result->reused);
        $this->assertSame('45', $result->issueKey);
        $this->assertSame('wk-45', $result->branch);
        $this->assertSame(State::InProgress->value, $result->reusedState);
        $this->assertCount(1, $this->analytics->opened);
    }

    public function testPromptWithoutProjectThrows(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('--project');
        $this->starter->start('do something', null);
    }

    public function testUnknownProjectThrows(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('wallet-kit');
        $this->starter->start('do something', 'nope');
    }
}
