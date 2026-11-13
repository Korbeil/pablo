<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Ade;

use Pablo\Agents\Ade\Detached;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real generated command, with a PATH that only holds the tools it
 * is allowed to find: proves it still detaches without util-linux's setsid
 * (stock macOS), and that the printed pid is the final exec'd command's.
 */
final class DetachedTest extends TestCase
{
    private string $bin = '';

    /** @var list<int> */
    private array $pids = [];

    protected function setUp(): void
    {
        $this->bin = TempDir::create('detached');
    }

    protected function tearDown(): void
    {
        foreach ($this->pids as $pid) {
            posix_kill($pid, \SIGKILL);
        }
        foreach (scandir($this->bin) ?: [] as $f) {
            if ('.' !== $f && '..' !== $f) {
                unlink($this->bin.'/'.$f);
            }
        }
        rmdir($this->bin);
    }

    /** @param list<string> $tools */
    private function expose(array $tools): void
    {
        foreach ($tools as $tool) {
            $path = trim((string) shell_exec('command -v '.escapeshellarg($tool)));
            if ('' === $path) {
                $this->markTestSkipped("{$tool} is not installed");
            }
            symlink($path, $this->bin.'/'.$tool);
        }
    }

    /** Runs Detached::command() under /bin/sh with PATH = the sandbox only, returns the printed pid. */
    private function detach(string $inner): int
    {
        $proc = proc_open(['/bin/sh', '-c', Detached::command($inner)], [1 => ['pipe', 'w']], $pipes, null, ['PATH' => $this->bin]);
        $this->assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($proc);
        $pid = (int) trim($out);
        $this->assertGreaterThan(0, $pid);
        $this->pids[] = $pid;

        return $pid;
    }

    private function waitFor(callable $done): void
    {
        $deadline = microtime(true) + 5;
        while (!$done() && microtime(true) < $deadline) {
            usleep(20_000);
        }
    }

    public function testDetachesThroughPerlWhenSetsidIsMissing(): void
    {
        $this->expose(['bash', 'perl', 'sleep']);
        $pid = $this->detach('exec sleep 30');

        // The pid is the exec'd sleep itself, alive, leading its own session.
        $this->waitFor(static fn (): bool => posix_getsid($pid) === $pid);
        $this->assertTrue(posix_kill($pid, 0));
        $this->assertSame($pid, posix_getsid($pid));
        $this->assertNotSame(posix_getsid(0), posix_getsid($pid));
        $this->assertSame($pid, posix_getpgid($pid));
    }

    public function testDetachesThroughSetsidWhenPresent(): void
    {
        $this->expose(['bash', 'setsid', 'sleep']);
        $pid = $this->detach('exec sleep 30');

        $this->waitFor(static fn (): bool => posix_getsid($pid) === $pid);
        $this->assertTrue(posix_kill($pid, 0));
        $this->assertSame($pid, posix_getsid($pid));
    }

    public function testStillRunsWithoutAnySessionHelper(): void
    {
        $this->expose(['bash']);
        $marker = $this->bin.'/ran';
        $this->detach('echo RAN > '.escapeshellarg($marker));

        $this->waitFor(static fn (): bool => is_file($marker));
        $this->assertSame('RAN', trim((string) @file_get_contents($marker)));
    }

    public function testCanStartSessionNeedsOneHelper(): void
    {
        $this->assertTrue(Detached::canStartSession(static fn (string $b): ?string => 'perl' === $b ? '/usr/bin/perl' : null));
        $this->assertFalse(Detached::canStartSession(static fn (string $b): ?string => null));
    }
}
