<?php

declare(strict_types=1);

namespace Pablo\Web;

use Pablo\App\Kernel;
use Pablo\Support\Files;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;

/**
 * How `pablo web` runs PHP's built-in web server (`php -S`, with
 * PHP_CLI_SERVER_WORKERS workers), in each way PABLO can run:
 *
 *  - from a repository checkout: the PHP running PABLO, on app/public;
 *  - from a phar run by a regular PHP: that PHP, through a router file that
 *    requires the phar's front controller;
 *  - from the standalone binary: the `micro` SAPI has no `php -S`, so the
 *    binary carries a static PHP CLI (bin/php in the phar, added at build
 *    time). It is extracted once per version, with a plain copy of the phar —
 *    a regular PHP cannot read the phar inside the binary, behind the micro
 *    prefix — under ~/.pablo/cache/container/<version>/web-<hash>/,
 *    which `pablo system:install` prunes with the rest of that version. A
 *    binary built without that CLI uses the `php` of the PATH.
 *
 * Every worker of `php -S` is forked before it opens the phar, so each has its
 * own file handle on it. (A worker forked by PABLO itself would share the
 * parent's handle, and concurrent reads corrupt each other.)
 */
final class PhpServer
{
    /**
     * Loopback only, and not configurable. The dashboard has no authentication
     * and exposes branch names, issue titles and PR URLs.
     */
    public const HOST = '127.0.0.1';

    /** Workers of `php -S`: a page polls two live components while it loads its assets. */
    public const WORKERS = 4;

    /** Where the binary keeps its PHP CLI, inside the phar. */
    public const EMBEDDED_PHP = 'bin/php';

    /** The front controller, relative to the root of the phar or checkout. */
    private const FRONT_CONTROLLER = 'app/public/index.php';

    /**
     * The `php -S` command line for $port.
     *
     * @return list<string>
     *
     * @throws PabloError when no PHP able to run the server can be found
     */
    public function command(int $port): array
    {
        $listen = self::HOST.':'.$port;
        $pharPath = PabloPaths::pharPath();

        if ('' === $pharPath) {
            $public = PabloPaths::resourcesDir().'/app/public';

            return [\PHP_BINARY, '-S', $listen, '-t', $public, $public.'/index.php'];
        }

        if (!\function_exists('micro_get_sfxsize') || !\function_exists('micro_get_self_filename')) {
            // A phar run by a regular PHP: that PHP serves, the phar stays where it is.
            $dir = self::workDir($pharPath);
            $router = self::writeRouter($dir, $pharPath);

            return [\PHP_BINARY, '-S', $listen, '-t', $dir.'/public', $router];
        }

        $dir = self::workDir($pharPath);
        $self = micro_get_self_filename();
        $phar = self::extractPhar($self, self::pharOffset($self, (int) micro_get_sfxsize()), $dir.'/pablo.phar');
        $php = self::extractPhp('phar://'.$pharPath.'/'.self::EMBEDDED_PHP, $dir.'/php') ?? self::phpOnPath();
        $router = self::writeRouter($dir, $phar);

        return [$php, '-d', 'memory_limit=512M', '-S', $listen, '-t', $dir.'/public', $router];
    }

    /**
     * The environment `php -S` runs with: its workers, and the env PABLO itself runs in.
     *
     * @return array<string, string>
     */
    public static function environment(string $pabloEnv): array
    {
        return ['PHP_CLI_SERVER_WORKERS' => (string) self::WORKERS, 'PABLO_ENV' => $pabloEnv];
    }

    /**
     * Where the phar starts when reading $binary from this process. micro
     * hooks the access to its own file so that it reads as the bare phar
     * (offset 0, and filesize() is the phar's); read raw — through another
     * path, say — the phar starts after micro.sfx and its ini ($sfxSize).
     */
    public static function pharOffset(string $binary, int $sfxSize): int
    {
        $head = (string) @file_get_contents($binary, false, null, 0, 4);

        return str_starts_with($head, '#!') || str_starts_with($head, '<?') ? 0 : $sfxSize;
    }

    /**
     * Copy the phar found in $binary from $offset to $dest, unless an
     * identical copy is already there.
     *
     * @throws PabloError
     */
    public static function extractPhar(string $binary, int $offset, string $dest): string
    {
        $size = (int) @filesize($binary) - $offset;
        if ($offset < 0 || $size <= 0) {
            throw new PabloError("cannot locate the phar inside {$binary}");
        }
        if (is_file($dest) && filesize($dest) === $size) {
            return $dest;
        }

        Files::ensureDir(\dirname($dest));
        $in = @fopen($binary, 'r');
        $tmp = $dest.'.tmp-'.getmypid();
        $out = @fopen($tmp, 'w');
        if (false === $in || false === $out) {
            throw new PabloError("cannot extract the phar of {$binary} to {$dest}");
        }
        try {
            fseek($in, $offset);
            $copied = stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
        if ($copied !== $size || !@rename($tmp, $dest)) {
            Files::tryRemove($tmp);
            throw new PabloError("cannot extract the phar of {$binary} to {$dest}");
        }

        return $dest;
    }

    /**
     * Copy the PHP CLI the binary carries to $dest (executable), unless it is
     * already there; null when the binary carries none.
     *
     * @throws PabloError
     */
    public static function extractPhp(string $source, string $dest): ?string
    {
        if (!is_file($source)) {
            return null;
        }
        if (is_file($dest) && is_executable($dest) && filesize($dest) === filesize($source)) {
            return $dest;
        }
        $content = Files::tryRead($source)
            ?? throw new PabloError("cannot read the PHP CLI embedded in the binary ({$source})");
        Files::write($dest, $content, 0o755);

        return $dest;
    }

    /**
     * The router `php -S` runs for every request: the phar's front
     * controller, which serves the compiled assets from the phar too.
     *
     * @throws PabloError
     */
    public static function writeRouter(string $dir, string $phar): string
    {
        Files::ensureDir($dir.'/public');
        $router = $dir.'/router.php';
        Files::write($router, "<?php\n\n// Written by `pablo web`: php -S cannot route into a phar by itself.\nreturn require ".var_export('phar://'.$phar.'/'.self::FRONT_CONTROLLER, true).";\n");

        return $router;
    }

    /**
     * ~/.pablo/cache/container/<version>/web-<hash>: next to the containers
     * of the same version, so it is pruned with them.
     */
    public static function workDir(string $pharPath): string
    {
        return Kernel::binaryVersionDir().'/web-'.Kernel::binaryHash($pharPath);
    }

    /** @throws PabloError */
    private static function phpOnPath(): string
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ('' !== $dir && is_file($dir.'/php') && is_executable($dir.'/php')) {
                return $dir.'/php';
            }
        }

        throw new PabloError('this pablo binary has no PHP to run the dashboard with, and there is no php on your PATH: install a pablo release, or PHP');
    }
}
