<?php

declare(strict_types=1);

namespace Pablo\Hooks;

/**
 * Runs the user's hook command. Behind an interface so tests record the
 * calls instead of spawning processes (see tests/FakeHookRunner.php).
 */
interface HookRunnerInterface
{
    /**
     * Start $command (a shell snippet, run by bash) detached with $env added
     * to the environment, from $cwd when it is a directory. Must return at
     * once: the caller never waits for, nor learns the outcome of, the hook.
     *
     * @param array<string, string> $env
     */
    public function run(string $command, array $env, ?string $cwd): void;
}
