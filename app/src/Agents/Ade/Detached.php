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
     * The programs that can put the process in a session of its own, in
     * order of preference: util-linux's setsid (Linux), else perl's
     * POSIX::setsid() (stock macOS has no setsid, but ships perl). Both
     * exec in place — a non-interactive shell never makes a background
     * process a group leader, so setsid never has to fork — which keeps
     * the printed pid that of the final command.
     */
    public const SESSION_HELPERS = ['setsid', 'perl'];

    /**
     * Picks the first helper on PATH at run time, into "$@"; with neither,
     * "$@" stays empty and the command still runs, just not in its own
     * session (system:doctor warns about it).
     */
    private const PICK_HELPER = 'if command -v setsid >/dev/null 2>&1; then set -- setsid; '
        .'elif command -v perl >/dev/null 2>&1; then set -- perl -MPOSIX=setsid -e \'setsid(); exec @ARGV; exit 127\'; '
        .'else set --; fi; ';

    /**
     * A detached background command that closes every inherited fd > 2
     * before exec, so a long-lived process never keeps the global dispatch
     * flock (or any other parent fd) alive after the run ends. bash closes
     * already-closed fds gracefully, unlike POSIX sh. Prints the pid.
     *
     * $inner is a shell snippet run as is: it should `exec` its last
     * command, so the printed pid is that command's. Never prefix it with a
     * blanket `exec`: `exec cd …` fails and ends the shell before anything runs.
     */
    public static function command(string $inner): string
    {
        $close = implode('; ', array_map(static fn (int $n) => "exec {$n}>&-", range(3, 255)));

        return self::PICK_HELPER.'"$@" bash -c '.escapeshellarg($close.'; '.$inner).' </dev/null >/dev/null 2>&1 & echo $!';
    }

    /**
     * Whether one of SESSION_HELPERS is on PATH, per $which (fn(string): ?string).
     */
    public static function canStartSession(callable $which): bool
    {
        foreach (self::SESSION_HELPERS as $helper) {
            if (null !== $which($helper)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Spawn $argv detached and return its pid.
     *
     * @param list<string> $argv
     */
    public static function spawn(array $argv): int
    {
        $inner = implode(' ', array_map(static fn ($a) => escapeshellarg((string) $a), $argv));

        return (int) trim((string) shell_exec(self::command('exec '.$inner)));
    }
}
