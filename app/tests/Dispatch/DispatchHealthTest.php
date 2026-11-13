<?php

declare(strict_types=1);

namespace Pablo\Tests\Dispatch;

use Pablo\Dispatch\DispatchHealth;
use Pablo\Dispatch\JobHealth;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class DispatchHealthTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('health');
        putenv('PABLO_STAMPS_DIR='.$this->tmp.'/stamps');
    }

    protected function tearDown(): void
    {
        putenv('PABLO_STAMPS_DIR');
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    private function failing(int $failures): JobHealth
    {
        return new JobHealth('a', 'sync', lastAttemptAt: '2027-01-15T08:00:00+00:00', consecutiveFailures: $failures);
    }

    public function testNoBackOffBeforeTheThreshold(): void
    {
        $health = new DispatchHealth();
        $this->assertNull($health->retryAt(null, 720));
        $this->assertNull($health->retryAt($this->failing(1), 720));
    }

    public function testBackOffDoublesAndIsCappedByTheInterval(): void
    {
        $health = new DispatchHealth();
        $base = 1_800_000_000.0;
        $this->assertSame($base + 10 * 60, $health->retryAt($this->failing(2), 720));
        $this->assertSame($base + 20 * 60, $health->retryAt($this->failing(3), 720));
        $this->assertSame($base + 640 * 60, $health->retryAt($this->failing(8), 720));
        $this->assertSame($base + 720 * 60, $health->retryAt($this->failing(9), 720));
        $this->assertSame($base + 720 * 60, $health->retryAt($this->failing(500), 720));
        // A short poll interval caps it early.
        $this->assertSame($base + 10 * 60, $health->retryAt($this->failing(5), 10));
    }

    public function testFailureStreakKeepsItsStartAndLastSuccess(): void
    {
        $health = new DispatchHealth();
        $health->recordSuccess('a', 'poll', 1_800_000_000.0);
        $health->recordFailure('a', 'poll', 1_800_000_300.0, 'one');
        $health->recordFailure('a', 'poll', 1_800_000_600.0, 'two');

        $poll = $health->read('a', 'poll');
        $this->assertNotNull($poll);
        $this->assertSame(2, $poll->consecutiveFailures);
        $this->assertSame('two', $poll->lastError);
        $this->assertSame('2027-01-15T08:05:00+00:00', $poll->failingSince);
        $this->assertSame('2027-01-15T08:10:00+00:00', $poll->lastAttemptAt);
        $this->assertSame('2027-01-15T08:00:00+00:00', $poll->lastSuccessAt);
    }

    public function testErrorsAreFlattenedAndTruncated(): void
    {
        $long = str_repeat('x', 1000);
        $this->assertSame(DispatchHealth::ERROR_MAX_CHARS, mb_strlen(DispatchHealth::truncate($long)));
        $this->assertSame('a b c', DispatchHealth::truncate("  a\n b\t\tc "));
    }

    public function testUnreadableFilesReadAsNoRecord(): void
    {
        mkdir($this->tmp.'/stamps', 0o777, true);
        file_put_contents(DispatchHealth::jobPath('a', 'sync'), '{not json');
        file_put_contents(DispatchHealth::dispatchPath(), '[]');

        $health = new DispatchHealth();
        $this->assertNull($health->read('a', 'sync'));
        $this->assertNull($health->lastDispatch());
    }

    public function testRecordingNeverThrows(): void
    {
        // The stamps "directory" is a file: every write fails, silently.
        file_put_contents($this->tmp.'/stamps', '');

        $health = new DispatchHealth();
        $health->recordFailure('a', 'sync', 1_800_000_000.0, 'boom');
        $health->recordSuccess('a', 'sync', 1_800_000_000.0);
        $health->startDispatch(1_800_000_000.0);
        $health->finishDispatch(1_800_000_000.0, 1_800_000_001.0, DispatchHealth::OUTCOME_OK);
        $this->assertNull($health->read('a', 'sync'));
    }
}
