<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * Runs external CLIs (gh/acli/linear/orca). Abstraction over Symfony Process
 * so callers stay testable: tests inject a fake implementing this interface
 * instead of executing real binaries.
 */
interface ProcessRunnerInterface
{
    /**
     * Run one command and return stdout.
     *
     * @param list<string> $argv
     */
    public function run(array $argv, bool $check = true, ?float $timeout = null): string;

    /**
     * Run commands concurrently and return each stdout, in the same order.
     *
     * @param list<list<string>> $commandGroups
     *
     * @return list<string>
     */
    public function runParallel(array $commandGroups, bool $check = true, ?float $timeout = null): array;

    /**
     * Run one command tolerating failure.
     *
     * @param list<string> $argv
     */
    public function probe(array $argv, ?float $timeout = null): ProbeResult;
}
