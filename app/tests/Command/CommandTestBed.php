<?php

declare(strict_types=1);

namespace Pablo\Tests\Command;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Gh\GhPr;
use Pablo\Provider\Git\GitRepo;
use Pablo\Provider\Tracker\ProviderRegistry;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Pablo\Tests\FakeAgents;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

abstract class CommandTestBed extends TestCase
{
    protected string $tmp;
    protected string $wt;
    protected string $projectsDir;
    protected Store $store;
    protected FakeAgents $agents;
    protected string $prevCwd;

    /** @var list<int> recorded pr numbers sent to mark_draft */
    protected array $drafts = [];

    /** @var list<int> recorded pr numbers sent to mark_ready */
    protected array $readies = [];

    /** @var array<int, string> recorded rerun ids */
    protected array $rerunIds = [];

    /** @var list<array{0: string, 1: string}> recorded (path, branch) removals */
    protected array $removed = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-cmd-'.uniqid();
        mkdir($this->tmp.'/repo', 0o777, true);
        mkdir($this->tmp.'/wt', 0o777, true);
        $this->wt = $this->tmp.'/wt/wk-45';
        (new Process(['git', 'init', '-q', $this->wt]))->run();

        $this->projectsDir = $this->tmp.'/projects';
        mkdir($this->projectsDir, 0o777, true);
        $this->writeProjects();

        $this->store = new Store($this->tmp.'/state');
        $this->agents = new FakeAgents();

        $task = new Task('wallet-kit', 'wk-45', $this->wt, State::InProgress);
        $task->prNumber = 7;
        $task->issue = new Issue('github', '45', 'u', 'T', 'WK');
        $this->store->save($task);

        ProviderRegistry::setResolver(static fn (string $name) => new DevNullProvider());
        RepoSlug::setFor(static fn () => 'acme/wallet-kit');
        GitRepo::setOriginUrl(static fn () => 'git@github.com:acme/wallet-kit.git');
        GhPr::setMarkDraft(function (string $slug, int $pr): void { $this->drafts[] = $pr; });
        GhPr::setMarkReady(function (string $slug, int $pr): void { $this->readies[] = $pr; });
        GhPr::setRerunCi(function (string $slug, string $branch) {
            $this->rerunIds = ['42', '43'];

            return $this->rerunIds;
        });
        GitRepo::setRemoveWorktree(function (string $repo, string $path, string $branch): void { $this->removed[] = [$path, $branch]; });

        putenv('PABLO_PROJECTS_DIR='.$this->projectsDir);
        $this->prevCwd = (string) getcwd();
        chdir($this->wt);
    }

    protected function tearDown(): void
    {
        chdir($this->prevCwd);
        putenv('PABLO_PROJECTS_DIR');
        ProviderRegistry::setResolver(null);
        RepoSlug::setFor(null);
        GitRepo::setOriginUrl(null);
        GitRepo::setAllBranchNames(null);
        GitRepo::setRemoveWorktree(null);
        GhPr::setMarkDraft(null);
        GhPr::setMarkReady(null);
        GhPr::setRerunCi(null);
        GhPr::setPrForBranch(null);
        GhPr::setPrsForBranches(null);
    }

    protected function writeProjects(?string $startupScript = null): void
    {
        $script = null !== $startupScript ? "startup_script: {$startupScript}\n" : '';
        file_put_contents($this->projectsDir.'/default.yaml', <<<'YAML'
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
YAML);
        file_put_contents($this->projectsDir.'/wallet-kit.yaml', \sprintf(
            <<<'YAML'
name: wallet-kit
type: open-source
repo:
  path: %s/repo
  primary_branch: main
worktrees_root: %s/wt
issue_tracker:
  provider: github
  identity: user
  project_key: WK
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
%s
YAML,
            $this->tmp,
            $this->tmp,
            $script,
        ));
    }

    protected function getTask(): Task
    {
        $task = $this->store->get('wallet-kit', 'wk-45');
        $this->assertNotNull($task);

        return $task;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     */
    protected function runCommand(Command $command, array $input = [], array $options = []): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute($input, $options);

        return $tester;
    }
}

final class DevNullProvider implements \Pablo\Provider\Tracker\Provider
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
        return null;
    }

    public function getIssue(string $ref, ProjectConfig $cfg): Issue
    {
        throw new \LogicException();
    }

    public function listAssigned(ProjectConfig $cfg): array
    {
        return [];
    }

    public function issueStatus(string $key, ProjectConfig $cfg): string
    {
        return 'todo';
    }

    public function failureSignalEvents(Task $task, ProjectConfig $cfg): array
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
