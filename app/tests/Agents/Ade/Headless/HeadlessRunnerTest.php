<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Ade\Headless;

use Pablo\Agents\Ade\Headless\HeadlessRunner;
use Pablo\Agents\Runtime\OpenCode\OpenCodeRuntime;
use Pablo\Domain\Time;
use Pablo\Support\Files;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Pid liveness goes through Support\Pid (see PidTest). wait() sees a run end
 * within its poll interval, and still gives up on a run that outlives the
 * timeout.
 */
final class HeadlessRunnerTest extends TestCase
{
    private string $tmp;
    private HeadlessRunner $runner;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('headless-runner');
        mkdir($this->tmp.'/agents', 0o777, true);
        putenv('PABLO_AGENTS_DIR='.$this->tmp.'/agents');
        $this->runner = new HeadlessRunner(new Time(), new class extends DelegatingRuntime {
            public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null, ?string $detachedIn = null): string
            {
                return 'true';
            }
        });
    }

    protected function tearDown(): void
    {
        putenv('PABLO_AGENTS_DIR');
        Files::remove($this->tmp);
    }

    public function testPidAlive(): void
    {
        $runner = new HeadlessRunner(new Time(), new OpenCodeRuntime());

        $this->assertTrue($runner->pidAlive((int) getmypid()));
        $this->assertFalse($runner->pidAlive(self::deadPid()));
    }

    public function testWaitReturnsSoonAfterTheRunExits(): void
    {
        $handle = $this->runner->launchCommand($this->tmp, 'short', 'sleep 0.3');

        $start = microtime(true);
        $this->runner->wait($handle, 30);
        $elapsed = microtime(true) - $start;

        $this->assertFalse($this->runner->pidAlive((int) substr($handle, \strlen(HeadlessRunner::HANDLE_PREFIX))));
        $this->assertLessThan(3.0, $elapsed, 'wait() must not oversleep a run that has exited');
    }

    public function testWaitGivesUpAtTheTimeout(): void
    {
        $handle = $this->runner->launchCommand($this->tmp, 'long', 'sleep 30');
        $pid = (int) substr($handle, \strlen(HeadlessRunner::HANDLE_PREFIX));
        try {
            $start = microtime(true);
            $this->runner->wait($handle, 1);
            $elapsed = microtime(true) - $start;

            $this->assertTrue($this->runner->pidAlive($pid));
            $this->assertGreaterThanOrEqual(1.0, $elapsed);
            $this->assertLessThan(3.0, $elapsed);
        } finally {
            // kill(1), not posix_kill(): ext-posix is optional.
            exec('kill -KILL '.$pid.' 2>/dev/null');
        }
    }

    /** The pid of a child that has exited and been reaped. */
    private static function deadPid(): int
    {
        $process = proc_open(['true'], [], $pipes);
        self::assertIsResource($process);
        $pid = proc_get_status($process)['pid'];
        proc_close($process);

        return $pid;
    }
}
