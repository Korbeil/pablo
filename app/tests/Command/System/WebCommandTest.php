<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Command\System\WebCommand;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Store\Store;
use Pablo\Tests\FakeAgents;
use Pablo\Web\PhpServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * `pablo web`: option checks in-process, then the real command started on a
 * free port against a throwaway ~/.pablo, spoken to over HTTP.
 */
final class WebCommandTest extends TestCase
{
    private ?string $tmp = null;
    private ?Process $process = null;

    protected function tearDown(): void
    {
        if (null !== $this->process && $this->process->isRunning()) {
            $this->process->stop(5);
        }
        if (null !== $this->tmp) {
            exec('rm -rf '.escapeshellarg($this->tmp));
        }
    }

    /** The dashboard has no auth; binding beyond loopback must not be possible. */
    public function testNeverBindsBeyondLoopback(): void
    {
        $this->assertSame('127.0.0.1', WebCommand::HOST);
        foreach ([80, 8321, 65535] as $port) {
            $this->assertSame('127.0.0.1:'.$port, (new PhpServer())->command($port)[2]);
        }
    }

    /** From a checkout: the PHP running PABLO, serving app/public through its front controller. */
    public function testFromACheckoutItRunsThePhpBuiltInServerOnAppPublic(): void
    {
        $command = (new PhpServer())->command(9000);

        $this->assertSame([\PHP_BINARY, '-S', '127.0.0.1:9000', '-t'], \array_slice($command, 0, 4));
        $this->assertSame(realpath(\dirname(__DIR__, 3).'/public'), realpath($command[4]));
        $this->assertSame(realpath(\dirname(__DIR__, 3).'/public/index.php'), realpath($command[5]));
        $this->assertSame(['PHP_CLI_SERVER_WORKERS' => '4', 'PABLO_ENV' => 'prod'], PhpServer::environment('prod'));
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

    #[DataProvider('badPorts')]
    public function testRejectsInvalidPorts(string $port): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['--port' => $port]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('invalid --port', $tester->getDisplay());
    }

    public function testAnInvalidDashboardPortInTheConfigIsReported(): void
    {
        $previous = getenv('PABLO_CONFIG');
        $config = sys_get_temp_dir().'/pablo-web-config-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($config, "dashboard_port: nope\n");
        putenv('PABLO_CONFIG='.$config);

        try {
            $tester = new CommandTester($this->command());
            $tester->execute([]);
        } finally {
            putenv(false === $previous ? 'PABLO_CONFIG' : 'PABLO_CONFIG='.$previous);
            @unlink($config);
        }

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('dashboard_port must be a port number', $tester->getDisplay());
    }

    public function testServesTheDashboardAndItsAssetsWithoutCallingExternalTools(): void
    {
        $port = $this->start();

        [$status, $headers, $page] = self::get($port, '/');
        $this->assertSame(200, $status, $page);
        $this->assertStringContainsString('text/html', $headers['content-type'] ?? '');
        $this->assertStringContainsString('PABLO', $page);

        if (1 !== preg_match('#href="(/assets/[^"]+\.css)"#', $page, $css)) {
            $this->fail('no stylesheet link in the page');
        }
        [$status, $headers] = self::get($port, $css[1]);
        $this->assertSame(200, $status, $css[1]);
        $this->assertStringContainsString('text/css', $headers['content-type'] ?? '');

        [$status] = self::get($port, '/no-such-page');
        $this->assertSame(404, $status);

        // A second request reuses a booted worker and still renders.
        $this->assertSame(200, self::get($port, '/')[0]);

        $this->assertFileDoesNotExist($this->tmp.'/external-tool-called', 'the dashboard must never run gh/orca/a tracker CLI to render');
    }

    private function start(): int
    {
        $this->tmp = sys_get_temp_dir().'/pablo-web-cmd-'.bin2hex(random_bytes(4));
        foreach (['home/.pablo/projects', 'home/.pablo/stamps', 'home/.pablo/logs', 'home/.pablo/state', 'bin'] as $dir) {
            mkdir($this->tmp.'/'.$dir, 0o777, true);
        }
        foreach (['gh', 'orca', 'openchamber', 'acli', 'linear', 'opencode', 'claude', 'copilot'] as $tool) {
            file_put_contents($this->tmp.'/bin/'.$tool, "#!/bin/sh\necho \"\$0 \$*\" >> '{$this->tmp}/external-tool-called'\nexit 1\n");
            chmod($this->tmp.'/bin/'.$tool, 0o755);
        }

        // A free port: bind one, release it, hand it over.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);

        $home = $this->tmp.'/home';
        // No --port: `pablo web` takes dashboard_port, as the service does.
        file_put_contents($home.'/.pablo/config.yaml', "dashboard_port: {$port}\n");
        $this->process = new Process([\PHP_BINARY, \dirname(__DIR__, 3).'/bin/pablo', 'web'], null, [
            'HOME' => $home,
            'PATH' => $this->tmp.'/bin:/usr/bin:/bin',
            'PABLO_ENV' => 'test',
            'PABLO_STATE_DIR' => $home.'/.pablo/state',
            'PABLO_PROJECTS_DIR' => $home.'/.pablo/projects',
            'PABLO_STAMPS_DIR' => $home.'/.pablo/stamps',
            'PABLO_LOGS_DIR' => $home.'/.pablo/logs',
            'PABLO_CONFIG' => $home.'/.pablo/config.yaml',
            'PABLO_AGENT_RUNTIME' => false,
            'PABLO_AGENT_BACKEND' => false,
        ]);
        $this->process->setTimeout(120);
        $this->process->start();
        $this->process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ctrl-c'));
        // `pablo web` prints its banner before `php -S` is listening.
        $deadline = microtime(true) + 20;
        while (false === ($probe = @stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $errstr, 1))) {
            $this->assertTrue($this->process->isRunning(), $this->process->getOutput().$this->process->getErrorOutput());
            $this->assertLessThan($deadline, microtime(true), 'php -S never started listening');
            usleep(100_000);
        }
        fclose($probe);

        return $port;
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    private static function get(int $port, string $path): array
    {
        $client = stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $errstr, 10);
        self::assertNotFalse($client, $errstr ?? 'cannot connect');
        stream_set_timeout($client, 60);
        fwrite($client, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nAccept: text/html\r\n\r\n");
        $response = (string) stream_get_contents($client);
        fclose($client);

        [$head, $body] = explode("\r\n\r\n", $response, 2) + [1 => ''];
        $lines = explode("\r\n", $head);
        $status = (int) (explode(' ', (string) array_shift($lines))[1] ?? 0);
        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }
        }

        return [$status, $headers, $body];
    }

    private function command(): WebCommand
    {
        $command = new WebCommand(new Store(), new Config(new GlobalConfig()), new AgentLauncherFactory(new GlobalConfig()), new FakeAgents());
        (new Application())->addCommand($command);

        return $command;
    }
}
