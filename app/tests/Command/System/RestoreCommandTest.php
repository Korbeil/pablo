<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Backup\Backup;
use Pablo\Command\System\RestoreCommand;
use Pablo\Config\Config;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Store\Store;
use Pablo\Tests\Git\RepoHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RestoreCommandTest extends TestCase
{
    private string $tmp;
    private string $clone;
    private string $pabloRoot;
    private string $projectsDir;
    private string $wt;
    private string $prevCwd;
    private Store $store;

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
        mkdir($this->pabloRoot.'/state/sezane', 0o777, true);
        mkdir($this->projectsDir, 0o777, true);

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
        file_put_contents($this->projectsDir.'/sezane.yaml', \sprintf(
            <<<'YAML'
name: sezane
type: work
repo:
  path: %s/clone
  primary_branch: main
worktrees_root: %s
issue_tracker:
  provider: jira
  identity: korbeil
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
        $task = new Task('sezane', 'oms-1', $this->tmp.'/old/oms-1', State::InProgress);
        $this->store->save($task);

        putenv('PABLO_ROOT='.$this->pabloRoot);
        putenv('PABLO_PROJECTS_DIR='.$this->projectsDir);
        $this->prevCwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->prevCwd);
        putenv('PABLO_ROOT');
        putenv('PABLO_PROJECTS_DIR');
        Backup::cleanupDir($this->tmp);
    }

    public function testRestoreRecreatesWorktreeAndRewritesTaskPath(): void
    {
        $projects = Config::loadProjects($this->projectsDir);
        $archive = Backup::writeArchive($this->pabloRoot, $this->projectsDir, $projects, $this->store, $this->tmp.'/backup');

        $command = new RestoreCommand($this->store);
        $tester = new CommandTester($command);
        $tester->setInputs([$this->clone]);
        $tester->execute(['archive' => $archive, '--yes' => true]);

        $this->assertSame(0, $tester->getStatusCode());

        $recreated = $this->wt.'/oms-1';
        $this->assertDirectoryExists($recreated);
        $this->assertSame('oms-1', RepoHelper::git($recreated, ['branch', '--show-current']));
        $this->assertSame('work', rtrim((string) file_get_contents($recreated.'/task.txt')));

        $task = $this->store->get('sezane', 'oms-1');
        $this->assertNotNull($task);
        $this->assertSame($recreated, $task->worktreePath);
        $this->assertSame(State::InProgress, $task->state);
    }

    public function testRestoreSkipsUnpushedBranchWithWarning(): void
    {
        RepoHelper::git($this->clone, ['checkout', '-b', 'local-only']);
        RepoHelper::git($this->clone, ['checkout', 'main']);
        $this->store->save(new Task('sezane', 'local-only', $this->tmp.'/old/local-only', State::InProgress));

        $projects = Config::loadProjects($this->projectsDir);
        $archive = Backup::writeArchive($this->pabloRoot, $this->projectsDir, $projects, $this->store, $this->tmp.'/backup');

        $command = new RestoreCommand($this->store);
        $tester = new CommandTester($command);
        $tester->setInputs([$this->clone]);
        $tester->execute(['archive' => $archive, '--yes' => true]);

        $this->assertStringContainsString('local-only', $tester->getDisplay());
        $this->assertStringContainsString('skipped', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->wt.'/local-only');
    }

    public function testSkipWorktreesRestoresStateOnly(): void
    {
        $projects = Config::loadProjects($this->projectsDir);
        $archive = Backup::writeArchive($this->pabloRoot, $this->projectsDir, $projects, $this->store, $this->tmp.'/backup');

        $command = new RestoreCommand($this->store);
        $tester = new CommandTester($command);
        $tester->execute(['archive' => $archive, '--yes' => true, '--skip-worktrees' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertDirectoryDoesNotExist($this->wt.'/oms-1');
    }
}
