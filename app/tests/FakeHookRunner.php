<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Hooks\HookRunnerInterface;

/**
 * Capturing test double for HookRunnerInterface: tests assert on the
 * recorded runs instead of a real process being spawned.
 */
final class FakeHookRunner implements HookRunnerInterface
{
    /** @var list<array{command: string, env: array<string, string>, cwd: ?string}> */
    public array $runs = [];

    public bool $throw = false;

    public function run(string $command, array $env, ?string $cwd): void
    {
        if ($this->throw) {
            throw new \RuntimeException('hook runner exploded');
        }
        $this->runs[] = ['command' => $command, 'env' => $env, 'cwd' => $cwd];
    }

    /** @return list<string> the PABLO_EVENT of each run */
    public function events(): array
    {
        return array_map(static fn (array $r): string => $r['env']['PABLO_EVENT'], $this->runs);
    }
}
