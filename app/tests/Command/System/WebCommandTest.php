<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Command\System\WebCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Argument assembly only — this must never actually start a server.
 */
final class WebCommandTest extends TestCase
{
    public function testServerCommandBindsLoopbackAndTheAppPublicDir(): void
    {
        $argv = WebCommand::serverCommand(9000);

        $this->assertSame(\PHP_BINARY, $argv[0]);
        $this->assertSame('-S', $argv[1]);
        $this->assertSame('127.0.0.1:9000', $argv[2]);
        $this->assertSame('-t', $argv[3]);
        $this->assertSame(realpath(\dirname(__DIR__, 3).'/public'), realpath($argv[4]));
        $this->assertSame(realpath(\dirname(__DIR__, 3).'/public/index.php'), realpath($argv[5]));
    }

    /** The dashboard has no auth; binding beyond loopback must not be possible. */
    public function testNeverBindsBeyondLoopback(): void
    {
        foreach ([80, 8321, 65535] as $port) {
            $this->assertSame('127.0.0.1:'.$port, WebCommand::serverCommand($port)[2]);
        }
    }

    public function testFrontControllerExists(): void
    {
        $this->assertFileExists(WebCommand::serverCommand(1)[5]);
    }

    /** @return iterable<string, array{string}> */
    public static function badPorts(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'too high' => ['65536'];
        yield 'not a number' => ['http'];
        yield 'empty' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPorts')]
    public function testRejectsInvalidPorts(string $port): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['--port' => $port]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('invalid --port', $tester->getDisplay());
    }

    private function command(): WebCommand
    {
        $command = new WebCommand();
        $application = new Application();
        $application->addCommand($command);

        return $command;
    }
}
