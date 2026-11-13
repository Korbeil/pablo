<?php

declare(strict_types=1);

namespace Pablo\Tests\Analytics;

use Pablo\Analytics\AnalyticsEvent;
use Pablo\Analytics\AnalyticsReader;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class AnalyticsReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::create('reader');
        mkdir($this->root.'/proj', 0o777, true);
        mkdir($this->root.'/other', 0o777, true);
        putenv('PABLO_ANALYTICS_DIR='.$this->root);
        $this->write('proj', '2026-01', '2026-01-31T23:00:00+00:00', 'jan');
        $this->write('proj', '2026-03', '2026-03-02T10:00:00+00:00', 'mar');
        $this->write('other', '2026-02', '2026-02-15T10:00:00+00:00', 'feb');
        // Not named after a month: always read.
        $this->write('other', 'legacy', '2025-06-01T10:00:00+00:00', 'legacy');
        file_put_contents($this->root.'/proj/2026-03.jsonl', "not json\n", \FILE_APPEND);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_ANALYTICS_DIR');
        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function write(string $project, string $file, string $ts, string $branch): void
    {
        file_put_contents(
            "{$this->root}/{$project}/{$file}.jsonl",
            json_encode(['ts' => $ts, 'type' => 'task_opened', 'project' => $project, 'branch' => $branch])."\n",
            \FILE_APPEND,
        );
    }

    public function testReadsEverythingInTsOrderWithoutAWindow(): void
    {
        $this->assertSame(['legacy', 'jan', 'feb', 'mar'], array_column((new AnalyticsReader())->read(), 'branch'));
        $this->assertSame(['jan', 'mar'], array_column((new AnalyticsReader())->read('proj'), 'branch'));
    }

    public function testSkipsMonthFilesEndingBeforeTheWindow(): void
    {
        $reader = new AnalyticsReader();

        // Feb 20 → January's file is never opened; February's is (it overlaps).
        $this->assertSame(['legacy', 'feb', 'mar'], array_column($reader->read(null, new \DateTimeImmutable('2026-02-20T00:00:00+00:00')), 'branch'));
        // The month is taken in UTC: 2026-02-01T00:30+01:00 is still January.
        $this->assertSame(['legacy', 'jan', 'feb', 'mar'], array_column($reader->read(null, new \DateTimeImmutable('2026-02-01T00:30:00+01:00')), 'branch'));
        $this->assertSame(['mar'], array_column($reader->read('proj', new \DateTimeImmutable('2026-03-01T00:00:00+00:00')), 'branch'));
    }

    public function testEventsApplyTheWindowAndTheAllowedProjects(): void
    {
        $reader = new AnalyticsReader();
        $branches = static fn (array $events): array => array_map(static fn (AnalyticsEvent $e): string => $e->branch(), $events);

        $this->assertSame(['legacy', 'jan', 'feb', 'mar'], $branches($reader->events()));
        // February's file is read (it overlaps the window), its older event dropped.
        $this->assertSame(['mar'], $branches($reader->events(null, new \DateTimeImmutable('2026-02-20T00:00:00+00:00'))));
        $this->assertSame(['legacy', 'feb'], $branches($reader->events(null, null, ['other'])));
        $this->assertSame([], $reader->events('proj', null, ['other']));
    }

    public function testEventsKeepAnEventWithoutTimestampAndDropAMalformedOneFromAWindow(): void
    {
        file_put_contents($this->root.'/proj/2026-03.jsonl', json_encode(['type' => 'task_opened', 'project' => 'proj', 'branch' => 'no-ts'])."\n", \FILE_APPEND);
        file_put_contents($this->root.'/proj/2026-03.jsonl', json_encode(['ts' => 'garbage', 'type' => 'task_opened', 'project' => 'proj', 'branch' => 'bad-ts'])."\n", \FILE_APPEND);

        $branches = array_map(static fn (AnalyticsEvent $e): string => $e->branch(), (new AnalyticsReader())->events('proj', new \DateTimeImmutable('2026-03-01T00:00:00+00:00')));

        $this->assertContains('no-ts', $branches);
        $this->assertNotContains('bad-ts', $branches);
        $this->assertContains('mar', $branches);
    }
}
