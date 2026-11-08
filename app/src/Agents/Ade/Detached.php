<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade;

/**
 * Detached background processes, shared by every ADE launcher (the
 * self-reinvocation subprocesses) and the headless runner (the agent runs).
 */
final class Detached
{
    /**
     * A detached background command that closes every inherited fd > 2
     * before exec, so a long-lived process never keeps the global dispatch
     * flock (or any other parent fd) alive after the run ends. bash closes
     * already-closed fds gracefully, unlike POSIX sh. Prints the pid.
     */
    public static function command(string $inner): string
    {
        $close = implode('; ', array_map(static fn (int $n) => "exec {$n}>&-", range(3, 255)));

        return 'setsid bash -c '.escapeshellarg($close.'; exec '.$inner).' </dev/null >/dev/null 2>&1 & echo $!';
    }

    /**
     * Spawn $argv detached and return its pid.
     *
     * @param list<string> $argv
     */
    public static function spawn(array $argv): int
    {
        $inner = implode(' ', array_map(static fn ($a) => escapeshellarg((string) $a), $argv));

        return (int) trim((string) shell_exec(self::command($inner)));
    }
}
