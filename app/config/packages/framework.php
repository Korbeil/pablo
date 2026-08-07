<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Framework configuration for PABLO.
 *
 * Written as extension arrays rather than Symfony\Config\FrameworkConfig on
 * purpose: the config-builder classes are generated into var/cache/<env>, so
 * PHPStan would fail on a clean checkout that has not warmed the cache yet.
 */

return static function (ContainerConfigurator $container): void {
    // Only used to sign LiveComponent payloads. The dashboard binds to
    // 127.0.0.1 and stores nothing; override with PABLO_SECRET if you care.
    $container->parameters()->set('pablo.secret_fallback', 'pablo-local-dashboard');

    $container->extension('framework', [
        'secret' => '%env(default:pablo.secret_fallback:PABLO_SECRET)%',
        'session' => ['enabled' => false],
        'router' => ['utf8' => true],
        'asset_mapper' => [
            'paths' => ['assets/'],
            'missing_import_mode' => 'strict',
        ],

        // PABLO leans on non-fatal warnings throughout (fopen on a lock file,
        // glob on a missing dir, rename/unlink races between the dispatcher
        // and interactive commands). Symfony's ErrorHandler would otherwise
        // promote those to ErrorException in debug mode and change behaviour
        // that the engine deliberately depends on. PHPUnit installs its own
        // handler, so failOnWarning still catches them in tests.
        'php_errors' => ['log' => true, 'throw' => false],
    ]);
};
