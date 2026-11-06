<?php

declare(strict_types=1);

namespace Pablo\Web;

use Pablo\Support\PabloError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * The dashboard's HTTP server, in plain PHP: `pablo web` runs it from a
 * repository checkout and from the standalone binary alike (a static PHP
 * binary has no `php -S`).
 *
 * Deliberately small, for one local user:
 *  - listens on 127.0.0.1 only — the dashboard has no authentication;
 *  - prefork: WORKERS processes accept() on the same socket, each boots the
 *    kernel once and then handles requests one at a time (handle(), then
 *    terminate(); the kernel resets its services — kernel.reset — before the
 *    next one). The parent restarts a worker that dies and passes
 *    SIGTERM/SIGINT on; a worker whose parent was killed outright leaves on
 *    its own. Without pcntl it serves from a single process;
 *  - HTTP/1.0 and 1.1 requests with a Content-Length body; every response is
 *    sent whole with `Connection: close`: no keep-alive, no chunked request
 *    bodies, no TLS;
 *  - files under the public dir (the compiled assets) are served as-is,
 *    everything else goes to the kernel.
 */
final class Server
{
    public const HOST = '127.0.0.1';

    public const WORKERS = 4;

    private const MAX_HEAD_BYTES = 65_536;

    private const MAX_BODY_BYTES = 16_777_216;

    /** Seconds a client may take to send its request. */
    private const READ_TIMEOUT_S = 30;

    /** How often an idle worker checks whether it was asked to stop. */
    private const ACCEPT_TIMEOUT_S = 1.0;

