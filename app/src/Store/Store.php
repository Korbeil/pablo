<?php

declare(strict_types=1);

namespace Pablo\Store;

use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Support\PabloError;
use Symfony\Component\Process\Process;

/**
 * Central task-state store, owned by PABLO's installation.
 *
 * Lives under ~/.pablo/state/<project>/<branch>.json (never inside a project
 * repo). Each task has a sibling .lock file used with flock(2) so the sync
 * job, the state poller, and interactive commands never interleave on the
 * same task; the kernel releases the lock if the holder crashes.
 */
final class Store
{
    public const LOCK_TIMEOUT_S = 30.0;

    private readonly string $root;

    /**
     * $root is resolved here rather than by the DI container: the compiled
     * container is cached under app/var/cache/<env>, so a compile-time value
     * would freeze PABLO_STATE_DIR (and HOME) as they were on the process that
     * first warmed the cache.
     */
    public function __construct(?string $root = null)
    {
        $this->root = $root ?? self::defaultRoot();
    }

    public static function defaultRoot(): string
    {
        $override = getenv('PABLO_STATE_DIR');
        if (false !== $override && '' !== $override) {
            return $override;
        }
        $home = getenv('HOME');

        return ($home ?: '~').'/.pablo/state';
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $project, string $branch): string
    {
        return rtrim($this->root, '/').'/'.$project.'/'.$branch.'.json';
    }

    public function lockPath(string $project, string $branch): string
    {
        return rtrim($this->root, '/').'/'.$project.'/'.$branch.'.lock';
    }

    public function get(string $project, string $branch): ?Task
    {
        $path = $this->path($project, $branch);
        if (!is_file($path)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true);

        return Task::fromJson($data);
    }

    public function save(Task $task): void
    {
        $task->updatedAt = Time::utcnow();
        $path = $this->path($task->project, $task->branch);
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        $tmp = $path.'.tmp';
        file_put_contents($tmp, $this->encode($task->toJson()));
        rename($tmp, $path);
    }

    public function delete(string $project, string $branch): void
    {
        if (is_file($this->path($project, $branch))) {
            unlink($this->path($project, $branch));
        }
        if (is_file($this->lockPath($project, $branch))) {
            unlink($this->lockPath($project, $branch));
        }
    }

    /** @return array<int, Task> */
    public function allTasks(?string $project = null): array
    {
        if (!is_dir($this->root)) {
            return [];
        }
        $tasks = [];
        foreach (glob(rtrim($this->root, '/').'/*/*.json') ?: [] as $path) {
            if (null !== $project && basename(\dirname($path)) !== $project) {
                continue;
            }
            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents($path), true);
            $tasks[] = Task::fromJson($data);
        }

        return $tasks;
    }

    /**
     * Resolve the task owning $cwd (a PABLO-created worktree).
     */
    public function taskForCwd(string $cwd): Task
    {
        $process = new Process(['git', '-C', $cwd, 'rev-parse', '--show-toplevel']);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new PabloError("{$cwd} is not a git worktree, so not a PABLO task worktree");
        }
        $topPath = (string) realpath(trim($process->getOutput()));

        return $this->taskForWorktreePath($topPath);
    }

    /**
     * Resolve the task whose worktree resolves to $worktreePath. Unlike
     * taskForCwd(), it matches the path directly (after realpath) instead of
     * requiring the current working directory to be the worktree — this lets
     * commands operate on a task from anywhere (e.g. an agent that isn't
     * running inside the worktree).
     */
    public function taskForWorktreePath(string $worktreePath): Task
    {
        $resolved = (string) realpath($worktreePath);
        foreach ($this->allTasks() as $task) {
            if ((string) realpath($task->worktreePath) === $resolved) {
                return $task;
            }
        }
        throw new PabloError("{$resolved} is not a PABLO task worktree (no task record matches it)");
    }

    /**
     * Exclusive per-task lock. Throws TaskLockedException if held past the
     * deadline (the distinct exception replaces Python's fragile "locked"
     * substring test). Writes holder metadata into the lock file.
     */
    public static function taskLock(self $store, string $project, string $branch, float $timeoutS = self::LOCK_TIMEOUT_S): TaskLock
    {
        $path = $store->lockPath($project, $branch);
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        $fd = fopen($path, 'c+');
        if (false === $fd) {
            throw new PabloError("cannot open lock file: {$path}");
        }
        $deadline = microtime(true) + $timeoutS;
        try {
            while (true) {
                if (flock($fd, \LOCK_EX | \LOCK_NB)) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $holder = '';
                    if (is_file($path)) {
                        $holder = trim((string) file_get_contents($path));
                    }
                    throw new TaskLockedException("task {$project}/{$branch} is locked".('' !== $holder ? " by {$holder}" : ''));
                }
                usleep(200_000);
            }
            ftruncate($fd, 0);
            rewind($fd);
            fwrite($fd, json_encode([
                'pid' => getmypid(),
                'argv' => $_SERVER['argv'] ?? [],
                'acquired_at' => Time::utcnow(),
            ], \JSON_THROW_ON_ERROR));

            return new TaskLock($fd);
        } catch (\Throwable $e) {
            fclose($fd);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return json_encode(
            $data,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        )."\n";
    }
}
