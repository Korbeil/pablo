<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Store\Store;
use Pablo\Store\TaskLockedException;
use Pablo\Support\PabloError;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private string $root;
    private Store $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/pablo-store-'.uniqid();
        mkdir($this->root, 0o777, true);
        $this->store = new Store($this->root.'/state');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function makeTask(string $project = 'wallet-kit', string $branch = 'wk-45'): Task
    {
        $task = new Task(
            project: $project,
            branch: $branch,
            worktreePath: "/tmp/worktrees/{$project}/{$branch}",
            state: State::InProgress,
        );
        $task->issue = new Issue(
            provider: 'github',
            key: '45',
            url: 'https://github.com/o/r/issues/45',
            title: 'Fix callback verification',
            projectKey: 'WK',
        );

        return $task;
    }

    public function testSaveAndGetRoundtrip(): void
    {
        $this->store->save($this->makeTask());
        $loaded = $this->store->get('wallet-kit', 'wk-45');
        $this->assertNotNull($loaded);
        $this->assertSame('wk-45', $loaded->branch);
        $this->assertSame(State::InProgress, $loaded->state);
        $this->assertNotNull($loaded->issue);
        $this->assertSame('Fix callback verification', $loaded->issue->title);
        $this->assertFalse($loaded->taskAnalystRan);
        $this->assertFalse($loaded->merged);
    }

    public function testGetMissingReturnsNull(): void
    {
        $this->assertNull($this->store->get('wallet-kit', 'nope'));
    }

    public function testDeleteRemovesRecord(): void
    {
        $this->store->save($this->makeTask());
        $this->store->delete('wallet-kit', 'wk-45');
        $this->assertNull($this->store->get('wallet-kit', 'wk-45'));
    }

    public function testAllTasksAcrossProjects(): void
    {
        $this->store->save($this->makeTask('a', 'a-1'));
        $this->store->save($this->makeTask('b', 'b-1'));
        $this->assertSame(['a', 'b'], array_map(static fn (Task $t) => $t->project, $this->store->allTasks()));
        $this->assertSame(['a'], array_map(static fn (Task $t) => $t->project, $this->store->allTasks('a')));
    }

    public function testAllTasksSkipsAndReportsUnreadableRecords(): void
    {
        $this->store->save($this->makeTask('a', 'a-1'));
        $this->store->save($this->makeTask('b', 'b-1'));
        $corrupt = $this->root.'/state/a/broken.json';
        file_put_contents($corrupt, '{"project": "a", "bra');
        $notObject = $this->root.'/state/b/list.json';
        file_put_contents($notObject, '[1, 2]');
        $stderr = fopen('php://memory', 'w+');
        $this->assertIsResource($stderr);
        $this->store->stderr = $stderr;

        $this->assertSame(['a-1', 'b-1'], array_map(static fn (Task $t) => $t->branch, $this->store->allTasks()));
        // Warned once per file, not on every call.
        $this->store->allTasks();

        $unreadable = $this->store->unreadableRecords();
        $this->assertSame([$corrupt, $notObject], array_keys($unreadable));
        $this->assertStringStartsWith('invalid JSON', $unreadable[$corrupt]);
        $this->assertSame('not a JSON object', $unreadable[$notObject]);
        rewind($stderr);
        $out = (string) stream_get_contents($stderr);
        $this->assertSame(1, substr_count($out, $corrupt));
        $this->assertSame(1, substr_count($out, $notObject));
        $this->assertStringContainsString('skipping unreadable task record', $out);
    }

    public function testGetThrowsANamedErrorOnACorruptRecord(): void
    {
        $this->store->save($this->makeTask());
        $path = $this->store->path('wallet-kit', 'wk-45');
        file_put_contents($path, '');

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage("cannot read task record {$path}: invalid JSON");
        $this->store->get('wallet-kit', 'wk-45');
    }

    public function testSaveLeavesNoTempFileBehind(): void
    {
        $this->store->save($this->makeTask());
        $this->store->save($this->makeTask());
        $this->assertSame(['wk-45.json'], array_values(array_diff(scandir($this->root.'/state/wallet-kit') ?: [], ['.', '..'])));
    }

    public function testSaveUsesAUniqueTempName(): void
    {
        // Another writer's in-flight temp file under the old fixed name
        // (a directory here, so reusing that name would fail outright).
        $fixed = $this->store->path('wallet-kit', 'wk-45').'.tmp';
        mkdir($fixed, 0o777, true);

        $this->store->save($this->makeTask());

        $this->assertNotNull($this->store->get('wallet-kit', 'wk-45'));
        $this->assertDirectoryExists($fixed);
    }

    public function testFailedSaveKeepsTheExistingRecord(): void
    {
        if (0 === posix_geteuid()) {
            $this->markTestSkipped('root ignores directory permissions');
        }
        $task = $this->makeTask();
        $task->summary = 'original';
        $this->store->save($task);
        $path = $this->store->path('wallet-kit', 'wk-45');
        $before = (string) file_get_contents($path);

        $dir = \dirname($path);
        chmod($dir, 0o555);
        try {
            $task->summary = 'clobbered';
            $this->store->save($task);
            $this->fail('expected PabloError');
        } catch (PabloError $e) {
            $this->assertStringContainsString("cannot write task record {$path}", $e->getMessage());
        } finally {
            chmod($dir, 0o755);
        }
        $this->assertSame($before, file_get_contents($path));
        $this->assertSame(['wk-45.json'], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])));
    }

    public function testSlashBranchIsFlattenedToTheStateFilename(): void
    {
        $task = $this->makeTask('sezane-pim', 'release/pim-upgrade-php-version');
        $this->store->save($task);

        $file = $this->root.'/state/sezane-pim/release__pim-upgrade-php-version.json';
        $this->assertFileExists($file);
        $this->assertFileDoesNotExist(\dirname($file, 2).'/sezane-pim/release/pim-upgrade-php-version.json');

        $loaded = $this->store->get('sezane-pim', 'release/pim-upgrade-php-version');
        $this->assertNotNull($loaded);
        $this->assertSame('release/pim-upgrade-php-version', $loaded->branch);

        $all = $this->store->allTasks();
        $this->assertCount(1, $all);
        $this->assertSame('sezane-pim', $all[0]->project);
        $this->assertSame('release/pim-upgrade-php-version', $all[0]->branch);

        $this->store->delete('sezane-pim', 'release/pim-upgrade-php-version');
        $this->assertFileDoesNotExist($file);
    }

    public function testPromptTaskSerializesNullIssue(): void
    {
        $task = $this->makeTask();
        $task->issue = null;
        $task->summary = 'fix callback verification';
        $this->store->save($task);
        $raw = json_decode((string) file_get_contents(
            $this->root.'/state/wallet-kit/wk-45.json',
        ), true);
        $this->assertNull($raw['issue']);
        $this->assertSame('fix callback verification', $raw['summary']);
    }

    public function testLockReleasedOnRelease(): void
    {
        $l = $this->store->taskLock('p', 'b');
        $l->release();
        // A still-held lock makes this throw TaskLockedException once the
        // (deliberately short) timeout elapses; getting a fresh, distinct lock
        // back is the proof that release() actually released.
        $l2 = $this->store->taskLock('p', 'b', timeoutS: 1.0);
        $this->assertNotSame($l, $l2);
        $l2->release();
    }

    public function testLockExcludesSecondHolder(): void
    {
        $autoload = \dirname(__DIR__, 2).'/vendor/autoload.php';
        $root = $this->store->root();
        $prog = \sprintf(
            <<<'PHP'
require %s;
$store = new \Pablo\Store\Store(%s);
$lock = $store->taskLock("p", "b");
echo "held\n";
flush();
fgets(STDIN); // hold the lock until the test closes our stdin
$lock->release();
PHP,
            var_export($autoload, true),
            var_export($root, true),
        );
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([\PHP_BINARY, '-r', $prog], $descriptors, $pipes);
        $this->assertIsResource($proc);
        $this->assertSame('held', trim((string) fgets($pipes[1])));
        $start = microtime(true);
        try {
            $lock = $this->store->taskLock('p', 'b', 0.3);
            $lock->release();
            $this->fail('expected TaskLockedException');
        } catch (TaskLockedException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        }
        $this->assertLessThan(4.0, microtime(true) - $start);
        fclose($pipes[0]);
        proc_close($proc);
    }

    public function testTaskForCwdRejectsNonTaskDir(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('not a PABLO task worktree');
        $this->store->taskForCwd(sys_get_temp_dir());
    }

    public function testTaskForCwdResolvesWorktree(): void
    {
        $wt = $this->root.'/wt';
        mkdir($wt);
        $process = new \Symfony\Component\Process\Process(['git', 'init', '-q', $wt]);
        $process->run();
        $task = $this->makeTask();
        $task->worktreePath = $wt;
        $this->store->save($task);
        mkdir($wt.'/sub');
        $found = $this->store->taskForCwd($wt.'/sub');
        $this->assertSame('wk-45', $found->branch);
    }

    public function testTaskForWorktreePathResolvesByPath(): void
    {
        $wt = $this->root.'/wt';
        mkdir($wt);
        $task = $this->makeTask();
        $task->worktreePath = $wt;
        $this->store->save($task);
        $found = $this->store->taskForWorktreePath($wt);
        $this->assertSame('wk-45', $found->branch);
    }

    public function testTaskForWorktreePathRejectsUnknownPath(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('not a PABLO task worktree');
        $this->store->taskForWorktreePath($this->root.'/nope');
    }
}
