<?php

declare(strict_types=1);

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Symfony\Component\Finder\Finder;

use function Castor\context;
use function Castor\finder;
use function Castor\fs;
use function Castor\io;
use function Castor\PHPQa\php_cs_fixer;
use function Castor\PHPQa\phpstan;
use function Castor\PHPQa\twig_cs_fixer;
use function Castor\run;

/**
 * PABLO QA tasks, powered by the remote `castor-php/php-qa` package
 * (see castor.composer.json). Runs against the Symfony Console app in app/.
 */

#[AsTask(name: 'cs-fixer', namespace: 'qa', description: 'Fix coding standards in app/{src,tests,config,public} and the site builder')]
function qa_cs_fixer(): void
{
    // --path-mode intersection means only paths listed here are ever fixed,
    // whatever the Finder in .php-cs-fixer.php sees. Keep the two in step.
    php_cs_fixer([
        'fix', '--config', __DIR__ . '/app/.php-cs-fixer.php',
        '--path-mode', 'intersection',
        __DIR__ . '/app/src', __DIR__ . '/app/tests',
        __DIR__ . '/app/config', __DIR__ . '/app/public',
        __DIR__ . '/site/build/src', __DIR__ . '/site/build/build.php',
    ]);
}

#[AsTask(name: 'cs:check', namespace: 'qa', description: 'Check coding standards without modifying files (CI)')]
function qa_cs_check(): void
{
    php_cs_fixer([
        'fix', '--config', __DIR__ . '/app/.php-cs-fixer.php',
        '--path-mode', 'intersection', '--dry-run', '--diff',
        __DIR__ . '/app/src', __DIR__ . '/app/tests',
        __DIR__ . '/app/config', __DIR__ . '/app/public',
        __DIR__ . '/site/build/src', __DIR__ . '/site/build/build.php',
    ]);
}

#[AsTask(name: 'twig-cs-fixer', namespace: 'qa', description: 'Lint and fix Twig templates in app/templates')]
function qa_twig_cs_fixer(): void
{
    twig_cs_fixer(['lint', '--fix', __DIR__ . '/app/templates']);
}

#[AsTask(name: 'phpstan', namespace: 'qa', description: 'Run PHPStan static analysis on app/src and app/tests', aliases: ['phpstan'])]
function qa_phpstan(bool $generateBaseline = false): void
{
    $args = ['analyse', '--configuration', __DIR__ . '/app/phpstan.neon', '--memory-limit=-1', '-v'];
    if ($generateBaseline) {
        $args[] = '--generate-baseline';
        $args[] = __DIR__ . '/app/phpstan-baseline.neon';
    }

    // Prefer phpstan installed as an app dev dependency: it runs in CI from the
    // normal `composer install` (see .github/workflows/ci.yml) and avoids the
    // fragile in-process Composer tool sandbox. The sandbox below stays as the
    // fallback for checkouts that never ran `composer install` in app/.
    $appBinary = __DIR__ . '/app/vendor/bin/phpstan';
    if (is_file($appBinary) && is_file(__DIR__ . '/app/vendor/autoload.php')) {
        run(['php', $appBinary, ...$args]);

        return;
    }

    // The static castor runtime ships without ext-simplexml, which
    // phpstan/phpstan-symfony requires at install time; skip that one platform
    // check so the tool sandbox installs on any castor build (CI included).
    putenv('COMPOSER_IGNORE_PLATFORM_REQS=ext-simplexml');

    // The Symfony extension is installed into the phpstan tool sandbox, not
    // app/composer.json — that is not where this phpstan runs from.
    // containerXmlPath is deliberately NOT configured: it would require a
    // warmed debug cache and break analysis on a clean checkout.
    phpstan($args, extraDependencies: [
        'phpstan/extension-installer' => '^1.4',
        'phpstan/phpstan-symfony' => '^2.0',
    ]);
}

#[AsTask(name: 'test', namespace: 'qa', description: 'Run the PHPUnit suite', aliases: ['test'])]
function qa_test(): void
{
    run(
        ['composer', 'test'],
        context()->withWorkingDirectory(__DIR__ . '/app'),
    );
}

#[AsTask(name: 'build', namespace: 'site', description: 'Build the website (site/) into _site/')]
function site_build(string $base = '/'): void
{
    if (!is_file(__DIR__ . '/site/build/vendor/autoload.php')) {
        run(['composer', 'install', '--no-interaction'], context()->withWorkingDirectory(__DIR__ . '/site/build'));
    }
    run(['php', __DIR__ . '/site/build/build.php', '--base=' . $base]);
}

