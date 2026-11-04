<?php

declare(strict_types=1);

namespace Pablo\Tests\Install;

use Symfony\Component\Process\Process;

/**
 * A throwaway HOME and a PATH holding only the system tools the shell
 * scripts need, so a script under test sees exactly the tools a test
 * installs (a developer machine may well have opencode, claude or orca).
 *
 * Every tool is a wrapper script, not a symlink: a multi-call binary or a
 * git that finds its helpers from argv[0] behaves the same either way.
 */
trait ShellSandbox
{
    private const SYSTEM_TOOLS = [
        'awk', 'basename', 'bash', 'cat', 'chmod', 'cp', 'cut', 'date', 'dirname', 'env', 'find', 'git', 'grep',
        'head', 'id', 'ln', 'ls', 'mkdir', 'mktemp', 'mv', 'perl', 'readlink', 'rm', 'sed', 'sort', 'tail', 'tee',
        'touch', 'tr', 'uname', 'wc',
    ];

    private string $sandbox;

    protected function createSandbox(): void
    {
        $this->sandbox = sys_get_temp_dir().'/pablo-sandbox-'.bin2hex(random_bytes(4));
        mkdir($this->sandbox.'/home', 0o777, true);
        mkdir($this->sandbox.'/path', 0o777, true);

        foreach (self::SYSTEM_TOOLS as $tool) {
            if (null !== ($real = $this->findOnRealPath($tool))) {
                $this->writeTool($tool, 'exec '.escapeshellarg($real).' "$@"');
            }
        }
        $this->writeTool('php', 'exec '.escapeshellarg(\PHP_BINARY).' "$@"');
    }

    protected function removeSandbox(): void
    {
        if (isset($this->sandbox) && str_contains($this->sandbox, 'pablo-sandbox-')) {
            exec('rm -rf '.escapeshellarg($this->sandbox));
        }
    }

    /** A command that exists on the sandbox PATH and does $body (default: nothing, successfully). */
    protected function fakeTool(string $name, string $body = 'exit 0'): void
    {
        $this->writeTool($name, $body);
    }

    protected function realTool(string $name): string
    {
        return $this->findOnRealPath($name) ?? self::fail("{$name} is not installed on this machine");
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    protected function runInSandbox(array $command, array $env = []): Process
    {
        $process = new Process($command, $this->sandbox, array_merge([
            'PATH' => $this->sandbox.'/path',
            'HOME' => $this->sandbox.'/home',
            'SHELL' => '/bin/bash',
            'LC_ALL' => 'C',
            'NO_COLOR' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_AUTHOR_NAME' => 'Test',
            'GIT_AUTHOR_EMAIL' => 'test@example.com',
            'GIT_COMMITTER_NAME' => 'Test',
            'GIT_COMMITTER_EMAIL' => 'test@example.com',
            // Whatever the developer exported must not leak into the script.
            'PABLO_AGENT_RUNTIME' => false,
            'PABLO_AGENT_BACKEND' => false,
            'PABLO_CONFIG' => false,
            'PABLO_PROJECTS_DIR' => false,
            'PABLO_INSTALL_DIR' => false,
            'PABLO_REPO_URL' => false,
            'PABLO_INSTALLER_TTY' => false,
            'CLAUDE_CONFIG_DIR' => false,
            'COPILOT_HOME' => false,
            'ZDOTDIR' => false,
        ], $env));
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    protected function outputOf(Process $process): string
    {
        return $process->getOutput().$process->getErrorOutput();
    }

    /** @param list<string> $arguments */
    protected function git(string $directory, array $arguments): void
    {
        $process = $this->runInSandbox([$this->sandbox.'/path/git', '-C', $directory, ...$arguments]);
        self::assertSame(0, $process->getExitCode(), 'git '.implode(' ', $arguments).': '.$this->outputOf($process));
    }

    private function writeTool(string $name, string $body): void
    {
        $path = $this->sandbox.'/path/'.$name;
        file_put_contents($path, "#!/bin/bash\n{$body}\n");
        chmod($path, 0o755);
    }

    private function findOnRealPath(string $tool): ?string
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = $directory.'/'.$tool;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
