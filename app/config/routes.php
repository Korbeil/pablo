<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Imported by MicroKernelTrait's default configureRoutes().
 *
 * The LiveComponent import is what Flex would have generated. Without it every
 * live-component interaction (polling, actions) 404s with no error server-side.
 */

return static function (RoutingConfigurator $routes): void {
    $routes->import(dirname(__DIR__).'/src/Controller/', 'attribute');

    $routes->import('@LiveComponentBundle/config/routes.php')
        ->prefix('/_components');
};