#[AsTask(name: 'serve', namespace: 'site', description: 'Build the website and preview it on http://127.0.0.1:8000')]
function site_serve(int $port = 8000): void
{
    site_build();
    io()->success("Serving _site/ on http://127.0.0.1:{$port} — Ctrl+C to stop");
    run(['php', '-S', "127.0.0.1:{$port}", '-t', __DIR__ . '/_site'], context()->withTimeout(null));
}

#[AsTask(name: 'install', namespace: '', description: 'Install from this checkout: app dependencies, then pablo system:install (shim, agents/commands, scheduler)')]
function install(): void
{
    // App dependencies (incl. dev tools such as PHPUnit), then the qa tools
    // (phpstan / php-cs-fixer) are fetched on first use by the qa:* tasks.
    run(
        ['composer', 'install'],
        context()->withWorkingDirectory(__DIR__ . '/app'),
    );
    // From a checkout, system:install writes a ~/.local/bin/pablo shim that
    // runs `php app/bin/pablo` (the binary is what end users install).
    run(['php', __DIR__ . '/app/bin/pablo', 'system:install']);
}

/** Extensions compiled into the static PHP (static-php-cli); keep in step with .github/workflows/binary.yml. */
const BINARY_EXTENSIONS = 'phar,ctype,iconv,mbstring,tokenizer,filter,posix,pcntl,dom,xml,zlib,openssl';

/**
 * ini baked into the binary by `spc micro:combine`; keep in step with
 * .github/workflows/binary.yml. No opcache.enable_cli=1: with PHP 8.5 micro
 * (static-php-cli 2.8.6) it segfaults at startup, even on a one-line script.
 */
const BINARY_INI = ['memory_limit=512M'];

