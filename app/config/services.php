<?php

declare(strict_types=1);

use Pablo\Agents\AgentLauncher;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\App\ConsoleApplication;
use Pablo\Command\Command as PabloCommand;
use Pablo\Store\Store;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // ~/.pablo/state and the shim path are deliberately NOT container
    // parameters: the compiled container is cached under app/var/cache/<env>,
    // so a compile-time value would freeze PABLO_STATE_DIR / PABLO_SHIM / HOME
    // as they were on the process that first warmed the cache. Both
    // constructors resolve their own default at instantiation instead. The
    // agent backend is selected the same way, so the AgentLauncherInterface is
    // resolved through the factory (never a compile-time backend choice).
    $services->set(Store::class);

    $services->set(AgentLauncherInterface::class)
        ->factory([AgentLauncher::class, 'create'])
        ->public();

    $services->set(AgentLauncher::class);

    // `pablo.command`, not `console.command`: every bundle now contributes to
    // the latter, and bin/pablo must keep listing PABLO subcommands only.
    // Autoconfigure still adds `console.command` on top, so bin/console (the
    // framework maintenance CLI) sees them too.
    $services->instanceof(PabloCommand::class)
        ->tag('pablo.command');

    $services->load('Pablo\\Command\\', '../src/Command');

    // Dashboard (web) services. Autoconfigure tags controllers with
    // controller.service_arguments and Twig components via their attributes.
    $services->load('Pablo\\Controller\\', '../src/Controller');
    $services->load('Pablo\\Dashboard\\', '../src/Dashboard');
    $services->load('Pablo\\Twig\\', '../src/Twig');

    $services->set(ConsoleApplication::class)
        ->public()
        ->args([tagged_iterator('pablo.command')]);
};
