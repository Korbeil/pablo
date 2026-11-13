<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime\OpenCode;

use Pablo\Agents\Runtime\OpenCode\OpenCodeRuntime;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The command an Orca terminal runs to show an agent in the OpenCode TUI,
 * run for real against a fake `opencode` on PATH (never the real CLI).
 */
final class OpenCodeInteractiveTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('oc-tui');
        mkdir($this->tmp.'/bin', 0o777, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->tmp]))->run();
    }

    /**
     * A fake opencode: `run -s <id> … ` logs its argv and creates the session
     * after a moment (or fails at once when $runFails), `session export <id>`
     * succeeds once it exists, and the TUI (`-s <id>`) logs what it opened.
     */
    private function fakeOpenCode(bool $runFails = false): void
    {
        $dir = $this->tmp;
        $fail = $runFails ? 'echo "Error: Model unavailable: x/y" >&2; exit 1' : 'sleep 1; touch "'.$dir.'/session-$3"';
        file_put_contents($dir.'/bin/opencode', <<<SH
            #!/bin/sh
            case "\$1" in
            run) printf '%s\\n' "\$@" > "{$dir}/run-argv"; {$fail} ;;
            session) [ -f "{$dir}/session-\$3" ] ;;
            -s) echo "TUI \$2" > "{$dir}/tui" ;;
            esac
            SH);
        chmod($dir.'/bin/opencode', 0o755);
    }

    private function runScript(string $command): Process
    {
        $process = Process::fromShellCommandline($command, $this->tmp, ['PATH' => $this->tmp.'/bin:'.getenv('PATH')], null, 30);
        $process->run();

        return $process;
    }

    public function testOpensTheTuiOnTheAgentsSession(): void
    {
        $this->fakeOpenCode();
        $process = $this->runScript((new OpenCodeRuntime())->interactiveCommand('task-analyst', "it's \$HOME's CI", 'opencode-go/glm-5.3-flash'));

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $argv = explode("\n", trim((string) file_get_contents($this->tmp.'/run-argv')));
        $this->assertSame(['run', '-s'], \array_slice($argv, 0, 2));
        $this->assertStringStartsWith('ses_pablo', $argv[2]);
        $this->assertSame(['--model', 'opencode-go/glm-5.3-flash', '--agent', 'task-analyst', "it's \$HOME's CI"], \array_slice($argv, 3));
        $this->assertSame('TUI '.$argv[2], trim((string) file_get_contents($this->tmp.'/tui')));
    }

    public function testAFailedRunLeavesItsErrorAndNoTui(): void
    {
        $this->fakeOpenCode(runFails: true);
        $process = $this->runScript((new OpenCodeRuntime())->interactiveCommand('task-analyst', 'p', null));

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Model unavailable', $process->getOutput());
        $this->assertFileDoesNotExist($this->tmp.'/tui');
    }
}
