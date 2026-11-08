<?php

declare(strict_types=1);

use Pablo\App\Kernel;
use Symfony\Component\HttpFoundation\Request;

/*
 * Front controller for the PABLO dashboard.
 *
 * Served by `pablo web` (see Pablo\Web\PhpServer), which runs PHP's built-in
 * server: php -S 127.0.0.1:<port> -t app/public app/public/index.php from a
 * checkout, or a router requiring this file from inside the phar for the
 * standalone binary.
 */

require dirname(__DIR__).'/vendor/autoload.php';

// Under the built-in server, real files (the compiled assets) bypass the
// kernel. From a checkout PHP serves them itself; inside a phar it cannot
// (it only serves files from disk), so this file does.
if ('cli-server' === \PHP_SAPI) {
    $path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH));
    $file = __DIR__.$path;
    if ('' !== $path && '/' !== $path && !str_contains($path, '..') && !str_ends_with($path, '.php') && is_file($file)) {
        if (!str_starts_with(__DIR__, 'phar://')) {
            return false;
        }

        $types = [
            'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
            'json' => 'application/json', 'map' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'txt' => 'text/plain; charset=utf-8',
        ];
        header('Content-Type: '.($types[strtolower(pathinfo($file, \PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
        // Compiled asset file names carry a content hash.
        header('Cache-Control: '.(str_starts_with($path, '/assets/') ? 'public, max-age=31536000, immutable' : 'no-cache'));
        header('Content-Length: '.filesize($file));
        readfile($file);

        return true;
    }
}

$env = Kernel::defaultEnvironment();
$kernel = new Kernel($env, 'prod' !== $env);

$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