    private const CONTENT_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
    ];

    /** @var resource|null */
    private $socket;

    private int $port = 0;

    private bool $stopping = false;

    /** The supervising process, which a worker must not outlive. */
    private int $parentPid = 0;

    /** @var array<int, float> worker pid => start time */
    private array $workers = [];

    /** @var \Closure(string): void */
    private readonly \Closure $log;

    /**
     * @param \Closure(): HttpKernelInterface $kernelFactory called once per worker, after the fork
     * @param string                          $publicDir     served as static files (the compiled assets)
     * @param (\Closure(string): void)|null   $log           access and worker lines; default: none
     */
    public function __construct(
        private readonly \Closure $kernelFactory,
        private readonly string $publicDir,
        private readonly int $workerCount = self::WORKERS,
        ?\Closure $log = null,
    ) {
        $this->log = $log ?? static function (string $line): void {};
    }

    /**
     * Bind 127.0.0.1:$port (0 picks a free port) and return the bound port.
     *
     * @throws PabloError when the port cannot be bound (already in use, ...)
     */
    public function listen(int $port): int
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_server('tcp://'.self::HOST.':'.$port, $errno, $errstr);
        if (false === $socket) {
            throw new PabloError('cannot listen on '.self::HOST.":{$port}: {$errstr}");
        }
        // Non-blocking: when several workers wake up for one connection, the
        // ones that lose the race get EAGAIN instead of blocking in accept().
        stream_set_blocking($socket, false);
        $this->socket = $socket;

        $name = (string) stream_socket_get_name($socket, false);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);

        return $this->port;
    }

    /** Serve until SIGTERM/SIGINT. */
    public function run(): void
    {
        if (null === $this->socket) {
            throw new PabloError('call listen() before run()');
        }

        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            $this->work();

            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stopping = true;
            foreach (array_keys($this->workers) as $pid) {
                posix_kill($pid, \SIGTERM);
            }
        };
        // No SA_RESTART: pcntl_wait() must return on a signal, or the handler
        // (run between two VM instructions) would wait for a worker to die.
        pcntl_signal(\SIGTERM, $stop, false);
        pcntl_signal(\SIGINT, $stop, false);

        for ($i = 0; $i < max(1, $this->workerCount); ++$i) {
            $this->spawn();
        }

        while ([] !== $this->workers) {
            $status = 0;
            $pid = pcntl_wait($status);
            if ($pid <= 0) {
                if (\PCNTL_ECHILD === pcntl_get_last_error()) {
                    break;
                }
                continue; // interrupted by a signal
            }
            $startedAt = $this->workers[$pid] ?? microtime(true);
            unset($this->workers[$pid]);
            if ($this->stopping) {
                continue;
            }
            ($this->log)("worker {$pid} exited (status {$status}), starting a new one");
            // A worker that cannot even boot would otherwise respawn in a hot loop.
            if (microtime(true) - $startedAt < 1.0) {
                sleep(1);
            }
            if (!$this->stopping) {
                $this->spawn();
            }
        }
    }

    private function spawn(): void
    {
        // A stop arriving between fork() and the bookkeeping below would miss
        // the new worker: hold the signals until its pid is recorded.
        pcntl_sigprocmask(\SIG_BLOCK, [\SIGTERM, \SIGINT]);
        $pid = pcntl_fork();
        if (-1 === $pid) {
            pcntl_sigprocmask(\SIG_UNBLOCK, [\SIGTERM, \SIGINT]);
            throw new PabloError('cannot fork a dashboard worker');
        }
        if (0 === $pid) {
            $this->workers = [];
            $this->parentPid = (int) posix_getppid();
            $code = 0;
            try {
                $this->work(); // installs its own handlers, then unblocks
            } catch (\Throwable $e) {
                ($this->log)('worker failed: '.$e->getMessage());
                $code = 1;
            }
            exit($code);
        }
        $this->workers[$pid] = microtime(true);
        pcntl_sigprocmask(\SIG_UNBLOCK, [\SIGTERM, \SIGINT]);
    }

    /** One worker: boot the kernel, then accept and serve until asked to stop. */
    private function work(): void
    {
        $socket = $this->socket;
        \assert(\is_resource($socket));

        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function (): void {
                $this->stopping = true;
            };
            pcntl_signal(\SIGTERM, $stop, false);
            pcntl_signal(\SIGINT, $stop, false);
            pcntl_sigprocmask(\SIG_UNBLOCK, [\SIGTERM, \SIGINT]);
        }

        $kernel = ($this->kernelFactory)();
        if ($kernel instanceof KernelInterface) {
            $kernel->boot();
        }

        while (!$this->stopping) {
            // A parent killed outright (SIGKILL) cannot pass the stop on:
            // re-parented workers notice and leave instead of lingering.
            if (0 !== $this->parentPid && posix_getppid() !== $this->parentPid) {
                break;
            }
            $peer = '';
            $connection = @stream_socket_accept($socket, self::ACCEPT_TIMEOUT_S, $peer);
            if (false === $connection) {
                continue;
            }
            try {
                $this->serve($connection, (string) $peer, $kernel);
            } finally {
                @fclose($connection);
            }
        }
    }

    /**
     * Read one request from $connection, answer it, and leave the connection
     * for the caller to close.
     *
     * @param resource $connection
     */
    public function serve($connection, string $peer, HttpKernelInterface $kernel): void
    {
        // BSD/macOS sockets inherit O_NONBLOCK from the listening socket.
        stream_set_blocking($connection, true);
        stream_set_timeout($connection, self::READ_TIMEOUT_S);

        try {
            $request = $this->readRequest($connection, $peer);
        } catch (BadRequest $e) {
            $this->write($connection, self::render(new Response($e->getMessage()."\n", $e->status, ['Content-Type' => 'text/plain; charset=utf-8']), false));

            return;
        }

        $response = $this->staticResponse($request);
        $fromKernel = null === $response;
        if (null === $response) {
            try {
                $response = $kernel->handle($request);
            } catch (\Throwable $e) {
                ($this->log)('kernel error: '.$e->getMessage());
                $response = new Response("Internal Server Error\n", 500, ['Content-Type' => 'text/plain; charset=utf-8']);
                $fromKernel = false;
            }
        }

        if ('HEAD' === $request->getMethod() && !$response->headers->has('Content-Length') && false !== ($content = $response->getContent())) {
            // prepare() empties a HEAD body and keeps only an explicit length.
            $response->headers->set('Content-Length', (string) \strlen($content));
        }
        $response->prepare($request);
        $this->write($connection, self::render($response, 'HEAD' !== $request->getMethod()));
        ($this->log)(\sprintf('%s [%d]: %s %s', $peer, $response->getStatusCode(), $request->getMethod(), $request->getRequestUri()));

        if ($fromKernel && $kernel instanceof TerminableInterface) {
            $kernel->terminate($request, $response);
        }
    }

    /**
     * @param resource $connection
     *
     * @throws BadRequest
     */
    private function readRequest($connection, string $peer): Request
    {
        $buffer = '';
        while (false === ($end = strpos($buffer, "\r\n\r\n"))) {
            if (\strlen($buffer) > self::MAX_HEAD_BYTES) {
                throw new BadRequest('Request Header Fields Too Large', 431);
            }
            $chunk = fread($connection, 8192);
            if (false === $chunk || '' === $chunk) {
                if (feof($connection) || stream_get_meta_data($connection)['timed_out']) {
                    throw new BadRequest('Bad Request: incomplete request');
                }
                continue;
            }
            $buffer .= $chunk;
        }

        $head = substr($buffer, 0, $end);
        $body = substr($buffer, $end + 4);

        $lines = explode("\r\n", $head);
        $requestLine = array_shift($lines);
        if (1 !== preg_match('#^([A-Z]+) (\S+) HTTP/(1\.[01])$#', $requestLine, $m)) {
            throw new BadRequest('Bad Request: malformed request line');
        }
        [, $method, $uri, $protocol] = $m;
        if (!str_starts_with($uri, '/')) {
            throw new BadRequest('Bad Request: only origin-form request targets are supported');
        }

        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if (false === $colon || 0 === $colon) {
                throw new BadRequest('Bad Request: malformed header');
            }
            $headers[strtolower(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
        }

        if (isset($headers['transfer-encoding'])) {
            throw new BadRequest('Length Required: chunked request bodies are not supported', 411);
        }
        $length = 0;
        if (isset($headers['content-length'])) {
            if (1 !== preg_match('/^\d+$/', $headers['content-length'])) {
                throw new BadRequest('Bad Request: invalid Content-Length');
            }
            $length = (int) $headers['content-length'];
        }
        if ($length > self::MAX_BODY_BYTES) {
            throw new BadRequest('Content Too Large', 413);
        }
        while (($missing = $length - \strlen($body)) > 0) {
            $chunk = fread($connection, min(65_536, $missing));
            if (false === $chunk || '' === $chunk) {
                if (feof($connection) || stream_get_meta_data($connection)['timed_out']) {
                    throw new BadRequest('Bad Request: incomplete body');
                }
                continue;
            }
            $body .= $chunk;
        }
        $body = substr($body, 0, $length);

        return $this->createRequest($method, $uri, $protocol, $headers, $body, $peer);
    }

    /**
     * @param array<string, string> $headers lower-cased names
     */
    private function createRequest(string $method, string $uri, string $protocol, array $headers, string $body, string $peer): Request
    {
        $remotePort = '0';
        $remoteAddr = self::HOST;
        if ('' !== $peer && false !== ($colon = strrpos($peer, ':'))) {
            $remoteAddr = substr($peer, 0, $colon);
            $remotePort = substr($peer, $colon + 1);
        }

        $server = [
            'SERVER_PROTOCOL' => 'HTTP/'.$protocol,
            'SERVER_NAME' => self::HOST,
            'SERVER_ADDR' => self::HOST,
            'SERVER_PORT' => (string) $this->port,
            'REMOTE_ADDR' => $remoteAddr,
            'REMOTE_PORT' => $remotePort,
            'HTTP_HOST' => self::HOST.':'.$this->port,
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
        ];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            if ('CONTENT_TYPE' === $key || 'CONTENT_LENGTH' === $key) {
                $server[$key] = $value;
            } else {
                $server['HTTP_'.$key] = $value;
            }
        }

        $cookies = [];
        foreach (explode(';', $headers['cookie'] ?? '') as $pair) {
            $pair = trim($pair);
            if ('' === $pair || !str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $cookies[urldecode($name)] = urldecode($value);
        }

        $parameters = [];
        $contentType = strtolower($headers['content-type'] ?? '');
        if ('GET' !== $method && 'HEAD' !== $method && '' !== $body) {
            if (str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
                parse_str($body, $parameters);
            } elseif (str_starts_with($contentType, 'multipart/form-data')
                && 1 === preg_match('/boundary="?([^";]+)"?/i', $headers['content-type'] ?? '', $boundary)) {
                $parameters = self::parseMultipart($body, $boundary[1]);
            }
        }

        /* @var array<string, mixed> $parameters */
        return Request::create($uri, $method, $parameters, $cookies, [], $server, $body);
    }

    /**
     * The form fields of a multipart/form-data body (how LiveComponent posts
     * its actions). File parts are dropped: the dashboard takes no uploads.
     *
     * @return array<array-key, mixed>
     */
    public static function parseMultipart(string $body, string $boundary): array
    {
        $pairs = [];
        foreach (explode('--'.$boundary, $body) as $part) {
            if ('' === trim($part) || str_starts_with($part, '--')) {
                continue;
            }
            $part = (string) preg_replace('/^\r\n/', '', $part);
            $split = strpos($part, "\r\n\r\n");
            if (false === $split) {
                continue;
            }
            $partHead = substr($part, 0, $split);
            $value = substr($part, $split + 4);
            if (str_ends_with($value, "\r\n")) {
                $value = substr($value, 0, -2);
            }
            if (1 !== preg_match('/^content-disposition:\s*form-data;(.*)$/mi', $partHead, $disposition)
                || 1 !== preg_match('/\bname="([^"]*)"/i', $disposition[1], $name)
                || 1 === preg_match('/\bfilename="/i', $disposition[1])) {
                continue;
            }
            $pairs[] = rawurlencode($name[1]).'='.rawurlencode($value);
        }

        $fields = [];
        parse_str(implode('&', $pairs), $fields);

        return $fields;
    }

    /** A file from the public dir (the compiled assets), or null for the kernel. */
    private function staticResponse(Request $request): ?Response
    {
        if (!\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }
        $path = rawurldecode($request->getPathInfo());
        if ('/' === $path || str_contains($path, '..') || str_contains($path, "\0") || str_ends_with($path, '.php')) {
            return null;
        }
        $file = rtrim($this->publicDir, '/').$path;
        if (!is_file($file)) {
            return null;
        }
        $content = @file_get_contents($file);
        if (false === $content) {
            return null;
        }

        $extension = strtolower(pathinfo($file, \PATHINFO_EXTENSION));
        $headers = ['Content-Type' => self::CONTENT_TYPES[$extension] ?? 'application/octet-stream'];
        // Compiled asset file names carry a content hash.
        $headers['Cache-Control'] = str_starts_with($path, '/assets/') ? 'public, max-age=31536000, immutable' : 'no-cache';

        return new Response($content, 200, $headers);
    }

    /** The full HTTP/1.1 response: status line, headers, then the body unless $withBody is false (HEAD). */
    public static function render(Response $response, bool $withBody = true): string
    {
        ob_start();
        try {
            $response->sendContent();
        } finally {
            $body = (string) ob_get_clean();
        }

        $status = $response->getStatusCode();
        $lines = [\sprintf('HTTP/1.1 %d %s', $status, Response::$statusTexts[$status] ?? '')];
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            if (\in_array(strtolower($name), ['connection', 'keep-alive', 'transfer-encoding', 'content-length'], true)) {
                continue;
            }
            foreach ($values as $value) {
                $lines[] = $name.': '.$value;
            }
        }
        foreach ($response->headers->getCookies() as $cookie) {
            $lines[] = 'Set-Cookie: '.$cookie;
        }
        // HEAD: Response::prepare() emptied the body but kept its length.
        $length = $withBody ? \strlen($body) : (int) ($response->headers->get('Content-Length') ?? \strlen($body));
        $lines[] = 'Content-Length: '.$length;
        $lines[] = 'Connection: close';

        return implode("\r\n", $lines)."\r\n\r\n".($withBody ? $body : '');
    }

    /** @param resource $connection */
    private function write($connection, string $data): void
    {
        while ('' !== $data) {
            $written = @fwrite($connection, $data);
            if (false === $written || 0 === $written) {
                return; // client went away
            }
            $data = substr($data, $written);
        }
    }
}
