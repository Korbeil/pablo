<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('ux_icons', [
        'icon_dir' => '%kernel.project_dir%/assets/icons',

        // On-demand downloads are off: PABLO is driven by a systemd timer and
        // must never need network at render time. Icons are imported once with
        // `bin/console ux:icons:import` and committed under assets/icons/.
        'iconify' => ['on_demand' => false],
    ]);
};
