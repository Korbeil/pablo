<?php

declare(strict_types=1);

namespace Pablo\Update;

use Pablo\App\ConsoleApplication;
use Pablo\Dispatch\Dispatch;
use Pablo\Support\Files;
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
 *
 * The replaced binary is kept as `<binary>.previous` (one copy only) so
 * `pablo update --rollback` can swap it back without a download. It lives
 * next to the binary rather than under ~/.pablo: only the same directory is
 * sure to be on the same filesystem, which rename() needs to be atomic.
 */
final class Updater
{
    public const DEFAULT_RELEASES_URL = 'https://github.com/Korbeil/pablo/releases';

    private const DOWNLOAD_TIMEOUT_S = 300.0;

    /** A dispatch run is stopped by its service after 10 minutes (TimeoutStartSec). */
    public const DISPATCH_WAIT_S = 600.0;

    public const PREVIOUS_SUFFIX = '.previous';

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

    /** Where the replaced binary is kept, '' when PABLO runs from a checkout. */
    public function previousPath(): string
    {
        $binary = $this->binaryPath();

        return '' === $binary ? '' : self::previousOf($binary);
    }

    public static function previousOf(string $binary): string
    {
        return $binary.self::PREVIOUS_SUFFIX;
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
     * first 7 characters of their commit SHA (`castor release:create`), which carries no
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
        $binary = $this->writableBinary();
        $asset = Target::asset($this->target ?? Target::current());
        $base = self::releasesUrl().'/download/'.rawurlencode($version);
        $tmp = $this->tempDir($binary);

        try {
            $info("downloading {$base}/{$asset}");
            $this->download($base.'/'.$asset, $tmp.'/'.$asset);
            $this->download($base.'/sha256sums.txt', $tmp.'/sha256sums.txt');

            $expected = self::expectedHash(Files::read($tmp.'/sha256sums.txt'), $asset);
            if (null === $expected) {
                throw new PabloError("{$asset} is not listed in the release's sha256sums.txt: refusing an unverified binary");
            }
            $actual = (string) hash_file('sha256', $tmp.'/'.$asset);
            if (!hash_equals($expected, $actual)) {
                throw new PabloError("checksum mismatch for {$asset} (expected {$expected}, got {$actual}): nothing was changed");
            }
            $info('sha256 verified');

            @chmod($tmp.'/'.$asset, 0o755);
            $reported = $this->runVersion($tmp.'/'.$asset, $tmp, 'the downloaded binary');
            // It must report the release it was downloaded as, or every later
            // `pablo update` would see a different name and reinstall it.
            if ("pablo {$version}" !== $reported) {
                throw new PabloError("the downloaded binary reports '{$reported}', not 'pablo {$version}': nothing was changed");
            }

            $this->keepPrevious($binary, $tmp);
            if (!@rename($tmp.'/'.$asset, $binary)) {
                throw new PabloError("cannot replace {$binary}");
            }
            $info("replaced {$binary} with {$version} (the previous binary is kept as {$this->previousPath()})");
        } finally {
            Files::remove($tmp);
        }
    }

    /**
     * Swap the binary kept by the last update back in, after checking it
     * runs. The replaced one becomes the previous binary in turn, so a second
     * rollback undoes the first.
     *
     * @param \Closure(string): void $info
     *
     * @return string the version of the restored binary
     *
     * @throws PabloError
     */
    public function rollback(\Closure $info): string
    {
        $binary = $this->writableBinary();
        $previous = $this->previousPath();
        if (!is_file($previous)) {
            throw new PabloError("no previous binary to roll back to ({$previous} does not exist): pablo update keeps one when it replaces the binary; to install a given release, run pablo update <release>");
        }

        $tmp = $this->tempDir($binary);
        try {
            @chmod($previous, 0o755);
            $reported = $this->runVersion($previous, $tmp, "the previous binary ({$previous})");
            $version = 1 === preg_match('/^pablo (\S+)$/', $reported, $m) ? $m[1] : $reported;

            // Stage a second name for the current binary first: once the
            // previous one is renamed over it, nothing else points to it.
            $staged = null;
            if (is_file($binary)) {
                $staged = $tmp.'/current';
                self::linkOrCopy($binary, $staged);
            }
            if (!@rename($previous, $binary)) {
                throw new PabloError("cannot restore {$previous} over {$binary}: nothing was changed");
            }
            if (null !== $staged && !@rename($staged, $previous)) {
                $info("warning: could not keep the replaced binary as {$previous}");
            }
            $info("restored {$binary} to {$version}");

            return $version;
        } finally {
            Files::remove($tmp);
        }
    }

