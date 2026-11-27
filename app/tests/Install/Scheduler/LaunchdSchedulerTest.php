<?php

declare(strict_types=1);

namespace Pablo\Tests\Install\Scheduler;

use Pablo\Install\InstallLog;
use Pablo\Install\Scheduler\LaunchdScheduler;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use Pablo\Support\ProbeResult;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class LaunchdSchedulerTest extends TestCase
{
    private FakeProcessRunner $runner;
    private string $tmp;
    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->runner = new FakeProcessRunner();
        $this->tmp = TempDir::create('launchd');
    }

    public function testBootstrapWaitsForTheBootedOutServiceToLeaveTheDomain(): void
    {
        // com.pablo.web (KeepAlive) is still listed twice after its bootout.
        $prints = ['com.pablo.web' => 2];
        $this->runner->onProbe = static function (array $argv) use (&$prints): ProbeResult {
            if ('print' === $argv[1]) {
                $label = substr($argv[2], strrpos($argv[2], '/') + 1);
                if (($prints[$label] ?? 0) > 0) {
                    --$prints[$label];

                    return new ProbeResult(0, 'state = running');
                }

                return new ProbeResult(113, 'Could not find service');
            }

            return new ProbeResult(0, '');
        };

        $this->scheduler()->install('/bin/pablo', true, '/usr/bin', 1, $this->log());

        $web = array_values(array_filter($this->runner->probeCalls, static fn (array $argv): bool => str_ends_with($argv[2], 'com.pablo.web') || str_ends_with($argv[3] ?? '', 'com.pablo.web.plist')));
        $this->assertSame(['bootout', 'print', 'print', 'print', 'bootstrap'], array_map(static fn (array $argv): string => $argv[1], $web));
        $this->assertCount(2, $this->slept);
    }

    public function testAFailedBootstrapIsRetriedThenSucceeds(): void
    {
        $failures = 2;
        $this->runner->onProbe = static function (array $argv) use (&$failures): ProbeResult {
            return match ($argv[1]) {
                'print' => new ProbeResult(113, 'Could not find service'),
                'bootstrap' => $failures-- > 0 ? new ProbeResult(5, 'Bootstrap failed: 5: Input/output error') : new ProbeResult(0, ''),
                default => new ProbeResult(0, ''),
            };
        };

        $this->scheduler()->install('/bin/pablo', false, '/usr/bin', 1, $this->log());

        $bootstraps = array_filter($this->runner->probeCalls, static fn (array $argv): bool => 'bootstrap' === $argv[1]);
        $this->assertCount(4, $bootstraps); // dispatch ×3, prune ×1
    }

    public function testABootstrapThatKeepsFailingIsAnError(): void
    {
        $this->runner->onProbe = static fn (array $argv): ProbeResult => match ($argv[1]) {
            'print' => new ProbeResult(113, 'Could not find service'),
            'bootstrap' => new ProbeResult(5, 'Bootstrap failed: 5: Input/output error'),
            default => new ProbeResult(0, ''),
        };

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('launchctl bootstrap com.pablo.dispatch failed: Bootstrap failed: 5');
        try {
            $this->scheduler()->install('/bin/pablo', false, '/usr/bin', 1, $this->log());
        } finally {
            $this->assertCount(5, array_filter($this->runner->probeCalls, static fn (array $argv): bool => 'bootstrap' === $argv[1]));
        }
    }

    public function testTheTickIsWrittenAndReadBack(): void
    {
        $this->runner->onProbe = static fn (array $argv): ProbeResult => new ProbeResult('print' === $argv[1] ? 113 : 0, '');

        $this->scheduler()->install('/bin/pablo', false, '/usr/bin', 15, $this->log());

        $plist = (string) file_get_contents($this->tmp.'/LaunchAgents/com.pablo.dispatch.plist');
        $this->assertMatchesRegularExpression('#<key>StartInterval</key>\s*<integer>900</integer>#', $plist);
        $this->assertSame(15, $this->scheduler()->dispatchStatus()->tickMinutes);
    }

    private function scheduler(): LaunchdScheduler
    {
        return new LaunchdScheduler(
            $this->runner,
            PabloPaths::resourcesDir().'/launchd',
            $this->tmp.'/LaunchAgents',
            $this->tmp.'/logs',
            $this->tmp,
            501,
            function (int $us): void { $this->slept[] = $us; },
        );
    }

    private function log(): InstallLog
    {
        return new InstallLog(new NullOutput());
    }
}
