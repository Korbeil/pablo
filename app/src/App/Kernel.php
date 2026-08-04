<?php

declare(strict_types=1);

namespace Pablo\App;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Builds and compiles the pablo service container.
 */
final class Kernel
{
    public static function build(): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $loader = new PhpFileLoader($builder, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');
        $builder->compile();

        return $builder;
    }
}
