<?php

/**
 * Importmap for the PABLO dashboard.
 *
 * Remote packages are vendored into assets/vendor/ by
 * `php bin/console importmap:install` and COMMITTED — bin/install.sh never
 * runs that command, so an un-vendored entry means a dashboard with no
 * JavaScript on a fresh clone. See the anchored `vendor/` rule in .gitignore.
 *
 * The two @symfony/* entries resolve through the asset-path namespaces the UX
 * bundles register themselves (`bin/console debug:asset-map`), so they need no
 * vendoring and stay in lockstep with the installed PHP packages.
 *
 * @return array<string, array{
 *     path: string,
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }|array{
 *     version: string,
 *     package_specifier?: string,
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@symfony/stimulus-bundle' => ['path' => '@symfony/stimulus-bundle/loader.js'],
    '@symfony/ux-live-component' => ['path' => '@symfony/ux-live-component/live_controller.js'],
    'bulma/css/bulma.min.css' => ['version' => '1.0.4', 'type' => 'css'],
];
