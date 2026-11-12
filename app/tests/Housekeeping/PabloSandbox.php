<?php

declare(strict_types=1);

namespace Pablo\Tests\Housekeeping;

/**
 * A throwaway ~/.pablo for prune tests: HOME and the PABLO_* overrides the
 * pruner reads point into a temp dir, never at the developer's real one.
 */
trait PabloSandbox
{
    protected string $home = '';

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function enterSandbox(): string
    {
        $this->home = sys_get_temp_dir().'/pablo-prune-'.uniqid();
        mkdir($this->home.'/.pablo/agents/logs', 0o777, true);
        mkdir($this->home.'/.pablo/logs', 0o777, true);
        foreach (['HOME', 'PABLO_AGENTS_DIR', 'PABLO_LOGS_DIR'] as $var) {
            $this->savedEnv[$var] = getenv($var);
        }
        putenv('HOME='.$this->home);
        putenv('PABLO_AGENTS_DIR='.$this->home.'/.pablo/agents');
        putenv('PABLO_LOGS_DIR='.$this->home.'/.pablo/logs');

        return $this->home.'/.pablo';
    }

    protected function leaveSandbox(): void
    {
        foreach ($this->savedEnv as $var => $value) {
            putenv(false === $value ? $var : "{$var}={$value}");
        }
        $this->savedEnv = [];
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
