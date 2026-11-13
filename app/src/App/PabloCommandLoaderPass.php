<?php

declare(strict_types=1);

namespace Pablo\App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Builds bin/pablo's command loader from the `pablo.command` tag, so a run
 * instantiates only the command it executes (and its services), never the
 * other ~35 and their whole graph.
 *
 * Each command is wrapped in a LazyCommand carrying its name, aliases, hidden
 * flag and description, read here from its #[AsCommand] attribute (required):
 * `pablo list`, `--help`, shell completion of command names and the
 * "did you mean" suggestions all work from that metadata alone.
 *
 * Not FrameworkBundle's `console.command_loader`: it holds every bundle's
 * commands (bin/console's), and it only wraps commands with a description,
 * so the hidden internal:* ones would still be built on every `pablo list`.
 *
 * Runs before removing, like AddConsoleCommandPass: the `pablo.command` tag
 * comes from an `instanceof` conditional, resolved during optimization.
 */
final class PabloCommandLoaderPass implements CompilerPassInterface
{
    public const TAG = 'pablo.command';

    public const LOADER_ID = 'pablo.command_loader';

    public function process(ContainerBuilder $container): void
    {
        $map = [];
        $lazyRefs = [];

        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $id) {
            $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass());
            $reflection = \is_string($class) ? $container->getReflectionClass($class) : null;
            if (null === $reflection || !$reflection->isSubclassOf(Command::class)) {
                throw new InvalidArgumentException(\sprintf('The service "%s" tagged "%s" must be a "%s".', $id, self::TAG, Command::class));
            }

            $attribute = ($reflection->getAttributes(AsCommand::class)[0] ?? null)?->newInstance();
            if (null === $attribute) {
                throw new InvalidArgumentException(\sprintf('The command "%s" must declare its name (and description, aliases, hidden flag) with #[%s]: bin/pablo lists commands without instantiating them.', $reflection->getName(), AsCommand::class));
            }

            // AsCommand folds aliases and the hidden flag into its name:
            // "name|alias…", or "|name|alias…" when hidden.
            $names = explode('|', $attribute->name);
            $hidden = '' === $names[0];
            if ($hidden) {
                array_shift($names);
            }
            // The container would resolve "%…%" in a string argument as a parameter.
            $names = array_map(static fn (string $name): string => str_replace('%', '%%', $name), $names);
            $name = array_shift($names);
            if (null === $name || '' === $name) {
                throw new InvalidArgumentException(\sprintf('The command "%s" has an empty #[%s] name.', $reflection->getName(), AsCommand::class));
            }

            $lazyId = '.'.self::LOADER_ID.'.'.$id;
            $container->register($lazyId, LazyCommand::class)
                ->setArguments([
                    $name,
                    $names,
                    str_replace('%', '%%', $attribute->description ?? ''),
                    $hidden,
                    new ServiceClosureArgument(new Reference($id)),
                ]);
            $lazyRefs[$lazyId] = new Reference($lazyId);

            foreach ([$name, ...$names] as $commandName) {
                if (isset($map[$commandName])) {
                    throw new InvalidArgumentException(\sprintf('The command name "%s" is declared twice ("%s" and "%s").', $commandName, $map[$commandName], $lazyId));
                }
                $map[$commandName] = $lazyId;
            }
        }

        $container->register(self::LOADER_ID, ContainerCommandLoader::class)
            ->setArguments([ServiceLocatorTagPass::register($container, $lazyRefs), $map]);

        // By index: named arguments were already resolved during optimization.
        $container->getDefinition(ConsoleApplication::class)
            ->setArgument(0, new Reference(self::LOADER_ID));
    }
}