    /** @throws PabloError */
    private function writableBinary(): string
    {
        $binary = $this->binaryPath();
        if ('' === $binary) {
            throw new PabloError('pablo runs from a repository checkout: update it with git pull, then php app/bin/pablo system:install');
        }
        $dir = \dirname($binary);
        if (!is_writable($dir)) {
            throw new PabloError("cannot replace {$binary}: {$dir} is not writable");
        }

        return $binary;
    }

    /**
     * A private scratch dir next to the binary: same filesystem, so what is
     * staged in it can be renamed into place atomically.
     *
     * @throws PabloError
     */
    private function tempDir(string $binary): string
    {
        $tmp = \dirname($binary).'/.pablo-update-'.bin2hex(random_bytes(4));
        if (!@mkdir($tmp, 0o700)) {
            throw new PabloError("cannot create {$tmp}");
        }

        return $tmp;
    }

    /**
     * Run $path once with `--version` before trusting it, in a throwaway
     * HOME so its container cache does not land in ~/.pablo/cache. Returns
     * the first line it prints.
     *
     * @throws PabloError
     */
    private function runVersion(string $path, string $tmp, string $what): string
    {
        // Best-effort: without it the version check still runs, its cache lands in $tmp.
        @mkdir($tmp.'/home', 0o700);
        $check = $this->runner->probe(['env', 'HOME='.$tmp.'/home', $path, '--version'], 120.0);
        if (0 !== $check->exitCode) {
            throw new PabloError("{$what} does not run: {$check->output}");
        }

        return trim(strtok($check->output, "\n") ?: '');
    }

    /**
     * Keep the current binary as the previous one, replacing an older copy
     * atomically. A hard link costs no space (the running processes hold
     * that inode anyway); a copy when the filesystem has none.
     *
     * @throws PabloError
     */
    private function keepPrevious(string $binary, string $tmp): void
    {
        if (!is_file($binary)) {
            return;
        }
        $staged = $tmp.'/previous';
        self::linkOrCopy($binary, $staged);
        if (!@rename($staged, $this->previousPath())) {
            throw new PabloError("cannot keep the current binary as {$this->previousPath()}: nothing was changed");
        }
    }

    /** @throws PabloError */
    private static function linkOrCopy(string $from, string $to): void
    {
        if (@link($from, $to)) {
            return;
        }
        if (!@copy($from, $to)) {
            throw new PabloError("cannot copy {$from}: nothing was changed");
        }
        @chmod($to, 0o755);
    }

    /**
     * Take the global dispatch lock, waiting for a running dispatch (sync,
     * poll, main update) to finish: swapping the binary and pruning the old
     * version's container under a run of the old binary would crash it. While
     * the lock is held, scheduler ticks skip. Release it with fclose().
     *
     * @param \Closure(string): void $info
     *
     * @return resource
     *
     * @throws PabloError when the lock cannot be had within $timeoutS
     */
    public function lockDispatch(\Closure $info, float $timeoutS = self::DISPATCH_WAIT_S, float $pollS = 2.0)
    {
        $path = Dispatch::lockPath();
        $dir = \dirname($path);
        Files::ensureDir($dir);
        $fd = @fopen($path, 'c+');
        if (false === $fd) {
            throw new PabloError("cannot open {$path}");
        }
        $deadline = microtime(true) + $timeoutS;
        $waiting = false;
        while (!flock($fd, \LOCK_EX | \LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($fd);
                throw new PabloError('a PABLO dispatch run is still in progress: try pablo update again in a few minutes');
            }
            if (!$waiting) {
                $info('waiting for the running dispatch (sync/poll) to finish…');
                $waiting = true;
            }
            usleep((int) ($pollS * 1_000_000));
        }

        return $fd;
    }

    /**
     * `system:install` run by the (new) binary, so its own templates, units
     * and container are used. $withWeb keeps a `--without-web` choice.
     */
    public function runInstall(bool $withWeb = true): ProbeResult
    {
        $argv = [$this->binaryPath(), 'system:install', '--no-interaction'];
        if (!$withWeb) {
            $argv[] = '--without-web';
        }

        return $this->runner->probe($argv, 900.0);
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
}
