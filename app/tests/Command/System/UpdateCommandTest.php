<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Command\System\UpdateCommand;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Store\Store;
use Pablo\Support\ProbeResult;
use Pablo\Tests\FakeAgents;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Update\Updater;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class UpdateCommandTest extends TestCase
{
    private string $tmp;
    private string $binary;
    private FakeProcessRunner $runner;
    private string $latest = 'v2.0.0';
    private int $installExit = 0;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-update-cmd-'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0o777, true);
        $this->binary = $this->tmp.'/pablo';
        file_put_contents($this->binary, "OLD\n");

        $new = "#!/bin/sh\necho 'pablo v2.0.0'\n";
        $this->runner = new FakeProcessRunner();
        $this->runner->onProbe = function (array $argv) use ($new): ProbeResult {
            if ('curl' === $argv[0] && \in_array('-fsSLI', $argv, true)) {
                return new ProbeResult(0, Updater::DEFAULT_RELEASES_URL.'/tag/'.$this->latest);
            }
            if ('curl' === $argv[0]) {
                file_put_contents($argv[3], str_ends_with($argv[4], 'sha256sums.txt') ? hash('sha256', $new)."  pablo-linux-x86_64\n" : $new);

                return new ProbeResult(0, '');
            }
            if ('env' === $argv[0]) {
                return new ProbeResult(0, 'pablo v2.0.0');
            }

            return new ProbeResult($this->installExit, 'install output');
        };
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    public function testUpdatesThenRunsSystemInstallWithTheNewBinary(): void
    {
        $tester = $this->runUpdate([]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('PABLO updated: v1.0.0 → v2.0.0', $tester->getDisplay());
        $this->assertStringContainsString('install output', $tester->getDisplay());
        $this->assertContains([$this->binary, 'system:install', '--no-interaction'], $this->runner->probeCalls);
        $this->assertStringContainsString('pablo v2.0.0', (string) file_get_contents($this->binary));
    }

    public function testCheckOnlyReports(): void
    {
        $tester = $this->runUpdate(['--check' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('PABLO v2.0.0 is available (installed: v1.0.0)', $tester->getDisplay());
        $this->assertSame("OLD\n", file_get_contents($this->binary));
    }

    public function testUpToDateDoesNothing(): void
    {
        $this->latest = 'v1.0.0';

        $tester = $this->runUpdate([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('PABLO v1.0.0 is up to date', $tester->getDisplay());
        $this->assertCount(1, $this->runner->probeCalls);
    }

    public function testNoInstallSkipsSystemInstall(): void
    {
        $tester = $this->runUpdate(['--no-install' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertNotContains([$this->binary, 'system:install', '--no-interaction'], $this->runner->probeCalls);
    }

    public function testAFailingSystemInstallIsReported(): void
    {
        $this->installExit = 1;

        $tester = $this->runUpdate([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('system:install failed', $tester->getDisplay());
    }

    public function testFromACheckoutItPointsToGitPull(): void
    {
        $tester = $this->runUpdate([], new Updater($this->runner, '', 'dev', 'linux-x86_64'));

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('git pull', $tester->getDisplay());
        $this->assertSame([], $this->runner->probeCalls);
    }

    public function testANamedReleaseIsInstalledWithoutAskingForTheLatest(): void
    {
        $tester = $this->runUpdate(['release' => 'abc1234']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('PABLO updated: v1.0.0 → abc1234', $tester->getDisplay());
        $this->assertNotContains('-fsSLI', array_merge(...$this->runner->probeCalls), 'the latest release is not looked up');
        $this->assertContains(['curl', '-fsSL', '-o', $this->downloadedTo(), Updater::DEFAULT_RELEASES_URL.'/download/abc1234/pablo-linux-x86_64'], $this->runner->probeCalls);
    }

    public function testANamedReleaseCanBeOlderThanTheRunningOne(): void
    {
        $tester = $this->runUpdate(['release' => 'v0.9.0']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('PABLO updated: v1.0.0 → v0.9.0', $tester->getDisplay());
    }

    public function testTheRunningReleaseIsNotReinstalled(): void
    {
        $tester = $this->runUpdate(['release' => 'v1.0.0']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('already the running release', $tester->getDisplay());
        $this->assertSame([], $this->runner->probeCalls);
    }

    public function testAnUnknownReleaseIsReportedAsSuch(): void
    {
        $this->runner->onProbe = static fn (array $argv): ProbeResult => new ProbeResult(22, 'curl: (22) The requested URL returned error: 404');

        $tester = $this->runUpdate(['release' => 'nope123']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('is nope123 a PABLO release?', $tester->getDisplay());
        $this->assertSame("OLD\n", file_get_contents($this->binary));
    }

    public function testIsAlsoCalledUpdate(): void
    {
        $this->assertContains('update', $this->command(new Updater($this->runner))->getAliases());
    }

    /** Where the fake curl call for the binary wrote it (the temporary dir is random). */
    private function downloadedTo(): string
    {
        foreach ($this->runner->probeCalls as $argv) {
            if ('curl' === $argv[0] && '-o' === ($argv[2] ?? null) && str_ends_with($argv[4] ?? '', 'pablo-linux-x86_64')) {
                return $argv[3];
            }
        }
        $this->fail('the binary was never downloaded');
    }

    /** @param array<string, mixed> $input */
    private function runUpdate(array $input, ?Updater $updater = null): CommandTester
    {
        $tester = new CommandTester($this->command($updater ?? new Updater($this->runner, $this->binary, 'v1.0.0', 'linux-x86_64')));
        $tester->execute($input);

        return $tester;
    }

    private function command(Updater $updater): UpdateCommand
    {
        $command = new UpdateCommand($updater, new Store(), new Config(new GlobalConfig()), new AgentLauncherFactory(new GlobalConfig()), new FakeAgents());
        (new Application())->addCommand($command);

        return $command;
    }
}
