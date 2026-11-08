<?php

declare(strict_types=1);

namespace Pablo\Tests\Web;

use Pablo\Support\PabloError;
use Pablo\Web\PhpServer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * What `pablo web` extracts from the standalone binary to run `php -S`: the
 * phar appended after the micro prefix, the PHP CLI it carries, and the
 * router — exercised with a real phar behind a fake prefix, served by a real
 * `php -S`.
 */
final class PhpServerTest extends TestCase
{
    private string $tmp;
    private ?Process $server = null;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-phpserver-'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (null !== $this->server && $this->server->isRunning()) {
            $this->server->stop(5);
        }
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    public function testThePharAfterThePrefixIsAReadablePharAndIsExtractedOnce(): void
    {
        [$binary, $offset] = $this->fakeBinary();

        $phar = PhpServer::extractPhar($binary, $offset, $this->tmp.'/work/pablo.phar');
        $mtime = filemtime($phar);
        clearstatcache();
        PhpServer::extractPhar($binary, $offset, $phar);

        $this->assertSame('front controller', $this->runPhp("echo file_get_contents('phar://{$phar}/app/public/marker.txt');"));
        $this->assertSame($mtime, filemtime($phar), 'an identical copy is kept');
    }

    public function testAMissingPharIsAClearError(): void
    {
        file_put_contents($this->tmp.'/binary', 'micro only');

        $this->expectException(PabloError::class);
        PhpServer::extractPhar($this->tmp.'/binary', 100, $this->tmp.'/pablo.phar');
    }

    /** Read through micro's hook, the binary starts with the phar stub; read raw, with the executable. */
    public function testThePharOffsetDependsOnWhatTheBinaryReadsAs(): void
    {
        file_put_contents($this->tmp.'/hooked', "#!/usr/bin/env php\n<?php __HALT_COMPILER();");
        file_put_contents($this->tmp.'/raw', "\x7fELF....#!/usr/bin/env php");

        $this->assertSame(0, PhpServer::pharOffset($this->tmp.'/hooked', 1234));
        $this->assertSame(1234, PhpServer::pharOffset($this->tmp.'/raw', 1234));
    }

    public function testTheEmbeddedPhpIsExtractedExecutableOrNullWhenThereIsNone(): void
    {
        file_put_contents($this->tmp.'/php-source', "#!/bin/sh\necho php\n");

        $php = PhpServer::extractPhp($this->tmp.'/php-source', $this->tmp.'/work/php');

        $this->assertNotNull($php);
        $this->assertTrue(is_executable($php));
        $this->assertNull(PhpServer::extractPhp($this->tmp.'/none', $this->tmp.'/work/php2'));
    }

    /** The router serves the app and its assets from inside the phar, through a real php -S. */
    public function testTheRouterServesPagesAndAssetsFromThePhar(): void
    {
        [$binary, $offset] = $this->fakeBinary();
        $phar = PhpServer::extractPhar($binary, $offset, $this->tmp.'/work/pablo.phar');
        $router = PhpServer::writeRouter($this->tmp.'/work', $phar);

        $port = $this->freePort();
        $this->server = new Process([\PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $this->tmp.'/work/public', $router], env: ['PHP_CLI_SERVER_WORKERS' => '2']);
        $this->server->start();
        $this->server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'started'));

        $this->assertSame('page /tasks', (string) @file_get_contents("http://127.0.0.1:{$port}/tasks"));
        $this->assertSame('body{}', (string) @file_get_contents("http://127.0.0.1:{$port}/assets/app-abc.css"));
        $this->assertContains('Content-Type: text/css; charset=utf-8', http_get_last_response_headers() ?? []);
        $this->assertSame('page /pablo.phar', (string) @file_get_contents("http://127.0.0.1:{$port}/pablo.phar"), 'the extracted files are never served');
    }

    /**
     * A "binary": a prefix standing for micro.sfx, then a phar holding an
     * app/public/index.php that mimics the real one (assets from the phar,
     * everything else to the "kernel").
     *
     * @return array{string, int} path, phar offset
     */
    private function fakeBinary(): array
    {
        $index = <<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (is_file(__DIR__.$path) && !str_ends_with($path, '.php')) {
                header('Content-Type: text/css; charset=utf-8');
                readfile(__DIR__.$path);
                return true;
            }
            echo 'page '.$path;
            PHP;
        $build = '$p = new Phar($argv[1]); $p->addFromString("app/public/index.php", $argv[2]); '
            .'$p->addFromString("app/public/marker.txt", "front controller"); $p->addFromString("app/public/assets/app-abc.css", "body{}"); '
            .'$p->setStub("<?php __HALT_COMPILER();");';
        $pharFile = $this->tmp.'/real.phar';
        $process = new Process([\PHP_BINARY, '-d', 'phar.readonly=0', '-r', $build, $pharFile, $index]);
        $process->mustRun();

        $prefix = str_repeat("\x7fELF micro prefix\n", 64);
        file_put_contents($this->tmp.'/binary', $prefix.file_get_contents($pharFile));

        return [$this->tmp.'/binary', \strlen($prefix)];
    }

    private function runPhp(string $code): string
    {
        $process = new Process([\PHP_BINARY, '-r', $code]);
        $process->mustRun();

        return $process->getOutput();
    }

    private function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
