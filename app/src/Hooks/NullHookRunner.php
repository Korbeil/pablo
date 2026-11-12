<?php

declare(strict_types=1);

namespace Pablo\Hooks;

/**
 * No-op runner: the default for hand-rolled instances, so nothing is ever
 * spawned outside a DI-wired process.
 */
final class NullHookRunner implements HookRunnerInterface
{
    public function run(string $command, array $env, ?string $cwd): void
    {
    }
}
