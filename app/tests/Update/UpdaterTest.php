<?php

declare(strict_types=1);

namespace Pablo\Tests\Update;

use Pablo\Support\PabloError;
use Pablo\Support\ProbeResult;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Update\Updater;
use PHPUnit\Framework\TestCase;

/**
 * The update flow with curl and the downloaded binary faked: a "release" is
 * a map of URL => file content.
 */
final class UpdaterTest extends TestCase
{
    private const OLD_BINARY = "#!/bin/sh\necho 'pablo v1.0.0'\n";
    private const NEW_BINARY = "#!/bin/sh\necho 'pablo v2.0.0'\n";

    private string $tmp;
    private string $binary;
    private FakeProcessRunner $runner;

    /** @var array<string, string> */
    private array $release = [];

    private string|false $releasesUrl;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-updater-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/bin', 0o777, true);
        $this->binary = $this->tmp.'/bin/pablo';
        file_put_contents($this->binary, self::OLD_BINARY);
        chmod($this->binary, 0o755);
        $this->releasesUrl = getenv('PABLO_RELEASES_URL');
        putenv('PABLO_RELEASES_URL=https://example.test/releases');

        $this->publish('v2.0.0', self::NEW_BINARY);
        $this->runner = new FakeProcessRunner();
        $this->runner->onProbe = function (array $argv): ProbeResult {
            if ('curl' === $argv[0] && \in_array('-fsSLI', $argv, true)) {
                return new ProbeResult(0, 'https://example.test/releases/tag/v2.0.0');
            }
            if ('curl' === $argv[0]) {
                [$dest, $url] = [$argv[3], $argv[4]];
                if (!isset($this->release[$url])) {
                    return new ProbeResult(22, 'curl: (22) The requested URL returned error: 404');
                }
                file_put_contents($dest, $this->release[$url]);

                return new ProbeResult(0, '');
            }
            if ('env' === $argv[0]) {
                $out = [];
                exec(escapeshellarg($argv[2]).' --version', $out, $code);

                return new ProbeResult($code, implode("\n", $out));
            }

            return new ProbeResult(0, 'installed');
        };
    }

    protected function tearDown(): void
    {
        putenv(false === $this->releasesUrl ? 'PABLO_RELEASES_URL' : 'PABLO_RELEASES_URL='.$this->releasesUrl);
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    public function testTheLatestVersionComesFromTheReleaseRedirect(): void
    {
        $this->assertSame('v2.0.0', $this->updater()->latestVersion());
        $this->assertSame(['curl', '-fsSLI', '-o', '/dev/null', '-w', '%{url_effective}', 'https://example.test/releases/latest'], $this->runner->probeCalls[0]);
    }

    public function testNoReleaseIsAClearError(): void
    {
        $this->runner->onProbe = static fn (array $argv): ProbeResult => new ProbeResult(0, 'https://example.test/releases');

        $this->expectExceptionMessage('cannot find the latest PABLO release');
        $this->updater()->latestVersion();
    }

    public function testIsNewer(): void
    {
        $this->assertTrue(Updater::isNewer('v1.10.0', 'v1.9.3'));
        $this->assertFalse(Updater::isNewer('v1.2.0', 'v1.2.0'));
        $this->assertFalse(Updater::isNewer('v1.2.0', 'v1.3.0'), 'never downgrade a newer release');
        $this->assertTrue(Updater::isNewer('v1.2.0', 'b99bd12'), 'a local build moves to the latest release');
    }

    public function testReplacesTheBinaryWithTheVerifiedRelease(): void
    {
        $lines = [];
        $this->updater()->update('v2.0.0', static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertSame(self::NEW_BINARY, file_get_contents($this->binary));
        $this->assertTrue(is_executable($this->binary));
        $this->assertContains('sha256 verified', $lines);
        $this->assertSame([$this->binary, $this->binary.'.previous'], glob($this->tmp.'/bin/*'), 'no temporary file is left behind');
        $this->assertSame(self::OLD_BINARY, file_get_contents($this->binary.'.previous'), 'the replaced binary is kept');
        $this->assertSame([], glob($this->tmp.'/bin/.pablo-update-*') ?: []);
    }

    public function testAChecksumMismatchChangesNothing(): void
    {
        $this->release['https://example.test/releases/download/v2.0.0/sha256sums.txt'] = str_repeat('0', 64)."  pablo-linux-x86_64\n";

        try {
            $this->updater()->update('v2.0.0', static function (string $line): void {});
            $this->fail('a corrupt download was installed');
        } catch (PabloError $e) {
            $this->assertStringContainsString('checksum mismatch', $e->getMessage());
        }
        $this->assertSame(self::OLD_BINARY, file_get_contents($this->binary));
        $this->assertSame([], glob($this->tmp.'/bin/.pablo-update-*') ?: []);
    }

    public function testAnAssetMissingFromTheChecksumsIsRefused(): void
    {
        $this->release['https://example.test/releases/download/v2.0.0/sha256sums.txt'] = '';

        $this->expectExceptionMessage('is not listed in the release');
        $this->updater()->update('v2.0.0', static function (string $line): void {});
    }

    public function testABinaryThatDoesNotRunIsNotInstalled(): void
    {
        $this->publish('v2.0.0', "#!/bin/sh\nexit 1\n");

        try {
            $this->updater()->update('v2.0.0', static function (string $line): void {});
            $this->fail('a broken binary was installed');
        } catch (PabloError $e) {
            $this->assertStringContainsString('does not run', $e->getMessage());
        }
        $this->assertSame(self::OLD_BINARY, file_get_contents($this->binary));
    }

    public function testAMissingAssetIsReported(): void
    {
        $this->expectExceptionMessage('cannot download https://example.test/releases/download/v2.0.0/pablo-macos-arm64');

        (new Updater($this->runner, $this->binary, 'v1.0.0', 'macos-arm64'))->update('v2.0.0', static function (string $line): void {});
    }

    public function testACheckoutIsRefused(): void
    {
        $this->expectExceptionMessage('repository checkout');

        (new Updater($this->runner, '', 'dev', 'linux-x86_64'))->update('v2.0.0', static function (string $line): void {});
    }

    public function testOnlyOnePreviousBinaryIsKept(): void
    {
        $this->publish('v3.0.0', "#!/bin/sh\necho 'pablo v3.0.0'\n");
        $this->updater()->update('v2.0.0', static function (string $line): void {});
        $this->updater()->update('v3.0.0', static function (string $line): void {});

        $this->assertSame(self::NEW_BINARY, file_get_contents($this->binary.'.previous'));
        $this->assertSame([$this->binary, $this->binary.'.previous'], glob($this->tmp.'/bin/*'));
    }

    public function testAFailedCheckKeepsThePreviousCopyAsItWas(): void
    {
        file_put_contents($this->binary.'.previous', 'OLDER');
        $this->publish('v2.0.0', "#!/bin/sh\nexit 1\n");

        try {
            $this->updater()->update('v2.0.0', static function (string $line): void {});
            $this->fail('a broken binary was installed');
        } catch (PabloError) {
        }
        $this->assertSame('OLDER', file_get_contents($this->binary.'.previous'));
    }

    public function testRollbackSwapsThePreviousBinaryBack(): void
    {
        $this->updater()->update('v2.0.0', static function (string $line): void {});

        $restored = (new Updater($this->runner, $this->binary, 'v2.0.0', 'linux-x86_64'))->rollback(static function (string $line): void {});

        $this->assertSame('v1.0.0', $restored);
        $this->assertSame(self::OLD_BINARY, file_get_contents($this->binary));
        $this->assertTrue(is_executable($this->binary));
        $this->assertSame(self::NEW_BINARY, file_get_contents($this->binary.'.previous'), 'a second rollback undoes the first');
        $this->assertSame([], glob($this->tmp.'/bin/.pablo-update-*') ?: []);
        $this->assertContains(['env', 'HOME='.$this->homeOf($this->runner->probeCalls), $this->binary.'.previous', '--version'], $this->runner->probeCalls, 'the previous binary is run before it is trusted');
    }

    public function testRollbackWithoutAPreviousBinaryIsAClearError(): void
    {
        try {
            $this->updater()->rollback(static function (string $line): void {});
            $this->fail('rolled back to nothing');
        } catch (PabloError $e) {
            $this->assertStringContainsString('no previous binary to roll back to', $e->getMessage());
        }
        $this->assertSame(self::OLD_BINARY, file_get_contents($this->binary));
    }

    public function testAPreviousBinaryThatDoesNotRunIsNotRestored(): void
    {
        file_put_contents($this->binary.'.previous', "#!/bin/sh\nexit 1\n");

        try {
            $this->updater()->rollback(static function (string $line): void {});
            $this->fail('a broken binary was restored');
        } catch (PabloError $e) {
            $this->assertStringContainsString('does not run', $e->getMessage());
        }
        $this->assertSame(self::OLD_BINARY, file_get_contents($this->binary));
        $this->assertFileExists($this->binary.'.previous');
    }

    public function testRollbackFromACheckoutIsRefused(): void
    {
        $this->expectExceptionMessage('repository checkout');

        (new Updater($this->runner, '', 'dev', 'linux-x86_64'))->rollback(static function (string $line): void {});
    }

    public function testExpectedHash(): void
    {
        $sums = str_repeat('a', 64)."  pablo-linux-x86_64\n".str_repeat('B', 64)." *pablo-macos-arm64\n";

        $this->assertSame(str_repeat('a', 64), Updater::expectedHash($sums, 'pablo-linux-x86_64'));
        $this->assertSame(str_repeat('b', 64), Updater::expectedHash($sums, 'pablo-macos-arm64'));
        $this->assertNull(Updater::expectedHash($sums, 'pablo-linux-aarch64'));
    }

    /**
     * The throwaway HOME of the last `--version` run.
     *
     * @param list<list<string>> $calls
     */
    private function homeOf(array $calls): string
    {
        $last = '';
        foreach ($calls as $argv) {
            if ('env' === $argv[0]) {
                $last = substr($argv[1], \strlen('HOME='));
            }
        }

        return $last;
    }

    private function updater(): Updater
    {
        return new Updater($this->runner, $this->binary, 'v1.0.0', 'linux-x86_64');
    }

    private function publish(string $version, string $binary): void
    {
        $base = 'https://example.test/releases/download/'.$version;
        $this->release[$base.'/pablo-linux-x86_64'] = $binary;
        $this->release[$base.'/sha256sums.txt'] = hash('sha256', $binary)."  pablo-linux-x86_64\n";
    }
}
