<?php

declare(strict_types=1);

namespace Pablo\Tests\App;

use Pablo\App\ConsoleApplication;
use Pablo\App\PabloCommandLoaderPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

final class PabloCommandLoaderPassTest extends TestCase
{
    public function testLoadsCommandsLazilyFromTheirAttribute(): void
    {
        $built = 0;
        $visible = new #[AsCommand(name: 'demo:run', description: '100% lazy', aliases: ['run'])] class extends Command {};
        $hidden = new #[AsCommand(name: 'internal:demo', hidden: true)] class extends Command {};

        $container = $this->container(['demo.visible' => $visible, 'demo.hidden' => $hidden], $built);
        $container->compile();

        $app = $container->get(ConsoleApplication::class);
        $this->assertInstanceOf(ConsoleApplication::class, $app);

        $run = $app->get('run');
        $this->assertInstanceOf(LazyCommand::class, $run);
        $this->assertSame('demo:run', $run->getName());
        $this->assertSame(['run'], $run->getAliases());
        $this->assertSame('100% lazy', $run->getDescription());
        $this->assertFalse($run->isHidden());
        $this->assertTrue($app->get('internal:demo')->isHidden());
        $this->assertSame(0, $built, 'listing must not build a command');

        $this->assertSame($visible::class, $run->getCommand()::class);
        $this->assertSame(1, $built);
    }

    public function testRefusesACommandWithoutAttribute(): void
    {
        $built = 0;
        $bare = new class('bare') extends Command {};

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#['.AsCommand::class.']');
        $this->container(['demo.bare' => $bare], $built)->compile();
    }

    public function testRefusesADuplicateName(): void
    {
        $built = 0;
        $one = new #[AsCommand(name: 'demo:one', aliases: ['demo'])] class extends Command {};
        $two = new #[AsCommand(name: 'demo')] class extends Command {};

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"demo" is declared twice');
        $this->container(['demo.one' => $one, 'demo.two' => $two], $built)->compile();
    }

    /**
     * @param array<string, Command> $commands by service id, built through a counting factory
     */
    private function container(array $commands, int &$built): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(ConsoleApplication::class, ConsoleApplication::class)
            ->setPublic(true)
            ->setArguments([null]);
        $container->register('factory', \Closure::class)->setSynthetic(true);
        foreach ($commands as $id => $command) {
            $container->register($id, $command::class)
                ->setFactory([new Reference('factory'), '__invoke'])
                ->setArguments([$id])
                ->addTag(PabloCommandLoaderPass::TAG);
        }
        $container->addCompilerPass(new PabloCommandLoaderPass());
        $container->set('factory', static function (string $id) use ($commands, &$built): Command {
            ++$built;

            return $commands[$id];
        });

        return $container;
    }
}
