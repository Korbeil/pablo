<?php

declare(strict_types=1);

namespace Pablo\Tests\Web;

use Pablo\Web\Server;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

/**
 * Pablo\Web\Server started for real (fixtures/serve.php, a stub kernel that
 * echoes the request) on a free port, and spoken to over real sockets.
 */
#[RequiresFunction('pcntl_fork')]
final class ServerTest extends TestCase
{
    private string $public;
    private ?Process $process = null;
    private int $port = 0;

    protected function setUp(): void
    {
        $this->public = sys_get_temp_dir().'/pablo-server-'.bin2hex(random_bytes(4));
        mkdir($this->public.'/assets', 0o777, true);
        file_put_contents($this->public.'/assets/app-abc123.css', 'body{}');
        file_put_contents($this->public.'/index.php', '<?php echo "never served";');
        file_put_contents(\dirname($this->public).'/pablo-server-secret.txt', 'TOP-SECRET-CONTENT');
    }

    protected function tearDown(): void
    {
        if (null !== $this->process && $this->process->isRunning()) {
            $this->process->stop(5, \SIGKILL);
        }
        exec('rm -rf '.escapeshellarg($this->public));
        @unlink(\dirname($this->public).'/pablo-server-secret.txt');
    }

    public function testGetGoesToTheKernelWithItsQueryHeadersAndCookies(): void
    {
        $this->start();

        [$status, $headers, $body] = $this->request("GET /tasks?type=work HTTP/1.1\r\nHost: 127.0.0.1\r\nX-Pablo-Test: hello\r\nCookie: a=1; b=two%20words\r\n\r\n");

        $this->assertSame(200, $status);
        $this->assertSame('close', $headers['connection']);
        $this->assertSame((string) \strlen($body), $headers['content-length']);
        $this->assertStringContainsString('seen=yes', $headers['set-cookie']);
        $data = json_decode($body, true);
        $this->assertIsArray($data);
        $this->assertSame('GET', $data['method']);
        $this->assertSame('/tasks', $data['path']);
        $this->assertSame(['type' => 'work'], $data['query']);
        $this->assertSame(['a' => '1', 'b' => 'two words'], $data['cookies']);
        $this->assertSame('hello', $data['header']);
        $this->assertSame('127.0.0.1', $data['remote']);
    }

    public function testFormBodyIsParsed(): void
    {
        $this->start();
        $form = 'name=pablo&list%5B%5D=1&list%5B%5D=2';

        [$status, , $body] = $this->request("POST /submit HTTP/1.1\r\nHost: x\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: ".\strlen($form)."\r\n\r\n".$form);

        $this->assertSame(200, $status);
        $data = json_decode($body, true);
        $this->assertIsArray($data);
        $this->assertSame(['name' => 'pablo', 'list' => ['1', '2']], $data['request']);
        $this->assertSame($form, $data['content']);
    }

    public function testMultipartFieldsAreParsedLikeLiveComponentSendsThem(): void
    {
        $this->start();
        $multipart = "--XyZ\r\nContent-Disposition: form-data; name=\"data\"\r\n\r\n{\"props\":{\"open\":true}}\r\n"
            ."--XyZ\r\nContent-Disposition: form-data; name=\"updated[open]\"\r\n\r\n1\r\n"
            ."--XyZ\r\nContent-Disposition: form-data; name=\"upload\"; filename=\"a.txt\"\r\nContent-Type: text/plain\r\n\r\nfile\r\n"
            ."--XyZ--\r\n";

        [$status, , $body] = $this->request("POST /_components/x HTTP/1.1\r\nHost: x\r\nContent-Type: multipart/form-data; boundary=XyZ\r\nContent-Length: ".\strlen($multipart)."\r\n\r\n".$multipart);

        $this->assertSame(200, $status);
        $data = json_decode($body, true);
        $this->assertIsArray($data);
        $this->assertSame(['data' => '{"props":{"open":true}}', 'updated' => ['open' => '1']], $data['request']);
    }

    public function testCompiledAssetsAreServedFromThePublicDir(): void
    {
        $this->start();

        [$status, $headers, $body] = $this->request("GET /assets/app-abc123.css HTTP/1.1\r\nHost: x\r\n\r\n");

        $this->assertSame(200, $status);
        $this->assertSame('body{}', $body);
        $this->assertSame('text/css; charset=utf-8', $headers['content-type']);
        $this->assertStringContainsString('immutable', $headers['cache-control']);
    }

    public function testHeadSendsNoBody(): void
    {
        $this->start();

        [$status, $headers, $body] = $this->request("HEAD /assets/app-abc123.css HTTP/1.1\r\nHost: x\r\n\r\n");

        $this->assertSame(200, $status);
        $this->assertSame('', $body);
        $this->assertSame('6', $headers['content-length']);
    }

    public function testPhpFilesAndPathsOutsideThePublicDirGoToTheKernel(): void
    {
        $this->start();

        foreach (['/index.php', '/../pablo-server-secret.txt', '/assets/%2e%2e/%2e%2e/pablo-server-secret.txt'] as $path) {
            [$status, , $body] = $this->request("GET {$path} HTTP/1.1\r\nHost: x\r\n\r\n");

            $this->assertSame(200, $status, $path);
            $this->assertStringNotContainsString('never served', $body, $path);
            $this->assertStringNotContainsString('TOP-SECRET-CONTENT', $body, $path);
            $this->assertStringContainsString('"method":"GET"', $body, $path);
        }
    }