#[AsTask(name: 'binary', namespace: 'build', description: 'Build the standalone binary: Box phar (dist/pablo.phar), then micro.sfx + phar (dist/pablo)')]
function build_binary(
    #[AsOption(description: 'micro.sfx built by static-php-cli (spc build ... --build-micro); without it only the phar is built')]
    string $micro = '',
    #[AsOption(description: 'static-php-cli binary used for micro:combine')]
    string $spc = 'spc',
    #[AsOption(description: 'Box binary (default: box on the PATH, else a box.phar downloaded into dist/tools)')]
    string $box = '',
): void {
    $dist = __DIR__ . '/dist';
    $stage = $dist . '/stage';

    // Build from a copy: `composer install --no-dev` and asset-map:compile must
    // not touch the checkout (its vendor/ has the dev tools, and compiled
    // assets in app/public/assets would shadow the dev AssetMapper).
    io()->section('Staging the sources in dist/stage');
    fs()->remove($stage);
    $copy = static function (string $from, ?Finder $finder = null) use ($stage): void {
        $finder ??= finder()->in(__DIR__ . '/' . $from)->ignoreDotFiles(false);
        fs()->mirror(__DIR__ . '/' . $from, $stage . '/' . $from, $finder);
    };
    // Anchored notPath(), not exclude(): exclude('vendor') would also drop
    // assets/vendor (the dashboard's vendored JS).
    $copy('app', finder()->in(__DIR__ . '/app')->ignoreDotFiles(false)
        ->notPath(['#^vendor(/|$)#', '#^var(/|$)#', '#^tests(/|$)#', '#^public/assets(/|$)#', '#^config/reference\.php$#']));
    foreach (['opencode', 'systemd', 'launchd'] as $directory) {
        $copy($directory);
    }
    fs()->copy(__DIR__ . '/examples/config.yaml', $stage . '/examples/config.yaml');

    io()->section('composer install --no-dev');
    run(
        ['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--classmap-authoritative', '--no-progress'],
        context()->withWorkingDirectory($stage . '/app'),
    );

    io()->section('asset-map:compile');
    run(['php', 'bin/console', 'asset-map:compile', '--env=prod', '--no-debug'], context()->withWorkingDirectory($stage . '/app'));

    // Box's `git-version` needs a git repository at its base path, which the
    // stage is not: compute the same value (the tag on HEAD, else the short
    // hash) from the checkout and pass it as a plain replacement.
    $version = trim(run(['git', 'describe', '--tags', '--exact-match', 'HEAD'], context()->withQuiet()->withAllowFailure())->getOutput());
    if ('' === $version) {
        $version = trim(run(['git', 'log', '--pretty=%h', '-n1', 'HEAD'], context()->withQuiet())->getOutput());
    }
    $config = json_decode((string) file_get_contents(__DIR__ . '/app/box.json'), true, flags: \JSON_THROW_ON_ERROR);
    unset($config['git-version']);
    $config['replacements'] = ['package_version' => $version];
    file_put_contents($stage . '/app/box.json', json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

    io()->section("box compile (version {$version})");
    $box = '' !== $box ? $box : box_binary($dist . '/tools');
    run(['php', '-d', 'phar.readonly=0', $box, 'compile', '--no-interaction', '--config', $stage . '/app/box.json'], context()->withWorkingDirectory($stage . '/app'));
    fs()->copy($stage . '/dist/pablo.phar', $dist . '/pablo.phar', true);
    io()->success('dist/pablo.phar');

    if ('' === $micro) {
        io()->note([
            'No --micro given: only the phar was built (it needs a PHP to run).',
            'Build a micro.sfx with static-php-cli, then pass it with --micro:',
            '  spc download --for-extensions=' . BINARY_EXTENSIONS . ' --with-php=8.5 --prefer-pre-built',
            '  spc build ' . BINARY_EXTENSIONS . ' --build-micro   # -> buildroot/bin/micro.sfx',
        ]);

        return;
    }

    io()->section('spc micro:combine');
    $combine = [$spc, 'micro:combine', $dist . '/pablo.phar', '--with-micro=' . $micro, '--output=' . $dist . '/pablo'];
    foreach (BINARY_INI as $ini) {
        $combine[] = '-I';
        $combine[] = $ini;
    }
    // spc writes its log/ into the working directory: keep it out of the checkout.
    run($combine, context()->withWorkingDirectory($dist));
    run([$dist . '/pablo', '--version']);
    io()->success('dist/pablo');
}

#[AsTask(name: 'create', namespace: 'release', description: 'Publish a release of the last main commit (named after its short hash) through the Binary workflow')]
function release_create(
    #[AsOption(description: 'do not flag the release as the latest one (the installer and pablo update keep installing the current latest)')]
    bool $noLatest = false,
): int {
    // The release is made by .github/workflows/binary.yml, not here: it only
    // creates it once the four binaries are built, so `releases/latest`
    // (what the installer and `pablo update` read) never points at a release
    // without binaries.
    run(['git', 'fetch', '--quiet', 'origin', 'main']);
    $sha = trim(run(['git', 'rev-parse', 'origin/main'], context()->withQuiet())->getOutput());
    // The short hash Box writes into the binaries (git-version on an untagged commit).
    $name = trim(run(['git', 'log', '--pretty=%h', '-n1', 'origin/main'], context()->withQuiet())->getOutput());

    $existing = run(['gh', 'release', 'view', $name, '--json', 'name'], context()->withQuiet()->withAllowFailure());
    if ($existing->isSuccessful()) {
        io()->error("A release named {$name} already exists (main has not moved since).");

        return 1;
    }

    run([
        'gh', 'workflow', 'run', 'binary.yml', '--ref', 'main',
        '-f', 'release=true',
        '-f', 'latest='.($noLatest ? 'false' : 'true'),
    ]);

    io()->success([
        "Binary workflow started on main ({$sha}).",
        "Release {$name} is published once the four builds pass".($noLatest ? ', without the latest flag.' : ', flagged latest.'),
        'Follow it with: gh run list --workflow binary.yml   (then gh run watch <id>)',
    ]);

    return 0;
}

#[AsTask(name: 'list', namespace: 'release', description: 'List the last releases (the "Latest" one is what the installer and pablo update install)')]
function release_list(
    #[AsOption(description: 'how many releases to show')]
    int $limit = 10,
): void {
    run(['gh', 'release', 'list', '--limit', (string) $limit]);
}

/** `box` from the PATH, else the latest box.phar, downloaded once into $tools. */
function box_binary(string $tools): string
{
    $onPath = trim(run(['sh', '-c', 'command -v box || true'], context()->withQuiet())->getOutput());
    if ('' !== $onPath) {
        return $onPath;
    }
    $phar = $tools . '/box.phar';
    if (!is_file($phar)) {
        fs()->mkdir($tools);
        run(['curl', '-fsSL', '-o', $phar, 'https://github.com/box-project/box/releases/latest/download/box.phar']);
    }

    return $phar;
}
