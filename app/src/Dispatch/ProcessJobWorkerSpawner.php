<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Starts `pablo internal:dispatch-job --project <p> --job <j>` as a child
 * process of the *running* PABLO — the same binary or checkout, so parent and
 * child always run the same version (not PABLO_SHIM / ~/.local/bin/pablo,
 * which a `pablo update` could swap under a running dispatch).
 *
 * A child process, never pcntl_fork(): a forked child of a process that has
 * the phar open shares its file handle and corrupts its reads, and pcntl is
 * optional anyway. proc_open execs a fresh process that opens the binary on
 * its own. The child inherits the environment (PABLO_*, HOME, PATH) and the
 * dispatch lock's fd, so the lock stays held for as long as a child lives.
 */
final class ProcessJobWorkerSpawner implements JobWorkerSpawnerInterface
{
    public function start(string $project, string $job): JobWorkerInterface
    {
        $process = new Process([...self::selfArgv(), 'internal:dispatch-job', '--project='.$project, '--job='.$job, '--no-interaction']);
        $process->setTimeout(null); // WorkerPool enforces the timeout and kills the child
        try {
            $process->start();
        } catch (ProcessRuntimeException $e) {
            throw new PabloError("cannot start the {$job} worker for {$project}: {$e->getMessage()}");
        }

        return new ProcessJobWorker($process);
    }

    /**
     * How to run this very PABLO again: the static binary itself (micro SAPI),
     * a phar through the PHP running it, or the checkout's bin/pablo.
     *
     * @return list<string>
     */
    public static function selfArgv(): array
    {
        if (PabloPaths::isPhar()) {
            return 'micro' === \PHP_SAPI ? [PabloPaths::pharPath()] : [\PHP_BINARY, PabloPaths::pharPath()];
        }

        return [\PHP_BINARY, PabloPaths::resourcesDir().'/app/bin/pablo'];
    }
}
