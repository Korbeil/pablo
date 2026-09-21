<?php

declare(strict_types=1);

namespace Pablo\Tests\Twig;

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
use Pablo\Support\RepoSlug;
use Pablo\Support\TaskSummarizer;
use Pablo\Task\TaskStarter;
use Pablo\Tests\FakeAgents;
use Pablo\Tests\FakeAnalytics;
use Pablo\Tests\FakeGhPr;
use Pablo\Tests\FakeGit;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\UsesGlobalConfig;
use Pablo\Twig\Components\NewTaskModal;
use PHPUnit\Framework\TestCase;

final class StartIssueProvider implements Provider
{
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
        return 'https://github.com/acme/wallet-kit/issues/9' === $url ? '9' : null;
    }

    public function getIssue(string $ref, ProjectConfig $cfg): Issue
    {
        return new Issue('github', $ref, 'u', 'Fix the thing', 'WK');
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

final class NewTaskModalTest extends TestCase
{
    use UsesGlobalConfig;

    private string $tmp;
    private string $projectsDir;
    private Store $store;
    private FakeAgents $agents;
    private FakeGit $git;
    private FakeAnalytics $analytics;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-modal-'.uniqid();
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
        $this->writeProject();
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
        putenv('PABLO_PROJECTS_DIR='.$this->projectsDir);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
        $this->unsetGlobalConfig();
    }

    private function writeProject(): void
    {
        file_put_contents($this->projectsDir.'/wallet-kit.yaml', \sprintf(
            <<<'YAML'
name: wallet-kit
type: work
repo:
  path: %s/repo
  primary_branch: main
worktrees_root: %s/wt
issue_tracker:
  provider: github
  identity: octocat
  project_key: WK
YAML,
            $this->tmp,
            $this->tmp,
        ));
    }

    private function modal(): NewTaskModal
    {
        $providers = new class implements ProviderRegistryInterface {
            public function get(string $name): Provider
            {
                return new StartIssueProvider();
            }
        };
        $starter = new TaskStarter(
            $providers,
            new Naming(),
            $this->git,
            new StateMachine(new FakeGhPr(), $providers, new RepoSlug($this->git), new Time()),
            new TaskSummarizer(new FakeProcessRunner()),
            $this->analytics,
            $this->store,
            new Config(new GlobalConfig()),
            $this->agents,
        );

        return new NewTaskModal($starter);
    }

    public function testIssueModeRejectsEmptyInput(): void
    {
        $modal = $this->modal();
        $modal->mode = 'issue';
        $modal->text = '';
        $modal->start();
        $this->assertStringContainsString('issue URL', (string) $modal->error);
        $this->assertNull($modal->message);
    }

    public function testIssueModeStartsOnUrl(): void
    {
        $modal = $this->modal();
        $modal->mode = 'issue';
        $modal->text = 'https://github.com/acme/wallet-kit/issues/9';
        $modal->start();
        $this->assertNull($modal->error);
        $this->assertStringContainsString('Started 9', (string) $modal->message);
        $this->assertFalse($modal->open);
        $tasks = $this->store->allTasks('wallet-kit');
        $this->assertCount(1, $tasks);
        $this->assertSame('wk-9', $tasks[0]->branch);
    }

    public function testPromptModeRequiresProject(): void
    {
        $modal = $this->modal();
        $modal->mode = 'prompt';
        $modal->text = 'do the thing';
        $modal->project = '';
        $modal->start();
        $this->assertSame('Pick a project for the prompt task.', $modal->error);
    }

    public function testPromptModeStartsOnSelectedProject(): void
    {
        $modal = $this->modal();
        $modal->mode = 'prompt';
        $modal->text = 'fix callback verification quickly now';
        $modal->project = 'wallet-kit';
        $modal->start();
        $this->assertNull($modal->error);
        $this->assertStringContainsString('Started task in project wallet-kit', (string) $modal->message);
        $task = $this->store->get('wallet-kit', 'wk-fix-callback-verification-quickly');
        $this->assertNotNull($task);
        $this->assertSame(State::InProgress, $task->state);
    }

    public function testProjectChoicesListConfiguredProjectsByType(): void
    {
        $this->assertSame(['work' => ['wallet-kit']], $this->modal()->projectChoices());
    }
}
