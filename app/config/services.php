<?php

declare(strict_types=1);

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\Agents;
use Pablo\App\ConsoleApplication;
use Pablo\Dispatch\Dispatch;
use Pablo\Store\Store;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $params = $container->parameters();
    $params->set('pablo.state_dir', Store::defaultRoot());
    $params->set('pablo.shim', Dispatch::shimPath());

    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $services->set(Store::class)
        ->arg('$root', '%pablo.state_dir%');

    $services->set(Agents::class)
        ->arg('$shimPath', '%pablo.shim%');

    $services->alias(AgentLauncherInterface::class, Agents::class);

    $services->load('Pablo\\Command\\', '../src/Command')
        ->tag('console.command');

    $services->set(ConsoleApplication::class)
        ->args([tagged_iterator('console.command')]);
};
