<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime;

use Pablo\Agents\Runtime\UsageWindow;
use Pablo\Domain\Time;
use PHPUnit\Framework\TestCase;

final class UsageWindowTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/pablo-usage-window-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testTheWindowStartsAGraceBeforeTheLaunch(): void
    {
        $this->assertSame(1_700_000_000 - UsageWindow::GRACE_S, UsageWindow::startS(new Time(), '2023-11-14T22:13:20Z'));
    }

    public function testCandidatesAreTheLogsInTheWindowOldestFirst(): void
    {
        $start = 1_700_000_000;
        $sessions = [
            'late' => ['startedS' => $start + 30, 'fingerprint' => 'b'],
            'early' => ['startedS' => $start + 10, 'fingerprint' => 'a'],
            'started-before' => ['startedS' => $start - 1, 'fingerprint' => 'c'],
            'unreadable' => null,
            'old-file' => ['startedS' => $start + 20, 'fingerprint' => 'd'],
        ];
        foreach (array_keys($sessions) as $name) {
            touch($this->dir.'/'.$name, 'old-file' === $name ? $start - 1 : $start + 60);
        }

        $candidates = UsageWindow::candidates(
            array_map(fn (string $name): string => $this->dir.'/'.$name, array_keys($sessions)),
            $start,
            static fn (string $path): ?array => $sessions[basename($path)],
        );

        $this->assertSame([$sessions['early'], $sessions['late']], $candidates);
    }

    public function testExactIsTheSessionWithTheFingerprint(): void
    {
        $candidates = [['startedS' => 1, 'fingerprint' => 'a'], ['startedS' => 2, 'fingerprint' => 'b']];

        $this->assertSame($candidates[1], UsageWindow::exact($candidates, 'b'));
        $this->assertNull(UsageWindow::exact($candidates, 'z'));
        $this->assertNull(UsageWindow::exact($candidates, null));
        $this->assertNull(UsageWindow::exact([['startedS' => 1, 'fingerprint' => '']], ''));
    }

    public function testTimestampsInMillisecondsZeroWhenMissingOrMalformed(): void
    {
        $time = new Time();

        $this->assertSame(1_700_000_000_250, UsageWindow::timestampMs($time, '2023-11-14T22:13:20.250Z'));
        $this->assertSame(0, UsageWindow::timestampMs($time, null));
        $this->assertSame(0, UsageWindow::timestampMs($time, ''));
        $this->assertSame(0, UsageWindow::timestampMs($time, 'not a date'));
    }
}
