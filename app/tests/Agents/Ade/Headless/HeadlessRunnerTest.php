<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Ade\Headless;

use Pablo\Agents\Ade\Headless\HeadlessRunner;
use Pablo\Agents\Runtime\OpenCode\OpenCodeRuntime;
use Pablo\Domain\Time;
use PHPUnit\Framework\TestCase;

/**
 * Pid liveness works with ext-posix and, through `kill -0`, without it
 * (ext-posix is only suggested in composer.json).
 */
final class HeadlessRunnerTest extends TestCase
{
    public function testPidAlive(): void
    {
        $runner = new HeadlessRunner(new Time(), new OpenCodeRuntime());

        $this->assertTrue($runner->pidAlive((int) getmypid()));
        $this->assertFalse($runner->pidAlive(self::deadPid()));
    }

    public function testKillZeroFallback(): void
    {
        $killZero = new \ReflectionMethod(HeadlessRunner::class, 'killZero');

        $this->assertTrue($killZero->invoke(null, (int) getmypid()));
        $this->assertFalse($killZero->invoke(null, self::deadPid()));
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
