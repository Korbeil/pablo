<?php

declare(strict_types=1);

/*
 * Builds the PABLO website into _site/ (repo root):
 *
 *   php site/build/build.php [--base=/] [--out=_site]
 *
 * --base is the URL path the site is served under: "/" on GitHub Pages (custom domain getpablo.dev),
 * "/pablo/" for a project-page preview.
 */

use Pablo\Site\SiteBuilder;

require __DIR__.'/vendor/autoload.php';

$options = getopt('', ['base::', 'out::']);
$option = static fn (string $name, string $default): string => is_string($options[$name] ?? null) ? $options[$name] : $default;

$root = dirname(__DIR__, 2);
$base = '/'.trim($option('base', '/'), '/').'/';
$base = '//' === $base ? '/' : $base;
$out = $option('out', $root.'/_site');

(new SiteBuilder($root.'/site/content', $root.'/site', $out, $base))->build();

fwrite(\STDOUT, "Site built in {$out} (base {$base})\n");
