<?php

declare(strict_types=1);

use Pablo\App\Kernel;
use Symfony\Component\HttpFoundation\Request;

/*
 * Front controller for the PABLO dashboard under a regular PHP web server.
 *
 * Not the usual path any more: `pablo web` serves the dashboard with its own
 * server (Pablo\Web\Server), which boots the kernel directly and never reads
 * this file. Kept so the dashboard can still be served by any PHP SAPI from a
 * repository checkout, e.g. php -S 127.0.0.1:8321 -t app/public app/public/index.php
 */

require dirname(__DIR__).'/vendor/autoload.php';

// Under the built-in server, let PHP serve real files itself (compiled assets,
// favicons) and route only the rest through the kernel.
if ('cli-server' === \PHP_SAPI) {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH);
    if ('' !== $path && '/' !== $path && is_file(__DIR__.$path)) {
        return false;
    }
}

$env = Kernel::defaultEnvironment();
$kernel = new Kernel($env, 'prod' !== $env);

$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
