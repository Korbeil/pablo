<?php

declare(strict_types=1);

namespace Pablo\App;

use Pablo\Support\PabloPaths;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * The single PABLO kernel, shared by every entry point: bin/pablo (the
 * user-facing CLI), bin/console (framework maintenance commands) and the
 * dashboard (`pablo web`: php -S, see Pablo\Web\PhpServer).
 *
 * The project dir is app/, so config/, templates/ and var/ resolve under it;
 * MicroKernelTrait's default configureContainer()/configureRoutes() are used
 * as-is.
 *
 * Cache and logs depend on how PABLO runs:
 *  - from a repository checkout, app/var/cache/<env> and app/var/log, as
 *    before (app/var/cache/test-<n> for paratest's worker n, see
 *    testWorkerSuffix());
 *  - from the standalone binary (a phar), the phar is read-only, so the
 *    compiled container goes to ~/.pablo/cache/container/<version>/<env>-<hash>
 *    and logs to ~/.pablo/logs. The version segment means a new binary never
 *    reuses an older container (which is what lets the binary default to the
 *    `prod` env, whose container is never checked for freshness); the hash of
 *    the binary's path and mtime keeps apart two copies of the binary (a
 *    compiled container embeds the absolute phar:// paths of the binary that
 *    warmed it) and two local builds of the same commit.
 *    HOME is resolved here, at runtime — never as a container parameter.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    protected function build(ContainerBuilder $container): void
    {
        // bin/pablo's lazy command loader (see the pass for why it runs then).
        $container->addCompilerPass(new PabloCommandLoaderPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    public function getCacheDir(): string
    {
        if (PabloPaths::isPhar()) {
            return self::binaryVersionDir().'/'.$this->environment.'-'.self::binaryHash(PabloPaths::pharPath());
        }

        return $this->getProjectDir().'/var/cache/'.$this->environment.self::testWorkerSuffix($this->environment);
    }

    /**
     * `-<n>` for the test env under a parallel test runner (paratest sets
     * TEST_TOKEN to the worker's number), else nothing: each worker — and every
     * `bin/pablo` it spawns with PABLO_ENV=test, which inherits the variable —
     * then compiles and checks its own container instead of racing the others
     * over a shared var/cache/test. Read at runtime, like everything else here.
     */
    public static function testWorkerSuffix(string $environment): string
    {
        $token = getenv('TEST_TOKEN');
        if ('test' !== $environment || false === $token || 1 !== preg_match('/^\d+$/', $token)) {
            return '';
        }

        return '-'.$token;
    }

    public function getLogDir(): string
    {
        if (PabloPaths::isPhar()) {
            return PabloPaths::pabloDir().'/logs';
        }

        return $this->getProjectDir().'/var/log';
    }

    /** ~/.pablo/cache/container: every container a binary compiled, one dir per version. */
    public static function binaryCacheRoot(): string
    {
        return PabloPaths::pabloDir().'/cache/container';
    }

    /** ~/.pablo/cache/container/<version>: everything this version of the binary caches. */
    public static function binaryVersionDir(): string
    {
        return self::binaryCacheRoot().'/'.ConsoleApplication::version();
    }

    /** Tells two copies (or two local builds of one commit) of the binary apart. */
    public static function binaryHash(string $pharPath): string
    {
        return substr(hash('sha256', $pharPath.'|'.(int) @filemtime($pharPath)), 0, 12);
    }

    /**
     * The env an entry point uses when PABLO_ENV is unset: `prod` for the
     * binary (its container dir is versioned, so it can never go stale), `dev`
     * for a checkout (a `git pull` must recompile the container).
     */
    public static function defaultEnvironment(): string
    {
        $env = getenv('PABLO_ENV');
        if (false !== $env && '' !== $env) {
            return $env;
        }

        return PabloPaths::isPhar() ? 'prod' : 'dev';
    }
}