    public function testMalformedRequestsAreRefusedWithoutTheKernel(): void
    {
        $this->start();

        $this->assertSame(400, $this->request("NOT A REQUEST\r\n\r\n")[0]);
        $this->assertSame(400, $this->request("GET http://evil.example/ HTTP/1.1\r\nHost: x\r\n\r\n")[0]);
        $this->assertSame(411, $this->request("POST / HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n")[0]);
        $this->assertSame(400, $this->request("POST / HTTP/1.1\r\nHost: x\r\nContent-Length: abc\r\n\r\n")[0]);
    }

    public function testAKernelExceptionIsA500AndTheWorkerKeepsServing(): void
    {
        $this->start(1);

        $this->assertSame(500, $this->request("GET /boom HTTP/1.1\r\nHost: x\r\n\r\n")[0]);
        $this->assertSame(200, $this->request("GET / HTTP/1.1\r\nHost: x\r\n\r\n")[0]);
    }

    public function testAWorkerBootsOnceAndServesManyRequests(): void
    {
        $this->start(1);

        $first = json_decode($this->request("GET / HTTP/1.1\r\nHost: x\r\n\r\n")[2], true);
        $second = json_decode($this->request("GET / HTTP/1.1\r\nHost: x\r\n\r\n")[2], true);

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertSame($first['pid'], $second['pid']);
        $this->assertSame($first['served'] + 1, $second['served']);
    }

    public function testADeadWorkerIsReplaced(): void
    {
        $this->start(1);
        $before = json_decode($this->request("GET / HTTP/1.1\r\nHost: x\r\n\r\n")[2], true);

        $this->request("GET /crash HTTP/1.1\r\nHost: x\r\n\r\n");
        $after = json_decode($this->request("GET / HTTP/1.1\r\nHost: x\r\n\r\n")[2], true);

        $this->assertIsArray($before);
        $this->assertIsArray($after);
        $this->assertNotSame($before['pid'], $after['pid']);
        $this->assertTrue($this->process?->isRunning());
    }

    public function testSeveralWorkersServeConcurrently(): void
    {
        $this->start(4);

        $pids = [];
        for ($i = 0; $i < 40; ++$i) {
            $data = json_decode($this->request("GET / HTTP/1.1\r\nHost: x\r\n\r\n")[2], true);
            $this->assertIsArray($data);
            $pids[$data['pid']] = true;
        }

        $this->assertGreaterThanOrEqual(1, \count($pids));
        $this->assertLessThanOrEqual(4, \count($pids));
    }

    public function testSigtermStopsTheParentAndItsWorkers(): void
    {
        $this->start(3);
        $process = $this->process;
        \assert(null !== $process);

        $process->signal(\SIGTERM);
        $deadline = microtime(true) + 10;
        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertFalse($process->isRunning());
        $this->assertFalse(@stream_socket_client('tcp://127.0.0.1:'.$this->port, $errno, $errstr, 1));
    }

    public function testListensOnLoopbackOnly(): void
    {
        $server = new Server(static fn () => throw new \LogicException('never booted'), $this->public);
        $port = $server->listen(0);

        $this->assertGreaterThan(0, $port);
        $this->assertSame('127.0.0.1', Server::HOST);
    }

    public function testABusyPortIsAClearError(): void
    {
        $first = new Server(static fn () => throw new \LogicException('never booted'), $this->public);
        $port = $first->listen(0);

        $this->expectExceptionMessage("cannot listen on 127.0.0.1:{$port}");
        (new Server(static fn () => throw new \LogicException('never booted'), $this->public))->listen($port);
    }

    public function testRenderWritesAWholeResponse(): void
    {
        $raw = Server::render(new Response('hi', 201, ['X-A' => 'b']));

        $this->assertStringStartsWith("HTTP/1.1 201 Created\r\n", $raw);
        $this->assertStringContainsString("X-A: b\r\n", $raw);
        $this->assertStringContainsString("Content-Length: 2\r\nConnection: close\r\n\r\nhi", $raw);
    }

    private function start(int $workers = 2): void
    {
        $this->process = new Process([\PHP_BINARY, __DIR__.'/fixtures/serve.php', $this->public, (string) $workers]);
        $this->process->setTimeout(60);
        $this->process->start();
        $this->process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "\n"));
        if (1 !== preg_match('/PORT (\d+)/', $this->process->getOutput(), $m)) {
            $this->fail('the server printed no port: '.$this->process->getErrorOutput());
        }
        $this->port = (int) $m[1];
    }

    /**
     * @return array{int, array<string, string>, string} status, lower-cased headers, body
     */
    private function request(string $raw): array
    {
        $client = stream_socket_client('tcp://127.0.0.1:'.$this->port, $errno, $errstr, 5);
        $this->assertNotFalse($client, $errstr ?? 'cannot connect');
        stream_set_timeout($client, 10);
        fwrite($client, $raw);
        $response = (string) stream_get_contents($client);
        fclose($client);

        if ('' === $response) {
            return [0, [], ''];
        }
        [$head, $body] = explode("\r\n\r\n", $response, 2) + [1 => ''];
        $lines = explode("\r\n", $head);
        $status = (int) explode(' ', (string) array_shift($lines))[1];
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = trim($value);
        }

        return [$status, $headers, $body];
    }
}
