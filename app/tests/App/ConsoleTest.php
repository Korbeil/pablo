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
    private function app(): ConsoleApplication
    {
        $app = Kernel::build()->get(ConsoleApplication::class);
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
            'start', 'sync', 'rebase-log', 'issues', 'tasks', 'slack', 'projects',
            'doctor', 'dispatch', 'poll', 'docs', 'state', 'relaunch', 'waiting',
            'skip-ci', 'retrigger-ci', 'close', 'precommit-check', 'task',
        ] as $name) {
            $this->assertContains($name, $names, "missing command {$name}");
        }
    }

    public function testProjectsExitsZero(): void
    {
        $tester = new CommandTester($this->app()->find('projects'));
        $tester->execute([]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testPrecommitCheckOutsideWorktreeIsInvalid(): void
    {
        $tester = new CommandTester($this->app()->find('precommit-check'));
        $tester->execute([]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
