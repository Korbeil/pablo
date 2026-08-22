<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Support\ProcessRunnerInterface;

/**
 * Test double for ProcessRunnerInterface. Mirrors the seams Proc::setRunner
 * used to provide: either script outputs as a FIFO list, or a resolver
 * callable receiving ($argv, $check, $timeout) and returning stdout.
 */
final class FakeProcessRunner implements ProcessRunnerInterface
{
    /** @var list<string> canned stdout per run(), popped FIFO */
    public array $outputs = [];

    /**
     * Fallback when the FIFO is empty: fn(list<string> $argv, bool $check, ?float $timeout): string.
     *
     * @var callable|null
     */
    public $onRun;

    /** @var list<array{0: list<string>, 1: bool, 2: ?float}> every run() invocation */
    public array $calls = [];

    /** @var array<int, string> canned stdout per runParallel() group, by index */
    public array $parallelOutputs = [];

    /** @var list<\Pablo\Support\ProbeResult> canned probe results, FIFO */
    public array $probes = [];

    /** @var list<list<string>> every probe() argv */
    public array $probeCalls = [];

    /**
     * Fallback probe resolver: fn(list<string> $argv): array{int, string}.
     *
     * @var callable|null
     */
    public $onProbe;

    public function run(array $argv, bool $check = true, ?float $timeout = null): string
    {
        $this->calls[] = [$argv, $check, $timeout];
        if ([] !== $this->outputs) {
            return (string) array_shift($this->outputs);
        }
        if (null !== $this->onRun) {
            return ($this->onRun)($argv, $check, $timeout);
        }

        throw new \LogicException('FakeProcessRunner: no scripted output for '.implode(' ', $argv));
    }

    public function runParallel(array $commandGroups, bool $check = true, ?float $timeout = null): array
    {
        $results = [];
        foreach ($commandGroups as $i => $argv) {
            $results[$i] = $this->run($argv, $check, $timeout);
        }

        return array_values($results);
    }

    public function probe(array $argv, ?float $timeout = null): \Pablo\Support\ProbeResult
    {
        $this->probeCalls[] = $argv;
        if ([] !== $this->probes) {
            return array_shift($this->probes);
        }
        if (null !== $this->onProbe) {
            return ($this->onProbe)($argv);
        }

        return new \Pablo\Support\ProbeResult(0, '');
    }
}
