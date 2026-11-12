<?php

declare(strict_types=1);

namespace Pablo\Tests\Housekeeping;

use Pablo\Housekeeping\PruneItem;
use Pablo\Housekeeping\Pruner;
use Pablo\Support\PabloError;
use PHPUnit\Framework\TestCase;

final class PrunerTest extends TestCase
{
    use PabloSandbox;

    /** The fixtures below are aged around a 30-day window. */
    private const DAYS = 30;

    private string $pablo;

    protected function setUp(): void
    {
        $this->pablo = $this->enterSandbox();
    }

    protected function tearDown(): void
    {
        $this->leaveSandbox();
    }

    /**
     * @param list<PruneItem> $items
     *
     * @return list<string>
     */
    private static function paths(array $items): array
    {
        return array_map(static fn (PruneItem $i): string => $i->path, $items);
    }

    public function testRemovesAgentLogsOlderThanTheCutoffOnly(): void
    {
        $old = $this->file($this->pablo.'/agents/logs/1000-task-analyst.log', 31, 'old');
        $young = $this->file($this->pablo.'/agents/logs/2000-task-analyst.log', 29, 'young');

        $pruner = new Pruner(phar: false);
        $items = $pruner->plan(self::DAYS);

        $this->assertSame([$old], self::paths($items));
        $this->assertSame(PruneItem::AGENT_LOG, $items[0]->kind);
        $this->assertSame(3, $items[0]->bytes);
        $this->assertFileExists($old, 'plan() touches nothing');

        $pruner->apply($items);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($young);

        $this->assertSame([], self::paths((new Pruner(phar: false))->plan(60)));
    }

    public function testKeepsAtLeastADay(): void
    {
        $this->file($this->pablo.'/agents/logs/1000-task-analyst.log', 20);

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('cannot keep fewer than 1 day of logs, got 0');
        (new Pruner(phar: false))->plan(0);
    }

    public function testKeepsTenDaysByDefault(): void
    {
        $young = $this->file($this->pablo.'/agents/logs/1000-task-analyst.log', 9);
        $old = $this->file($this->pablo.'/agents/logs/2000-task-analyst.log', 11);

        $this->assertSame([$old], self::paths((new Pruner(phar: false))->plan()));
        $this->assertFileExists($young);
    }

    public function testNeverTouchesTheLogOfARunningAgent(): void
    {
        $live = $this->file($this->pablo.'/agents/logs/1000-task-analyst.log', 40);
        $dead = $this->file($this->pablo.'/agents/logs/1001-task-analyst.log', 40);
        // This PHPUnit process stands for the live run.
        file_put_contents($this->pablo.'/agents/'.getmypid().'.json', json_encode(['pid' => getmypid(), 'agent' => 'task-analyst', 'log' => $live]));

        $this->assertSame([$dead], self::paths((new Pruner(phar: false))->plan(30)));
    }

    public function testALegacyPidfileProtectsTheLogsOfItsLabelSinceItStarted(): void
    {
        $started = time() - 40 * 86400;
        $before = $this->file($this->pablo.'/agents/logs/'.($started - 3600).'-startup.log', 41);
        $its = $this->file($this->pablo.'/agents/logs/'.$started.'-startup.log', 40);
        $other = $this->file($this->pablo.'/agents/logs/'.$started.'-task-analyst.log', 40);
        file_put_contents($this->pablo.'/agents/'.getmypid().'.json', json_encode([
            'pid' => getmypid(),
            'agent' => 'startup',
            'started_at' => gmdate('Y-m-d\TH:i:s\Z', $started),
        ]));

        $this->assertSame([$before, $other], self::paths((new Pruner(phar: false))->plan(30)));
        $this->assertFileExists($its);
    }

    public function testRotatesServiceLogsIntoADailyArchiveInPlace(): void
    {
        $log = $this->file($this->pablo.'/logs/dispatch.log', 0, "line 1\nline 2\n");
        $empty = $this->file($this->pablo.'/logs/web.log', 0, '');
        $sync = $this->file($this->pablo.'/logs/rebase-last-acme.json', 0, '{}');
        $inode = fileinode($log);
        // launchd's handle: kept open in append mode across the rotation.
        $launchd = fopen($log, 'a');
        \assert(false !== $launchd);

        $now = (float) time();
        $archive = $this->pablo.'/logs/archive/dispatch.log.'.date('Y-m-d', (int) $now);
        $pruner = new Pruner(phar: false);
        $items = $pruner->plan(self::DAYS, $now);
        $this->assertSame([$log], self::paths($items), 'only non-empty service logs');
        $this->assertSame($archive, $items[0]->archive);
        $this->assertSame(0, $items[0]->freedBytes(), 'a rotation frees nothing');

        $pruner->apply($items);
        clearstatcache();
        $this->assertSame("line 1\nline 2\n", file_get_contents($archive));
        $this->assertSame('', file_get_contents($log));
        $this->assertSame($inode, fileinode($log), 'truncated in place, never replaced');

        fwrite($launchd, "line 3\n");
        fclose($launchd);
        $this->assertSame("line 3\n", file_get_contents($log), 'launchd keeps writing to the same file');

        // A second rotation the same day appends to the same archive.
        $pruner->apply($pruner->plan(self::DAYS, $now));
        $this->assertSame("line 1\nline 2\nline 3\n", file_get_contents($archive));
        $this->assertSame('', file_get_contents($empty));
        $this->assertSame('{}', file_get_contents($sync), 'only the service logs');
    }

