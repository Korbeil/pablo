<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Support\Pid;
use PHPUnit\Framework\TestCase;

/**
 * Pid liveness works with ext-posix and, through `kill -0`, without it
 * (ext-posix is only suggested in composer.json).
 */
final class PidTest extends TestCase
{
    public function testIsAlive(): void
    {
        $this->assertTrue(Pid::isAlive((int) getmypid()));
        $this->assertFalse(Pid::isAlive(self::deadPid()));
    }

    public function testKillZeroFallback(): void
    {
        $this->assertTrue(Pid::killZero((int) getmypid()));
        $this->assertFalse(Pid::killZero(self::deadPid()));
    }

    public function testProcessGroupPidsAreNeverAlive(): void
    {
        $this->assertFalse(Pid::isAlive(0));
        $this->assertFalse(Pid::isAlive(-1));
        $this->assertFalse(Pid::killZero(0));
        $this->assertFalse(Pid::killZero(-1));
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
