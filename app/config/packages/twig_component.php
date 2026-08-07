<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('twig_component', [
        'anonymous_template_directory' => 'components/',
        'defaults' => [
            'Pablo\Twig\Components\\' => 'components/',
        ],
    ]);
};
