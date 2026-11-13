<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

/**
 * Runs queued dispatcher jobs in child processes, at most $limit alive at a
 * time, and hands each one's outcome back as soon as it ends — so its output
 * is printed whole, never interleaved with another child's.
 *
 * Mechanism only; the dispatcher decides the policy through $onFinish:
 * returning true drops the limit to 1 for the rest of the queue (a rate-limit
 * signal: the queue then goes on exactly as the sequential dispatcher would).
 *
 * Bounded: a child running longer than $jobTimeoutS is killed and reported as
 * failed, and no new child starts once $startDeadlineS has passed since the
 * pool started (the jobs left are returned, for the next tick). A run never
 * holds the dispatch lock longer than $startDeadlineS + $jobTimeoutS.
 */
final class WorkerPool
{
    /** A poll of one project takes seconds; 10 minutes (two ticks) means it hangs. */
    public const JOB_TIMEOUT_S = 600.0;

    /** No child starts later than this after the pool started. */
    public const START_DEADLINE_S = 600.0;

    /** How often the running children are checked, in microseconds. */
    private const POLL_INTERVAL_US = 50_000;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var \Closure(): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(): float)|null $clock test seam: microtime(true)
     * @param (\Closure(): void)|null  $sleep test seam: wait POLL_INTERVAL_US between checks
     */
    public function __construct(
        private readonly JobWorkerSpawnerInterface $spawner,
        private readonly float $jobTimeoutS = self::JOB_TIMEOUT_S,
        private readonly float $startDeadlineS = self::START_DEADLINE_S,
        ?\Closure $clock = null,
        ?\Closure $sleep = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (): void { usleep(self::POLL_INTERVAL_US); };
    }

    /**
     * @param list<PoolJob>                 $jobs     started in this order (a job whose exclusive key is busy waits its turn)
     * @param callable(WorkerOutcome): bool $onFinish called once per started job, as it ends; true = one at a time from now on
     *
     * @return list<PoolJob> the jobs never started because the start deadline passed
     */
    public function run(array $jobs, int $limit, callable $onFinish): array
    {
        $limit = max(1, $limit);
        $queue = $jobs;
        /** @var array<int, array{job: PoolJob, worker: JobWorkerInterface, startedAt: float}> $running */
        $running = [];
        $poolStartedAt = ($this->clock)();

        while ([] !== $queue || [] !== $running) {
            $progress = false;

            foreach ($running as $i => $slot) {
                $now = ($this->clock)();
                $worker = $slot['worker'];
                $failure = null;
                if ($worker->isRunning()) {
                    if ($now - $slot['startedAt'] < $this->jobTimeoutS) {
                        continue;
                    }
                    $worker->stop();
                    $failure = \sprintf('%s worker timed out after %ds and was killed', $slot['job']->job, (int) $this->jobTimeoutS);
                }
                unset($running[$i]);
                $progress = true;
                if ($onFinish($this->outcome($slot['job'], $worker, $now - $slot['startedAt'], $failure))) {
                    $limit = 1;
                }
            }

            if (($this->clock)() - $poolStartedAt >= $this->startDeadlineS) {
                if ([] === $running) {
                    return $queue;
                }
            } else {
                while (\count($running) < $limit && null !== ($next = $this->nextStartable($queue, $running))) {
                    $job = $queue[$next];
                    unset($queue[$next]);
                    $queue = array_values($queue);
                    $progress = true;
                    $startedAt = ($this->clock)();
                    try {
                        $running[] = ['job' => $job, 'worker' => $this->spawner->start($job->project, $job->job), 'startedAt' => $startedAt];
                    } catch (\Throwable $e) {
                        if ($onFinish(new WorkerOutcome($job, '', '', null, 0.0, $e->getMessage()))) {
                            $limit = 1;
                        }
                    }
                }
            }

            if (!$progress && [] !== $running) {
                ($this->sleep)();
            }
        }

        return [];
    }

    /**
     * The first queued job whose exclusive key no running job holds.
     *
     * @param list<PoolJob>                        $queue
     * @param array<int, array{job: PoolJob, ...}> $running
     */
    private function nextStartable(array $queue, array $running): ?int
    {
        $busy = [];
        foreach ($running as $slot) {
            $busy[$slot['job']->exclusiveKey] = true;
        }
        foreach ($queue as $i => $job) {
            if (!isset($busy[$job->exclusiveKey])) {
                return $i;
            }
        }

        return null;
    }

    private function outcome(PoolJob $job, JobWorkerInterface $worker, float $durationS, ?string $failure): WorkerOutcome
    {
        [$output, $result] = JobResult::extract($worker->output());
        if (null === $failure && null === $result) {
            $code = $worker->exitCode();
            $lastError = self::lastLine($worker->errorOutput());
            $failure = \sprintf('%s worker exited with %s without a result', $job->job, null === $code ? 'a signal' : "code {$code}")
                .('' !== $lastError ? ": {$lastError}" : '');
        }

        return new WorkerOutcome($job, $output, $worker->errorOutput(), $result, $durationS, $failure);
    }

    private static function lastLine(string $text): string
    {
        $lines = preg_split('/\R/', trim($text)) ?: [];

        return trim((string) end($lines));
    }
}
