<?php

declare(strict_types=1);

namespace Pablo\Update;

use Pablo\App\ConsoleApplication;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use Pablo\Support\ProbeResult;
use Pablo\Support\ProcessRunnerInterface;

/**
 * `pablo update`: replaces the running binary with the latest GitHub release
 * for the same OS and architecture.
 *
 * Everything goes through `curl` (the installer needs it too): the static
 * binary's OpenSSL may not find the system's CA certificates, `curl` does.
 * The new binary is downloaded next to the current one (same filesystem),
 * checked against the release's sha256sums.txt, run once (`--version`, in a
 * throwaway HOME), then renamed over the current one — atomic, so a running
 * dashboard or dispatcher keeps its old inode until it restarts.
 */
final class Updater
{
    public const DEFAULT_RELEASES_URL = 'https://github.com/Korbeil/pablo/releases';

    private const DOWNLOAD_TIMEOUT_S = 300.0;

    /**
     * @param string|null $binaryPath override of the running binary (tests); '' outside one
     * @param string|null $version    override of the running version (tests)
     * @param string|null $target     override of the platform target (tests)
     */
    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly ?string $binaryPath = null,
        private readonly ?string $version = null,
        private readonly ?string $target = null,
    ) {
    }

    /** The binary to replace, '' when PABLO runs from a checkout. */
    public function binaryPath(): string
    {
        return $this->binaryPath ?? PabloPaths::pharPath();
    }

    public function currentVersion(): string
    {
        return $this->version ?? ConsoleApplication::version();
    }

    /** PABLO_RELEASES_URL (same variable as the installer), else GitHub. Resolved per call. */
    public static function releasesUrl(): string
    {
        $url = getenv('PABLO_RELEASES_URL');

        return rtrim(false !== $url && '' !== $url ? $url : self::DEFAULT_RELEASES_URL, '/');
    }

    /**
     * The tag of the latest release, read from where /releases/latest
     * redirects to (…/releases/tag/<tag>): no API call, no rate limit.
     *
     * @throws PabloError
     */
    public function latestVersion(): string
    {
        $result = $this->runner->probe(
            ['curl', '-fsSLI', '-o', '/dev/null', '-w', '%{url_effective}', self::releasesUrl().'/latest'],
            60.0,
        );
        $url = trim($result->output);
        if (0 !== $result->exitCode || 1 !== preg_match('#/releases/tag/([^/?\#\s]+)$#', $url, $m)) {
            throw new PabloError('cannot find the latest PABLO release ('.('' !== $url ? $url : 'curl exit '.$result->exitCode).')');
        }

        return rawurldecode($m[1]);
    }

    /**
     * Whether $latest should replace $current. Releases are named after the
     * short hash of their commit (`castor release:create`), which carries no
     * order: any other name than the running one is installed, so moving the
     * "latest" flag back to an older release rolls everyone back. Only
     * semver-looking names (vX.Y.Z) are compared, never to downgrade.
     */
    public static function isNewer(string $latest, string $current): bool
    {
        if ($latest === $current) {
            return false;
        }
        $semver = '/^v?\d+\.\d+\.\d+([-+].*)?$/';
        if (1 === preg_match($semver, $latest) && 1 === preg_match($semver, $current)) {
            return version_compare(ltrim($latest, 'v'), ltrim($current, 'v'), '>');
        }

        return true;
    }

    /**
     * Download, verify and swap in release $version.
     *
     * @param \Closure(string): void $info
     *
     * @throws PabloError
     */
    public function update(string $version, \Closure $info): void
    {
        $binary = $this->binaryPath();
        if ('' === $binary) {
            throw new PabloError('pablo runs from a repository checkout: update it with git pull, then php app/bin/pablo system:install');
        }
        $dir = \dirname($binary);
        if (!is_writable($dir)) {
            throw new PabloError("cannot replace {$binary}: {$dir} is not writable");
        }

        $asset = Target::asset($this->target ?? Target::current());
        $base = self::releasesUrl().'/download/'.rawurlencode($version);
        $tmp = $dir.'/.pablo-update-'.bin2hex(random_bytes(4));
        if (!@mkdir($tmp, 0o700)) {
            throw new PabloError("cannot create {$tmp}");
        }

        try {
            $info("downloading {$base}/{$asset}");
            $this->download($base.'/'.$asset, $tmp.'/'.$asset);
            $this->download($base.'/sha256sums.txt', $tmp.'/sha256sums.txt');

            $expected = self::expectedHash((string) @file_get_contents($tmp.'/sha256sums.txt'), $asset);
            if (null === $expected) {
                throw new PabloError("{$asset} is not listed in the release's sha256sums.txt: refusing an unverified binary");
            }
            $actual = (string) hash_file('sha256', $tmp.'/'.$asset);
            if (!hash_equals($expected, $actual)) {
                throw new PabloError("checksum mismatch for {$asset} (expected {$expected}, got {$actual}): nothing was changed");
            }
            $info('sha256 verified');

            @chmod($tmp.'/'.$asset, 0o755);
            // Run it once before trusting it, in a throwaway HOME so its
            // container cache does not land in ~/.pablo/cache.
            @mkdir($tmp.'/home', 0o700);
            $check = $this->runner->probe(['env', 'HOME='.$tmp.'/home', $tmp.'/'.$asset, '--version'], 120.0);
            if (0 !== $check->exitCode || !str_contains($check->output, 'pablo')) {
                throw new PabloError("the downloaded binary does not run: {$check->output}");
            }

            if (!@rename($tmp.'/'.$asset, $binary)) {
                throw new PabloError("cannot replace {$binary}");
            }
            $info("replaced {$binary} with {$version}");
        } finally {
            self::removeTree($tmp);
        }
    }

    /**
     * `system:install` run by the (new) binary, so its own templates, units
     * and container are used.
     */
    public function runInstall(): ProbeResult
    {
        return $this->runner->probe([$this->binaryPath(), 'system:install', '--no-interaction'], 900.0);
    }

    /** The sha256 of $asset in a `sha256sum` listing, or null. */
    public static function expectedHash(string $sums, string $asset): ?string
    {
        foreach (explode("\n", $sums) as $line) {
            if (1 === preg_match('/^([0-9a-f]{64})\s+\*?(\S+)\s*$/i', trim($line), $m) && $m[2] === $asset) {
                return strtolower($m[1]);
            }
        }

        return null;
    }

    /** @throws PabloError */
    private function download(string $url, string $dest): void
    {
        $result = $this->runner->probe(['curl', '-fsSL', '-o', $dest, $url], self::DOWNLOAD_TIMEOUT_S);
        if (0 !== $result->exitCode || !is_file($dest)) {
            throw new PabloError("cannot download {$url}".('' !== $result->output ? ': '.$result->output : ''));
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                self::removeTree($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }
}
