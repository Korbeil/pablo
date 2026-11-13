<?php

declare(strict_types=1);

namespace Pablo\Tests\Housekeeping;

use Pablo\Tests\SandboxedEnv;
use Pablo\Tests\TempDir;

/**
 * A throwaway ~/.pablo for prune tests: HOME and the PABLO_* overrides the
 * pruner reads point into a temp dir, never at the developer's real one.
 */
trait PabloSandbox
{
    use SandboxedEnv;

    protected string $home = '';

    protected function enterSandbox(): string
    {
        $this->home = TempDir::create('prune');
        mkdir($this->home.'/.pablo/agents/logs', 0o777, true);
        mkdir($this->home.'/.pablo/logs', 0o777, true);
        $this->setEnv('HOME', $this->home);
        $this->setEnv('PABLO_AGENTS_DIR', $this->home.'/.pablo/agents');
        $this->setEnv('PABLO_LOGS_DIR', $this->home.'/.pablo/logs');

        return $this->home.'/.pablo';
    }

    protected function leaveSandbox(): void
    {
        $this->restoreEnv();
        if ('' !== $this->home) {
            \Pablo\Support\Files::remove($this->home);
        }
    }

    /** A file holding $content, last modified $daysAgo days ago. */
    protected function file(string $path, int $daysAgo = 0, string $content = 'x'): string
    {
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }
        file_put_contents($path, $content);
        touch($path, time() - $daysAgo * 86400);

        return $path;
    }
}
