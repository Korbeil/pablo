<?php

declare(strict_types=1);

namespace Pablo\Tests\Command;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Pablo\Tests\FakeAgents;
use Pablo\Tests\FakeGhPr;
use Pablo\Tests\FakeGit;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\StubProviders;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

abstract class CommandTestBed extends TestCase
{
    use UsesGlobalConfig;

    protected string $tmp;
    protected string $wt;
    protected string $projectsDir;
    protected Store $store;
    protected FakeAgents $agents;
    protected FakeGit $git;
    protected FakeGhPr $gh;
    protected FakeProcessRunner $runner;
    protected Config $projectsLoader;
    protected GlobalConfig $globalConfig;
    protected AgentLauncherFactory $agentLaunchers;
    protected StubProviders $providers;
    protected RepoSlug $repoSlug;
    protected StateMachine $stateMachine;
    protected Time $time;
    protected string $prevCwd;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-cmd-'.uniqid();
        mkdir($this->tmp.'/repo', 0o777, true);
        mkdir($this->tmp.'/wt', 0o777, true);
        $this->wt = $this->tmp.'/wt/wk-45';
        (new \Symfony\Component\Process\Process(['git', 'init', '-q', $this->wt]))->run();

        $this->projectsDir = $this->tmp.'/projects';
        mkdir($this->projectsDir, 0o777, true);
        $this->writeProjects();

        $this->store = new Store($this->tmp.'/state');
        $this->agents = new FakeAgents();
        $this->runner = new FakeProcessRunner();
        $this->time = new Time();

        $this->git = new FakeGit();
        $this->git->originUrl = 'git@github.com:acme/wallet-kit.git';

        $this->gh = new FakeGhPr();
        $this->providers = new StubProviders();

        $this->globalConfig = new GlobalConfig();
        $this->projectsLoader = new Config($this->globalConfig);
        $this->agentLaunchers = new AgentLauncherFactory($this->globalConfig);
        $this->repoSlug = new RepoSlug($this->git);
        $this->stateMachine = new StateMachine($this->gh, $this->providers, $this->repoSlug, $this->time);

        $task = new Task('wallet-kit', 'wk-45', $this->wt, State::InProgress);
        $task->prNumber = 7;
        $task->issue = new Issue('github', '45', 'u', 'T', 'WK');
        $this->store->save($task);

        putenv('PABLO_PROJECTS_DIR='.$this->projectsDir);
        $this->prevCwd = (string) getcwd();
        chdir($this->wt);
    }

    protected function tearDown(): void
    {
        chdir($this->prevCwd);
        putenv('PABLO_PROJECTS_DIR');
        $this->unsetGlobalConfig();
    }

    protected function writeProjects(?string $startupScript = null): void
    {
        $script = null !== $startupScript ? "startup_script: {$startupScript}\n" : '';
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
  identity: octocat
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
