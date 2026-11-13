<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Dispatch\JobResult;
use Pablo\Dispatch\JobWorkerInterface;
use Pablo\Dispatch\JobWorkerSpawnerInterface;

/**
 * Test double for JobWorkerSpawnerInterface: no child process, ever. Each
 * start() asks $script (fn(string $project, string $job): FakeJobWorkerScript)
 * how the "child" behaves; without one, start() fails loudly so a test can
 * never reach a real `pablo internal:dispatch-job`.
 *
 * Tracks how many workers are alive at once ($maxAlive) and the start order.
 */
final class FakeJobWorkers implements JobWorkerSpawnerInterface
{
    /** @var (\Closure(string, string): FakeJobWorkerScript)|null */
    public ?\Closure $script = null;

    /** @var list<string> "<project>:<job>", in start order */
    public array $started = [];

    /** @var list<string> "<project>:<job>", killed by stop() */
    public array $stopped = [];

    public int $alive = 0;
    public int $maxAlive = 0;

    /** @var list<array{0: string, 1: int}> [event, workers alive right after it], e.g. ['start a', 2] */
    public array $timeline = [];

    public function start(string $project, string $job): JobWorkerInterface
    {
        if (null === $this->script) {
            throw new \LogicException("FakeJobWorkers: no script for {$project} {$job}");
        }
        $script = ($this->script)($project, $job);
        if (null !== $script->startError) {
            throw new \Pablo\Support\PabloError($script->startError);
        }
        $this->started[] = "{$project}:{$job}";
        ++$this->alive;
        $this->maxAlive = max($this->maxAlive, $this->alive);
        $this->timeline[] = ["start {$project}", $this->alive];

        return new FakeJobWorker($this, "{$project}:{$job}", $script);
    }

    /** @internal called by FakeJobWorker */
    public function ended(string $id, bool $killed): void
    {
        --$this->alive;
        if ($killed) {
            $this->stopped[] = $id;
        }
        $this->timeline[] = ['end '.explode(':', $id)[0], $this->alive];
    }

    /** A child that prints $output then a successful result after $ticks checks. */
    public static function ok(string $output = '', int $ticks = 1, int $checkFailures = 0): FakeJobWorkerScript
    {
        return new FakeJobWorkerScript($ticks, $output.(new JobResult(null, $checkFailures))->line(), '', 0);
    }

    /** A child whose job throws $message (as internal:dispatch-job reports it). */
    public static function failing(string $message, string $output = '', int $ticks = 1): FakeJobWorkerScript
    {
        return new FakeJobWorkerScript($ticks, $output.(new JobResult($message))->line(), "Pablo\\Support\\PabloError: {$message}\n#0 trace\n", 1);
    }
}