    public function testDeletesWholeArchivesByTheDayInTheirName(): void
    {
        // Noon, so a DST change never moves a day boundary across the cutoff.
        $now = (float) strtotime('today 12:00');
        $day = static fn (int $daysAgo): string => date('Y-m-d', (int) $now - $daysAgo * 86400);
        $archives = $this->pablo.'/logs/archive/';
        // The name wins over the mtime, which a copy or a backup can change.
        $old = $this->file($archives.'web.err.log.'.$day(32), 0);
        $lastDay = $this->file($archives.'web.err.log.'.$day(30), 40, 'its day may still be within the month');
        $young = $this->file($archives.'web.err.log.'.$day(29), 40);
        // No date in the name: its mtime decides.
        $undatedOld = $this->file($archives.'dispatch.log.old', 31);
        $undatedYoung = $this->file($archives.'dispatch.log.old2', 29);
        $foreign = $this->file($archives.'notes.txt', 400);

        $pruner = new Pruner(phar: false);
        $items = $pruner->plan(self::DAYS, $now);
        $this->assertSame([$undatedOld, $old], self::paths($items));
        $this->assertSame(PruneItem::LOG_ARCHIVE, $items[0]->kind);

        $pruner->apply($items);
        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($undatedOld);
        foreach ([$lastDay, $young, $undatedYoung, $foreign] as $kept) {
            $this->assertFileExists($kept);
        }
    }

    public function testRemovesTheContainersOfOtherVersionsFromTheBinaryOnly(): void
    {
        $current = $this->file($this->pablo.'/cache/container/abc1234/prod-x/Container.php', 40, '<?php');
        $stale = $this->container('0ld0000', 40, '<?php //');
        $recent = $this->container('1ate000', 40, '<?php');
        // One recent file is enough to keep a container.
        $this->file($recent.'/prod-z/Other.php', 10);

        $this->assertSame([], (new Pruner(phar: false, version: 'abc1234'))->staleContainers(), 'nothing from a checkout');

        $pruner = new Pruner(phar: true, version: 'abc1234');
        $items = $pruner->plan(self::DAYS);
        $this->assertSame([$stale], self::paths($items));
        $this->assertSame(8, $items[0]->bytes);

        $pruner->apply($items);
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($recent);
        $this->assertFileExists($current);

        // system:install drops every other version right away.
        $this->assertSame([$recent], self::paths($pruner->staleContainers()));
    }

    /** A version's container dir holding one file, all of it $daysAgo days old. */
    private function container(string $version, int $daysAgo, string $content): string
    {
        $dir = $this->pablo.'/cache/container/'.$version;
        $this->file($dir.'/prod-y/Container.php', $daysAgo, $content);
        touch($dir.'/prod-y', time() - $daysAgo * 86400);
        touch($dir, time() - $daysAgo * 86400);

        return $dir;
    }

    public function testLeavesEverythingElseAlone(): void
    {
        $keep = [
            $this->file($this->pablo.'/state/acme/wk-1.json', 400),
            $this->file($this->pablo.'/state/acme/wk-1.lock', 400),
            $this->file($this->pablo.'/stamps/acme.sync', 400),
            $this->file($this->pablo.'/analytics/acme/2025-01.jsonl', 400),
            $this->file($this->pablo.'/worktrees/acme/wk-1/old.log', 400),
            $this->file($this->pablo.'/agents/123.json', 400, '{"pid": 1}'),
            $this->file($this->pablo.'/agents/logs/notes.txt', 400),
            $this->file($this->pablo.'/cache/jira-sites.json', 400),
            $this->file($this->pablo.'/config.yaml', 400),
            $this->file($this->home.'/.pablo-backups/pablo-backup.tar.gz', 400),
        ];

        $pruner = new Pruner(phar: true, version: 'abc1234');
        $pruner->apply($pruner->plan(self::DAYS));

        foreach ($keep as $path) {
            $this->assertFileExists($path);
        }
    }

    public function testDescribe(): void
    {
        $item = new PruneItem(PruneItem::AGENT_LOG, '/x.log', 2048);
        $this->assertSame('would remove agent log /x.log (2.0 KB)', $item->describe(true));
        $this->assertSame('removed agent log /x.log (2.0 KB)', $item->describe(false));
        $this->assertSame('rotated service log /d.log into /a/d.log.2026-11-12 (512 B)', (new PruneItem(PruneItem::SERVICE_LOG, '/d.log', 512, '/a/d.log.2026-11-12'))->describe(false));
        $this->assertSame('3.0 MB', PruneItem::humanBytes(3 * 1024 * 1024));
    }
}
