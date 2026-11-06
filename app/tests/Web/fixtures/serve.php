<?php

declare(strict_types=1);

/*
 * Runs Pablo\Web\Server for ServerTest with a stub kernel that echoes what it
 * received, on a free port it prints as "PORT <n>".
 *
 * Usage: php serve.php <public-dir> <workers>
 */

use Pablo\Web\Server;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$kernel = new class implements HttpKernelInterface {
    private int $served = 0;

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        ++$this->served;
        if ('/crash' === $request->getPathInfo()) {
            exit(3); // a worker dying mid-request: the parent must replace it
        }
        if ('/boom' === $request->getPathInfo()) {
            throw new RuntimeException('boom');
        }

        $response = new JsonResponse([
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'request' => $request->request->all(),
            'cookies' => $request->cookies->all(),
            'content' => $request->getContent(),
            'host' => $request->getHttpHost(),
            'remote' => $request->getClientIp(),
            'header' => $request->headers->get('x-pablo-test'),
            'pid' => getmypid(),
            'served' => $this->served,
        ]);
        $response->headers->setCookie(new Symfony\Component\HttpFoundation\Cookie('seen', 'yes'));

        return $response;
    }
};

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];
$server = new Server(static fn (): HttpKernelInterface => $kernel, $arguments[1], (int) $arguments[2]);
$port = $server->listen(0);
fwrite(\STDOUT, "PORT {$port}\n");
$server->run();
