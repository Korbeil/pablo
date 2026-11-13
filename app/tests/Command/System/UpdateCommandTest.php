<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Command\System\UpdateCommand;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Dispatch\Dispatch;
use Pablo\Install\Scheduler\SchedulerFactory;
use Pablo\Store\Store;
use Pablo\Support\ProbeResult;
use Pablo\Tests\FakeAgents;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\SandboxedEnv;
use Pablo\Tests\TempDir;
use Pablo\Update\Updater;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class UpdateCommandTest extends TestCase
{
    use SandboxedEnv;

    private const OLD = "#!/bin/sh\necho 'pablo v1.0.0'\n";

    private string $tmp;
    private string $binary;
    private FakeProcessRunner $runner;
    private string $latest = 'v2.0.0';
    private int $installExit = 0;

    /** @var list<int> exit codes of the next system:install runs, before */
    private array $installExits = [];

    /** @var list<string> the content of the binary each system:install ran */
    private array $installedBy = [];

    /** The release the fake curl last downloaded: what the fake new binary reports. */
    private string $downloaded = '';

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('update-cmd');
        $this->binary = $this->tmp.'/pablo';
        file_put_contents($this->binary, self::OLD);
        chmod($this->binary, 0o755);
        $this->setEnv('HOME', $this->tmp.'/home');
        $this->setEnv('PABLO_STAMPS_DIR', $this->tmp.'/stamps');
        $this->withWebService(true);

        $new = "#!/bin/sh\necho 'pablo v2.0.0'\n";
        $this->runner = new FakeProcessRunner();
        $this->runner->onProbe = function (array $argv) use ($new): ProbeResult {
            if ('curl' === $argv[0] && \in_array('-fsSLI', $argv, true)) {
                return new ProbeResult(0, Updater::DEFAULT_RELEASES_URL.'/tag/'.$this->latest);
            }
            if ('curl' === $argv[0]) {
                if (1 === preg_match('#/download/([^/]+)/#', $argv[4], $m)) {
                    $this->downloaded = $m[1];
                }
                file_put_contents($argv[3], str_ends_with($argv[4], 'sha256sums.txt') ? hash('sha256', $new)."  pablo-linux-x86_64\n" : $new);

                return new ProbeResult(0, '');
            }
            if ('env' === $argv[0] && str_ends_with($argv[2], '.previous')) {
                $out = [];
                exec(escapeshellarg($argv[2]).' --version', $out, $code);

                return new ProbeResult($code, implode("\n", $out));
            }
            if ('env' === $argv[0]) {
                return new ProbeResult(0, 'pablo '.$this->downloaded);
            }
            if (\in_array('system:install', $argv, true)) {
                $this->installedBy[] = trim((string) file_get_contents($argv[0]));
            }

            return new ProbeResult(array_shift($this->installExits) ?? $this->installExit, 'install output');
        };
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
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
        $this->assertSame(self::OLD, file_get_contents($this->binary));
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

    public function testAFailingSystemInstallRollsBackToThePreviousBinary(): void
    {
        $this->installExits = [1];

        $tester = $this->runUpdate([]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('PABLO rolled back: v2.0.0 → v1.0.0 (system:install failed with v2.0.0)', $tester->getDisplay());
        $this->assertSame(self::OLD, file_get_contents($this->binary));
        $this->assertCount(2, $this->installedBy, 'the restored binary installs again');
        $this->assertStringContainsString('pablo v2.0.0', $this->installedBy[0]);
        $this->assertStringContainsString('pablo v1.0.0', $this->installedBy[1]);
    }

    public function testAFailingInstallOfTheRestoredBinaryIsReported(): void
    {
        $this->installExit = 1;

        $tester = $this->runUpdate([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('PABLO rolled back: v2.0.0 → v1.0.0', $tester->getDisplay());
        $this->assertStringContainsString("system:install failed: fix the error above, then run: {$this->binary} system:install", $tester->getDisplay());
    }

    public function testRollbackRestoresThePreviousBinaryAndInstallsWithIt(): void
    {
        $this->assertSame(0, $this->runUpdate([])->getStatusCode());
        $this->runner->probeCalls = [];

        $tester = $this->runUpdate(['--rollback' => true], new Updater($this->runner, $this->binary, 'v2.0.0', 'linux-x86_64'));

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('PABLO rolled back: v2.0.0 → v1.0.0', $tester->getDisplay());
        $this->assertSame(self::OLD, file_get_contents($this->binary));
        $this->assertStringContainsString('pablo v2.0.0', (string) file_get_contents($this->binary.'.previous'));
        $this->assertContains([$this->binary, 'system:install', '--no-interaction'], $this->runner->probeCalls);
        $this->assertNotContains('curl', array_column($this->runner->probeCalls, 0), 'nothing is downloaded');
    }

    public function testRollbackKeepsAWithoutWebInstallWithoutTheDashboard(): void
    {
        $this->assertSame(0, $this->runUpdate([])->getStatusCode());
        $this->withWebService(false);

        $tester = $this->runUpdate(['--rollback' => true], new Updater($this->runner, $this->binary, 'v2.0.0', 'linux-x86_64'));

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertContains([$this->binary, 'system:install', '--no-interaction', '--without-web'], $this->runner->probeCalls);
    }

    public function testRollbackWithoutAPreviousBinaryIsAClearError(): void
    {
        $tester = $this->runUpdate(['--rollback' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('no previous binary to roll back to', $tester->getDisplay());
        $this->assertSame([], $this->runner->probeCalls);
        $this->assertSame(self::OLD, file_get_contents($this->binary));
    }

    public function testRollbackTakesNoRelease(): void
    {
        $tester = $this->runUpdate(['--rollback' => true, 'release' => 'abc1234']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--rollback takes no release', $tester->getDisplay());
    }

    public function testRollbackFromACheckoutPointsToGitPull(): void
    {
        $tester = $this->runUpdate(['--rollback' => true], new Updater($this->runner, '', 'dev', 'linux-x86_64'));

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('git pull', $tester->getDisplay());
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
        $this->assertSame(self::OLD, file_get_contents($this->binary));
    }

    public function testAWithoutWebInstallStaysWithoutTheDashboard(): void
    {
        $this->withWebService(false);

        $tester = $this->runUpdate([]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertContains([$this->binary, 'system:install', '--no-interaction', '--without-web'], $this->runner->probeCalls);
    }

    public function testWaitsForARunningDispatchAndGivesUpWithoutChangingAnything(): void
    {
        mkdir($this->tmp.'/stamps', 0o777, true);
        $held = fopen(Dispatch::lockPath(), 'c+');
        $this->assertNotFalse($held);
        $this->assertTrue(flock($held, \LOCK_EX | \LOCK_NB));

        try {
            $updater = new Updater($this->runner, $this->binary, 'v1.0.0', 'linux-x86_64');
            $this->expectExceptionMessage('dispatch run is still in progress');
            $updater->lockDispatch(static function (string $line): void {}, 0.2, 0.05);
        } finally {
            fclose($held);
            $this->assertSame(self::OLD, file_get_contents($this->binary));
        }
    }

    public function testTheDispatchLockIsHeldDuringTheSwapAndReleasedAfter(): void
    {
        $heldDuringInstall = null;
        $previous = $this->runner->onProbe;
        \assert(\is_callable($previous));
        $this->runner->onProbe = static function (array $argv) use ($previous, &$heldDuringInstall): ProbeResult {
            if (\in_array('system:install', $argv, true)) {
                $fd = fopen(Dispatch::lockPath(), 'c+');
                $heldDuringInstall = false === $fd || !flock($fd, \LOCK_EX | \LOCK_NB);
                if (false !== $fd) {
                    fclose($fd);
                }
            }

            return ($previous)($argv);
        };

        $this->assertSame(0, $this->runUpdate([])->getStatusCode());

        $this->assertTrue($heldDuringInstall, 'a dispatch tick must not start while system:install runs');
        $fd = fopen(Dispatch::lockPath(), 'c+');
        $this->assertNotFalse($fd);
        $this->assertTrue(flock($fd, \LOCK_EX | \LOCK_NB), 'the lock is released afterwards');
        fclose($fd);
    }

    public function testABinaryReportingAnotherVersionIsRefused(): void
    {
        $previous = $this->runner->onProbe;
        \assert(\is_callable($previous));
        $this->runner->onProbe = static fn (array $argv): ProbeResult => 'env' === $argv[0]
            ? new ProbeResult(0, 'pablo aaaaaaa-3-gbbbbbbb')
            : ($previous)($argv);

        $tester = $this->runUpdate([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("reports 'pablo aaaaaaa-3-gbbbbbbb', not 'pablo v2.0.0'", $tester->getDisplay());
        $this->assertSame(self::OLD, file_get_contents($this->binary));
    }

    public function testIsAlsoCalledUpdate(): void
    {
        $this->assertContains('update', $this->command(new Updater($this->runner))->getAliases());
    }

    public function testCheckAsJson(): void
    {
        $tester = $this->runUpdate(['--check' => true, '--format' => 'json']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(
            ['current' => 'v1.0.0', 'latest' => 'v2.0.0', 'requested' => null, 'update_available' => true],
            json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR),
        );
        $this->assertSame(self::OLD, file_get_contents($this->binary));
    }

    public function testCheckAsJsonWhenUpToDate(): void
    {
        $this->latest = 'v1.0.0';

        $tester = $this->runUpdate(['--check' => true, '--format' => 'json']);

        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['update_available']);
    }

    public function testALatestReleaseOtherThanTheRunningOneIsAnUpdateWhateverItsName(): void
    {
        // Release names (commit SHAs) carry no order: only equality matters.
        $this->latest = 'v0.9.0';

        $tester = $this->runUpdate(['--check' => true, '--format' => 'json']);

        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['update_available']);
    }

    public function testJsonNeedsCheck(): void
    {
        $tester = $this->runUpdate(['--format' => 'json']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--format=json is only supported with --check', $tester->getDisplay());
        $this->assertSame([], $this->runner->probeCalls);
    }

    public function testJsonRefusesRollback(): void
    {
        $tester = $this->runUpdate(['--check' => true, '--rollback' => true, '--format' => 'json']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--format=json is only supported with --check (without --rollback)', $tester->getDisplay());
    }

    public function testJsonFromACheckoutWritesNothingToStdout(): void
    {
        $tester = new CommandTester($this->command(new Updater($this->runner, '', 'dev', 'linux-x86_64')));
        $tester->execute(['--check' => true, '--format' => 'json'], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame('', $tester->getDisplay());
        $this->assertStringContainsString('git pull', $tester->getErrorOutput());
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

    /** A PABLO dashboard unit in the sandbox HOME, or none (a --without-web install). */
    private function withWebService(bool $installed): void
    {
        $unit = $this->tmp.'/home/.config/systemd/user/pablo-web.service';
        if ($installed) {
            @mkdir(\dirname($unit), 0o777, true);
            file_put_contents($unit, "# marker: pablo-web.service\n");
        } else {
            @unlink($unit);
        }
    }

    private function command(Updater $updater): UpdateCommand
    {
        $command = new UpdateCommand($updater, new SchedulerFactory($this->runner, 'Linux'), new Store(), new Config(new GlobalConfig()), \Pablo\Tests\Registries::launchers($this->runner, new GlobalConfig()), new FakeAgents());
        (new Application())->addCommand($command);

        return $command;
    }
}
