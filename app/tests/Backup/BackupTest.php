<?php

declare(strict_types=1);

namespace Pablo\Tests\Backup;

use Pablo\Backup\Backup;
use Pablo\Config\Config;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Git\GitRepo;
use Pablo\Store\Store;
use Pablo\Support\Proc;
use PHPUnit\Framework\TestCase;

final class BackupTest extends TestCase
{
    private string $tmp;
    private string $pabloRoot;
    private string $projectsDir;
    private string $dest;
    private Store $store;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-backup-'.uniqid();
        $this->pabloRoot = $this->tmp.'/pablo';
        $this->projectsDir = $this->tmp.'/projects';
        $this->dest = $this->tmp.'/out';

        mkdir($this->pabloRoot.'/state/acme', 0o777, true);
        mkdir($this->pabloRoot.'/stamps', 0o777, true);
        mkdir($this->pabloRoot.'/logs', 0o777, true);
        mkdir($this->pabloRoot.'/cache', 0o777, true);
        mkdir($this->pabloRoot.'/agents/locks', 0o777, true);
        mkdir($this->pabloRoot.'/worktrees/acme/oms-1', 0o777, true);

        file_put_contents($this->pabloRoot.'/stamps/acme.poll', '1');
        file_put_contents($this->pabloRoot.'/logs/rebase-last-acme.json', '{}');
        file_put_contents($this->pabloRoot.'/cache/jira.json', '{}');
        file_put_contents($this->pabloRoot.'/agents/locks/session.lock', 'secret-agent');
        file_put_contents($this->pabloRoot.'/worktrees/acme/oms-1/file.txt', 'x');

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
        file_put_contents($this->projectsDir.'/acme.yaml', \sprintf(
            <<<'YAML'
name: acme
type: work
repo:
  path: %s/repo
  primary_branch: main
worktrees_root: %s/wt
issue_tracker:
  provider: jira
  identity: user
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
            $this->tmp,
        ));

        $this->store = new Store($this->pabloRoot.'/state');
        $task = new Task('acme', 'oms-1', $this->pabloRoot.'/worktrees/acme/oms-1', State::InProgress);
        $this->store->save($task);

        GitRepo::setOriginUrl(static fn () => 'git@example.com:acme/acme.git');
        Proc::setRunner(static fn (array $argv) => match (true) {
            \in_array('orca', $argv, true) && \in_array('repo', $argv, true) && \in_array('list', $argv, true) => '{"ok":true,"result":{"repos":[{"path":"/tmp/acme"}]}}',
            default => throw new \RuntimeException('unexpected proc call: '.implode(' ', $argv)),
        });
    }

    protected function tearDown(): void
    {
        Proc::setRunner(null);
        GitRepo::setOriginUrl(null);
        Backup::cleanupDir($this->tmp);
    }

    public function testWriteAndExtractArchiveIncludesStateExcludesAgents(): void
    {
        $projects = Config::loadProjects($this->projectsDir);
        $path = Backup::writeArchive($this->pabloRoot, $this->projectsDir, $projects, $this->store, $this->dest);

        $this->assertFileExists($path);

        $extracted = $this->tmp.'/extract';
        $manifest = Backup::extractArchive($path, $extracted);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('git@example.com:acme/acme.git', $manifest['projects'][0]['origin_url']);
        $this->assertSame(['oms-1'], $manifest['projects'][0]['branches']);

        $this->assertDirectoryExists($extracted.'/state/acme');
        $this->assertDirectoryExists($extracted.'/stamps');
        $this->assertDirectoryExists($extracted.'/logs');
        $this->assertDirectoryExists($extracted.'/cache');
        $this->assertFileExists($extracted.'/projects/acme.yaml');

        $this->assertFileExists($extracted.'/orca-repos.json');
        $orcaRepos = json_decode((string) file_get_contents($extracted.'/orca-repos.json'), true);
        $this->assertIsArray($orcaRepos);
        $this->assertTrue($orcaRepos['ok']);

        $this->assertDirectoryDoesNotExist($extracted.'/agents');
        $this->assertDirectoryDoesNotExist($extracted.'/worktrees');
    }

    public function testRestoreTreeAndProjectsIntoFreshRoot(): void
    {
        $projects = Config::loadProjects($this->projectsDir);
        $path = Backup::writeArchive($this->pabloRoot, $this->projectsDir, $projects, $this->store, $this->dest);
        $extracted = $this->tmp.'/extract';
        Backup::extractArchive($path, $extracted);

        $fresh = $this->tmp.'/fresh';
        $restored = Backup::restoreStoreTree($extracted, $fresh, true);
        $this->assertContains('state', $restored);
        $this->assertContains('stamps', $restored);
        $tag = array_values(array_filter($restored, static fn (string $s) => 'cache' === $s));
        $this->assertSame(['cache'], $tag);

        $freshStore = new Store($fresh.'/state');
        $task = $freshStore->get('acme', 'oms-1');
        $this->assertNotNull($task);
        $this->assertSame(State::InProgress, $task->state);

        $projectsResult = Backup::restoreProjects($extracted, $this->tmp.'/restored-projects', false);
        $this->assertContains('acme.yaml', $projectsResult);
        $this->assertFileExists($this->tmp.'/restored-projects/acme.yaml');
    }

    public function testRestoreSkipsExistingWhenNotOverwriting(): void
    {
        $projects = Config::loadProjects($this->projectsDir);
        $path = Backup::writeArchive($this->pabloRoot, $this->projectsDir, $projects, $this->store, $this->dest);
        $extracted = $this->tmp.'/extract';
        Backup::extractArchive($path, $extracted);

        $target = $this->tmp.'/target';
        mkdir($target.'/state', 0o777, true);
        file_put_contents($target.'/state/marker.json', '{}');
        $restored = Backup::restoreStoreTree($extracted, $target, false);
        $this->assertContains('state:skipped', $restored);
        $this->assertFileExists($target.'/state/marker.json');
    }
}
