<?php

declare(strict_types=1);

namespace Pablo\Hooks;

use Pablo\Agents\Ade\Detached;
use Pablo\Support\Files;
use Pablo\Support\PabloPaths;

/**
 * Runs the hook as a detached background process (own session, every inherited
 * fd > 2 closed, see Detached): PABLO never waits for it, and a slow or
 * failing command can neither block nor fail what fired it. Its output is
 * appended to ~/.pablo/logs/hooks.log (PABLO_LOGS_DIR).
 */
final class DetachedHookRunner implements HookRunnerInterface
{
    public function __construct(private readonly ?string $logFile = null)
    {
    }

    public function run(string $command, array $env, ?string $cwd): void
    {
        // Resolved per call, never at container compile time (HOME may differ).
        $log = $this->logFile ?? PabloPaths::logsDir().'/hooks.log';
        // best-effort: a missing hooks log must never stop the hook.
        Files::tryEnsureDir(\dirname($log));
        $assignments = [];
        foreach ($env as $name => $value) {
            $assignments[] = escapeshellarg($name.'='.$value);
        }
        $inner = '';
        if (null !== $cwd && is_dir($cwd)) {
            $inner .= 'cd '.escapeshellarg($cwd).' || exit 1; ';
        }
        $inner .= 'exec env '.implode(' ', $assignments).' bash -c '.escapeshellarg($command)
            .' >>'.escapeshellarg($log).' 2>&1';

        shell_exec(Detached::command($inner));
    }
}
