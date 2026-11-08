<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

use Pablo\Support\ProcessRunnerInterface;

/**
 * One throwaway, tool-less question to a small model of the runtime (e.g.
 * the task summary for prompt-started tasks). Best-effort: null on any
 * failure, never throws.
 */
interface RuntimeOneShotInterface
{
    /** The model the one-shot call uses. */
    public function model(): string;

    /** The raw answer text to $instruction, asked from $cwd, or null. */
    public function ask(ProcessRunnerInterface $runner, string $cwd, string $instruction, float $timeout): ?string;
}
