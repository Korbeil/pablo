<?php

declare(strict_types=1);

namespace Pablo\App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * The single PABLO kernel, shared by every entry point: bin/pablo (the
 * user-facing CLI), bin/console (framework maintenance commands) and
 * public/index.php (the dashboard).
 *
 * The project dir is app/, so config/, templates/, assets/, public/ and var/
 * all live beside src/. The compiled container is cached under
 * var/cache/<env> — which is why no PABLO_* environment variable may ever be
 * resolved at container compile time (see config/services.php).
 *
 * MicroKernelTrait's own configureContainer()/configureRoutes() defaults
 * already import config/{packages}/*, config/services.php and
 * config/routes.php, so neither is overridden here.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir().'/var/log';
    }
}
