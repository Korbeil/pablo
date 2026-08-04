<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Task;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Provider\Git\GitRepo;
use Pablo\Provider\Tracker\Provider;
use Pablo\Provider\Tracker\ProviderRegistry;
use Pablo\Store\Store;
use Pablo\Tests\FakeAgents;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class FakeStartProvider implements Provider
{
    public function __construct(private ?Issue $issue = null)
    {
    }

    public function name(): string
    {
        return null !== $this->issue ? $this->issue->provider : 'github';
    }

    public function supportsSignalViaStatus(): bool
    {
        return false;
    }

    public function matchUrl(string $url, ProjectConfig $cfg): ?string
    {
        if (null !== $this->issue && $url === $this->issue->url) {
            return $this->issue->key;
        }

        return null;
    }

    public function getIssue(string $ref, ProjectConfig $cfg): Issue
    {
        if (null === $this->issue) {
            throw new \LogicException('no issue');
        }

        return $this->issue;
    }

    public function listAssigned(ProjectConfig $cfg): array
    {
        return [];
    }

    public function issueStatus(string $key, ProjectConfig $cfg): string
    {
        return 'todo';
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

final class StartCommandTest extends TestCase
{
    private string $tmp;
    private string $projectsDir;
    private Store $store;
    private FakeAgents $agents;

    /** @var list<array{0: string, 1: string}> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-start-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $this->projectsDir = $this->tmp.'/projects';
        mkdir($this->projectsDir, 0o777, true);
        $this->store = new Store($this->tmp.'/state');
        $this->agents = new FakeAgents();
        $default = <<<'YAML'
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
YAML;
        file_put_contents($this->projectsDir.'/default.yaml', $default);
        putenv('PABLO_PROJECTS_DIR='.$this->projectsDir);
        GitRepo::setAllBranchNames(static fn () => []);
        GitRepo::setOriginUrl(static fn () => 'git@github.com:acme/wallet-kit.git');
        GitRepo::setCreateWorktree(function (string $repo, string $root, string $branch, string $base): string {
            $this->created[] = [$branch, $base];
            $path = rtrim($root, '/').'/'.$branch;
            @mkdir(\dirname($path), 0o777, true);

            return $path;
        });
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
        GitRepo::setAllBranchNames(null);
        GitRepo::setOriginUrl(null);
        GitRepo::setCreateWorktree(null);
        ProviderRegistry::setResolver(null);
    }

    private function writeProject(string $name, ?string $repo = null, ?string $projKey = null, string $provider = 'github'): void
    {
        $repo ??= $this->tmp.'/'.$name;
        $projKey ??= strtoupper(substr($name, 0, 2));
        file_put_contents($this->projectsDir.'/'.$name.'.yaml', \sprintf(
            <<<'YAML'
name: %s
type: work
repo:
  path: %s
  primary_branch: main
worktrees_root: %s/wt
issue_tracker:
  provider: %s
  identity: user
  project_key: %s
YAML,
            $name,
            $repo,
            $name,
            $provider,
            $projKey,
        ));
    }

    /** @param array<string, Provider> $byName */
    private function configureProviders(array $byName): void
    {
        ProviderRegistry::setResolver(static fn (string $name) => $byName[$name] ?? throw new \LogicException('no provider '.$name));
    }

    /** @param array<string, mixed> $input */
    private function runCommand(array $input): CommandTester
    {
        $cmd = new \Pablo\Command\Task\StartCommand($this->store, $this->agents);
        $tester = new CommandTester($cmd);
        $tester->execute($input);

        return $tester;
    }

    public function testStartFromUrlCreatesWorktreeAndTask(): void
    {
        $issue = new Issue('github', '45', 'https://github.com/acme/wallet-kit/issues/45', 'Fix callback verification', 'WK');
        $this->writeProject('wallet-kit', $this->tmp.'/repo', 'WK');
        $this->configureProviders(['github' => new FakeStartProvider($issue)]);

        $tester = $this->runCommand(['input' => [$issue->url]]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame([['wk-45', 'main']], $this->created);
        $task = $this->store->get('wallet-kit', 'wk-45');
        $this->assertNotNull($task);
        $this->assertNotNull($task->issue);
        $this->assertSame(State::InProgress, $task->state);
        $this->assertSame('45', $task->issue->key);
        $this->assertSame(['task-analyst'], $this->agents->launch);
        $this->assertStringContainsString('wk-45', $tester->getDisplay());
    }

    public function testStartFromUrlSetsOrcaDisplayNameToIssueKey(): void
    {
        $issue = new Issue('github', '45', 'https://github.com/acme/wallet-kit/issues/45', 'T', 'WK');
        $this->writeProject('wallet-kit', $this->tmp.'/repo', 'WK');
        $this->configureProviders(['github' => new FakeStartProvider($issue)]);
        $this->runCommand(['input' => [$issue->url]]);
        $this->assertSame(['45'], $this->agents->displayNames);
    }

    public function testStartUnmatchedUrlErrors(): void
    {
        $this->writeProject('wallet-kit', $this->tmp.'/repo', 'WK');
        $this->configureProviders(['github' => new FakeStartProvider(new Issue('github', '45', 'x', 'T', 'WK'))]);
        $tester = $this->runCommand(['input' => ['https://github.com/other/repo/issues/1']]);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('no managed project matches', $tester->getDisplay());
    }

    public function testStartSameIssueReuses(): void
    {
        $issue = new Issue('github', '45', 'https://github.com/acme/wallet-kit/issues/45', 'T', 'WK');
        $this->writeProject('wallet-kit', $this->tmp.'/repo', 'WK');
        $this->configureProviders(['github' => new FakeStartProvider($issue)]);
        $this->runCommand(['input' => [$issue->url]]);
        $tester = $this->runCommand(['input' => [$issue->url]]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertCount(1, $this->created);
        $this->assertStringContainsString('already', $tester->getDisplay());
    }

    public function testStartConflictingBranchGetsSuffix(): void
    {
        GitRepo::setAllBranchNames(static fn () => ['wk-45']);
        $issue = new Issue('github', '45', 'https://github.com/acme/wallet-kit/issues/45', 'T', 'WK');
        $this->writeProject('wallet-kit', $this->tmp.'/repo', 'WK');
        $this->configureProviders(['github' => new FakeStartProvider($issue)]);
        $this->runCommand(['input' => [$issue->url]]);
        $this->assertSame([['wk-45-2', 'main']], $this->created);
    }

    public function testStartPromptRequiresProject(): void
    {
        $this->writeProject('wallet-kit', $this->tmp.'/repo', 'WK');
        $tester = $this->runCommand(['input' => ['fix callback verification']]);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('--project', $tester->getDisplay());
    }

    public function testStartPromptCreatesSlugBranch(): void
    {
        $this->writeProject('wallet-kit', $this->tmp.'/repo', 'WK');
        $this->configureProviders(['github' => new FakeStartProvider(null)]);
        $tester = $this->runCommand(['--project' => 'wallet-kit', 'input' => ['fix callback verification quickly now']]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame([['wk-fix-callback-verification-quickly', 'main']], $this->created);
        $task = $this->store->get('wallet-kit', 'wk-fix-callback-verification-quickly');
        $this->assertNotNull($task);
        $this->assertNull($task->issue);
        $this->assertSame('fix callback verification quickly now', $task->prompt);
        $this->assertSame(['task-analyst'], $this->agents->launch);
    }

    public function testStartUnknownProjectErrors(): void
    {
        $this->writeProject('wallet-kit', $this->tmp.'/repo', 'WK');
        $tester = $this->runCommand(['--project' => 'nope', 'input' => ['do something']]);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('wallet-kit', $tester->getDisplay());
    }

    public function testStartPromptWithIssueKeyResolvesIssue(): void
    {
        $this->writeProject('wallet-kit', $this->tmp.'/repo', 'WK');
        $this->writeProject('acme-oms', $this->tmp.'/oms-repo', 'OMS', 'jira');
        $jiraIssue = new Issue('jira', 'OMS-6393', 'https://example.atlassian.net/browse/OMS-6393', 'Release gallery ML', 'OMS');
        $this->configureProviders(['github' => new FakeStartProvider(null), 'jira' => new FakeStartProvider($jiraIssue)]);

        $tester = $this->runCommand(['--project' => 'acme-oms', 'input' => ['fix the thing per OMS-6393 please']]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame([['oms-6393', 'main']], $this->created);
        $task = $this->store->get('acme-oms', 'oms-6393');
        $this->assertNotNull($task);
        $this->assertNotNull($task->issue);
        $this->assertSame('OMS-6393', $task->issue->key);
        $this->assertStringContainsString('OMS-6393', $tester->getDisplay());
    }

    public function testStartWithJiraKeySetsOrcaDisplayName(): void
    {
        $this->writeProject('wallet-kit', $this->tmp.'/repo', 'WK');
        $this->writeProject('acme-oms', $this->tmp.'/oms-repo', 'OMS', 'jira');
        $jiraIssue = new Issue('jira', 'OMS-6393', 'https://example.atlassian.net/browse/OMS-6393', 'T', 'OMS');
        $this->configureProviders(['github' => new FakeStartProvider(null), 'jira' => new FakeStartProvider($jiraIssue)]);
        $this->runCommand(['--project' => 'acme-oms', 'input' => ['fix the thing per OMS-6393 please']]);
        $this->assertSame(['OMS-6393'], $this->agents->displayNames);
    }

    public function testStartPromptWithUnconfiguredKeyFallsBackToSlug(): void
    {
        $this->writeProject('wallet-kit', $this->tmp.'/repo', 'WK');
        $this->writeProject('acme-oms', $this->tmp.'/oms-repo', 'OMS', 'jira');
        $this->configureProviders(['github' => new FakeStartProvider(null), 'jira' => new FakeStartProvider(new Issue('jira', 'OMS-6393', 'u', 'T', 'OMS'))]);
        $this->runCommand(['--project' => 'wallet-kit', 'input' => ['fix the thing per XYZ-999 please']]);
        $this->assertSame([['wk-fix-the-thing-per', 'main']], $this->created);
    }

    public function testStartWithProjectDisambiguatesSharedKey(): void
    {
        $this->writeProject('acme-oms', $this->tmp.'/ecommerce', 'OMS', 'jira');
        $this->writeProject('acme-retail', $this->tmp.'/retail', 'OMS', 'jira');
        $this->configureProviders(['jira' => new FakeStartProvider(new Issue('jira', 'OMS-6393', 'u', 'T', 'OMS'))]);
        $tester = $this->runCommand(['--project' => 'acme-retail', 'input' => ['OMS-6393']]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $task = $this->store->get('acme-retail', 'oms-6393');
        $this->assertNotNull($task);
        $this->assertNotNull($task->issue);
        $this->assertSame('acme-retail', $task->project);
        $this->assertSame('OMS-6393', $task->issue->key);
        $this->assertStringContainsString('acme-retail', $tester->getDisplay());
    }

    public function testStartWithoutProjectUsesFirstMatchForSharedKey(): void
    {
        $this->writeProject('acme-oms', $this->tmp.'/ecommerce', 'OMS', 'jira');
        $this->writeProject('acme-retail', $this->tmp.'/retail', 'OMS', 'jira');
        $this->configureProviders(['jira' => new FakeStartProvider(new Issue('jira', 'OMS-6393', 'u', 'T', 'OMS'))]);
        $tester = $this->runCommand(['input' => ['OMS-6393']]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $task = $this->store->get('acme-oms', 'oms-6393');
        $this->assertNotNull($task);
        $this->assertNotNull($task->issue);
        $this->assertSame('acme-oms', $task->project);
        $this->assertStringContainsString('acme-oms', $tester->getDisplay());
    }
}
