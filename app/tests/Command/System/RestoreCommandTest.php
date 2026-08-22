<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Backup\Backup;
use Pablo\Command\System\RestoreCommand;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Store\Store;
use Pablo\Tests\FakeAgents;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\Git\RepoHelper;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RestoreCommandTest extends TestCase
{
    use UsesGlobalConfig;

    private string $tmp;
    private string $clone;
    private string $pabloRoot;
    private string $projectsDir;
    private string $wt;
    private string $prevCwd;
    private Store $store;
    private FakeProcessRunner $runner;

    /** @var list<list<string>> */
    private array $orcaCalls = [];

    private function makeBackup(): Backup
    {
        return new Backup(new \Pablo\Provider\Git\GitRepo(), $this->runner, new Time());
    }

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-restore-'.uniqid();
        $repos = RepoHelper::makeRepos($this->tmp);
        $this->clone = $repos['clone'];

        RepoHelper::git($this->clone, ['checkout', '-b', 'oms-1']);
        RepoHelper::commitFile($this->clone, 'task.txt', "work\n", 'task work');
        RepoHelper::git($this->clone, ['push', '-q', '-u', 'origin', 'oms-1']);
        RepoHelper::git($this->clone, ['checkout', 'main']);

        $this->pabloRoot = $this->tmp.'/pablo';
        $this->wt = $this->tmp.'/wt';
        $this->projectsDir = $this->tmp.'/projects';
        mkdir($this->pabloRoot.'/state/acme', 0o777, true);
        mkdir($this->projectsDir, 0o777, true);

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
        file_put_contents($this->projectsDir.'/acme.yaml', \sprintf(
            <<<'YAML'
name: acme
type: work
repo:
  path: %s/clone
  primary_branch: main
worktrees_root: %s
issue_tracker:
  provider: jira
  identity: acme@example.com
  project_key: SEZ
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
YAML,
            $this->tmp,
            $this->wt,
        ));

        $this->store = new Store($this->pabloRoot.'/state');
        $task = new Task('acme', 'oms-1', $this->tmp.'/old/oms-1', State::InProgress);
        $this->store->save($task);

        putenv('PABLO_ROOT='.$this->pabloRoot);
        putenv('PABLO_PROJECTS_DIR='.$this->projectsDir);
        $this->orcaCalls = [];
        $this->runner = new FakeProcessRunner();
        $this->runner->onRun = function (array $argv): string {
            if (\in_array('orca', $argv, true) && \in_array('repo', $argv, true) && \in_array('list', $argv, true)) {
                return '{"ok":true,"result":{"repos":[{"path":"/tmp/acme"}]}}';
            }
            if (\in_array('orca', $argv, true) && \in_array('repo', $argv, true) && \in_array('add', $argv, true)) {
                $this->orcaCalls[] = array_values($argv);

                return '{"ok":true,"result":{}}';
            }

            throw new \RuntimeException('unexpected proc call: '.implode(' ', $argv));
        };
        $this->prevCwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->prevCwd);
        putenv('PABLO_ROOT');
        putenv('PABLO_PROJECTS_DIR');
        $this->unsetGlobalConfig();
        $backup = $this->makeBackup();
        $backup->cleanupDir($this->tmp);
    }

    public function testRestoreRecreatesWorktreeAndRewritesTaskPath(): void
    {
        $loader = new Config(new GlobalConfig());
        $projects = $loader->loadProjects($this->projectsDir);
        $archive = $this->makeBackup()->writeArchive($this->pabloRoot, $this->projectsDir, $projects, $this->store, $this->tmp.'/backup');

        $command = new RestoreCommand($this->makeBackup(), new \Pablo\Provider\Git\GitRepo(), $this->runner, $this->store, $loader, new \Pablo\Agents\AgentLauncherFactory(new GlobalConfig()), new FakeAgents());
        $tester = new CommandTester($command);
        $tester->setInputs([$this->clone]);
        $tester->execute(['archive' => $archive, '--yes' => true]);

        $this->assertSame(0, $tester->getStatusCode());

        $recreated = $this->wt.'/oms-1';
        $this->assertDirectoryExists($recreated);
        $this->assertSame('oms-1', RepoHelper::git($recreated, ['branch', '--show-current']));
        $this->assertSame('work', rtrim((string) file_get_contents($recreated.'/task.txt')));

        $task = $this->store->get('acme', 'oms-1');
        $this->assertNotNull($task);
        $this->assertSame($recreated, $task->worktreePath);
        $this->assertSame(State::InProgress, $task->state);

        $this->assertCount(1, $this->orcaCalls);
        $this->assertContains('--path', $this->orcaCalls[0]);
        $this->assertContains($this->clone, $this->orcaCalls[0]);
    }

    public function testRestoreSkipsUnpushedBranchWithWarning(): void
    {
        RepoHelper::git($this->clone, ['checkout', '-b', 'local-only']);
        RepoHelper::git($this->clone, ['checkout', 'main']);
        $this->store->save(new Task('acme', 'local-only', $this->tmp.'/old/local-only', State::InProgress));

        $loader = new Config(new GlobalConfig());
        $projects = $loader->loadProjects($this->projectsDir);
        $archive = $this->makeBackup()->writeArchive($this->pabloRoot, $this->projectsDir, $projects, $this->store, $this->tmp.'/backup');

        $command = new RestoreCommand($this->makeBackup(), new \Pablo\Provider\Git\GitRepo(), $this->runner, $this->store, $loader, new \Pablo\Agents\AgentLauncherFactory(new GlobalConfig()), new FakeAgents());
        $tester = new CommandTester($command);
        $tester->setInputs([$this->clone]);
        $tester->execute(['archive' => $archive, '--yes' => true]);

        $this->assertStringContainsString('local-only', $tester->getDisplay());
        $this->assertStringContainsString('skipped', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->wt.'/local-only');
    }

    public function testSkipWorktreesRestoresStateOnly(): void
    {
        $loader = new Config(new GlobalConfig());
        $projects = $loader->loadProjects($this->projectsDir);
        $archive = $this->makeBackup()->writeArchive($this->pabloRoot, $this->projectsDir, $projects, $this->store, $this->tmp.'/backup');

        $command = new RestoreCommand($this->makeBackup(), new \Pablo\Provider\Git\GitRepo(), $this->runner, $this->store, $loader, new \Pablo\Agents\AgentLauncherFactory(new GlobalConfig()), new FakeAgents());
        $tester = new CommandTester($command);
        $tester->execute(['archive' => $archive, '--yes' => true, '--skip-worktrees' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertDirectoryDoesNotExist($this->wt.'/oms-1');
        $this->assertCount(0, $this->orcaCalls);
    }

    public function testSkipOrcaRegistersNoRepos(): void
    {
        $loader = new Config(new GlobalConfig());
        $projects = $loader->loadProjects($this->projectsDir);
        $archive = $this->makeBackup()->writeArchive($this->pabloRoot, $this->projectsDir, $projects, $this->store, $this->tmp.'/backup');

        $command = new RestoreCommand($this->makeBackup(), new \Pablo\Provider\Git\GitRepo(), $this->runner, $this->store, $loader, new \Pablo\Agents\AgentLauncherFactory(new GlobalConfig()), new FakeAgents());
        $tester = new CommandTester($command);
        $tester->setInputs([$this->clone]);
        $tester->execute(['archive' => $archive, '--yes' => true, '--skip-orca' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertDirectoryExists($this->wt.'/oms-1');
        $this->assertCount(0, $this->orcaCalls);
    }
}
