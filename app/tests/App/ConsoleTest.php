<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\App\ConsoleApplication;
use Pablo\App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleTest extends TestCase
{
    use RestoresErrorHandlers;

    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        $this->restoreErrorHandlers();
    }

    private function app(): ConsoleApplication
    {
        $this->snapshotErrorHandlers();

        // debug:false — this asserts command wiring, not the debug toolchain.
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();

        $app = $this->kernel->getContainer()->get(ConsoleApplication::class);
        if (!$app instanceof ConsoleApplication) {
            throw new \RuntimeException('Container did not return a ConsoleApplication');
        }
        $app->setAutoExit(false);

        return $app;
    }

    public function testRegistersAllCommands(): void
    {
        $app = $this->app();
        $names = array_keys($app->all());
        foreach ([
            'task:start', 'sync:run', 'sync:log', 'show:issues', 'show:tasks', 'show:prs', 'show:projects',
            'system:doctor', 'system:dispatch', 'system:poll', 'show:docs', 'task:state', 'task:relaunch', 'task:waiting',
            'task:skip-ci', 'task:retrigger-ci', 'task:close', 'task:precommit-check', 'task:info',
        ] as $name) {
            $this->assertContains($name, $names, "missing command {$name}");
        }
    }

    public function testProjectsExitsZero(): void
    {
        $tester = new CommandTester($this->app()->find('show:projects'));
        $tester->execute([]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testPrecommitCheckOutsideWorktreeIsInvalid(): void
    {
        $tester = new CommandTester($this->app()->find('task:precommit-check'));
        $tester->execute([]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
