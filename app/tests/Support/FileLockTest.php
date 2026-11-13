<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Support\FileLock;
use Pablo\Support\PabloError;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class FileLockTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('filelock');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    public function testAcquireCreatesTheFileAndHoldsTheLockUntilReleased(): void
    {
        $path = $this->tmp.'/nested/x.lock';
        $fd = FileLock::acquire($path, 0.0);
        $this->assertIsResource($fd);
        $this->assertFileExists($path);
        $this->assertFalse($this->lockable($path), 'held while acquired');

        FileLock::release($fd);
        $this->assertTrue($this->lockable($path), 'free once released');
        FileLock::release($fd); // a closed handle is ignored
        FileLock::release(null);
    }

    public function testNonBlockingAcquireOfAHeldLockReturnsNullAtOnce(): void
    {
        $held = FileLock::acquire($this->tmp.'/x.lock', 0.0);
        $waited = 0;
        $start = microtime(true);

        $this->assertNull(FileLock::acquire($this->tmp.'/x.lock', 0.0, onWait: static function () use (&$waited): void {
            ++$waited;
        }));
        $this->assertLessThan(0.5, microtime(true) - $start);
        $this->assertSame(0, $waited, 'no wait without a timeout');
        FileLock::release($held);
    }

    public function testAcquireGivesUpAfterTheTimeoutAndCallsOnWaitOnce(): void
    {
        $held = FileLock::acquire($this->tmp.'/x.lock', 0.0);
        $waited = 0;
        $start = microtime(true);

        $fd = FileLock::acquire($this->tmp.'/x.lock', 0.2, 20_000, static function () use (&$waited): void {
            ++$waited;
        });

        $this->assertNull($fd);
        $this->assertGreaterThanOrEqual(0.2, microtime(true) - $start);
        $this->assertSame(1, $waited);
        FileLock::release($held);
        $this->assertIsResource($again = FileLock::acquire($this->tmp.'/x.lock', 0.2));
        FileLock::release($again);
    }

    public function testAnUnopenableLockFileIsAPabloErrorNamingIt(): void
    {
        mkdir($this->tmp.'/dir.lock', 0o777, true); // a directory cannot be opened as a lock file

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage($this->tmp.'/dir.lock');
        FileLock::acquire($this->tmp.'/dir.lock', 0.0);
    }

    /** Whether another open file description could take the lock right now. */
    private function lockable(string $path): bool
    {
        $fd = fopen($path, 'c+');
        $this->assertNotFalse($fd);
        $free = flock($fd, \LOCK_EX | \LOCK_NB);
        fclose($fd);

        return $free;
    }
}
