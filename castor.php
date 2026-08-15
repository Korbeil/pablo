<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;

use function Castor\context;
use function Castor\PHPQa\php_cs_fixer;
use function Castor\PHPQa\phpstan;
use function Castor\PHPQa\twig_cs_fixer;
use function Castor\run;

/**
 * PABLO QA tasks, powered by the remote `castor-php/php-qa` package
 * (see castor.composer.json). Runs against the Symfony Console app in app/.
 */

#[AsTask(name: 'cs-fixer', namespace: 'qa', description: 'Fix coding standards in app/{src,tests,config,public}')]
function qa_cs_fixer(): void
{
    // --path-mode intersection means only paths listed here are ever fixed,
    // whatever the Finder in .php-cs-fixer.php sees. Keep the two in step.
    php_cs_fixer([
        'fix', '--config', __DIR__ . '/app/.php-cs-fixer.php',
        '--path-mode', 'intersection',
        __DIR__ . '/app/src', __DIR__ . '/app/tests',
        __DIR__ . '/app/config', __DIR__ . '/app/public',
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
    $params = ['analyse', '--configuration', __DIR__ . '/app/phpstan.neon', '--memory-limit=-1', '-v'];
    if ($generateBaseline) {
        $params[] = '--generate-baseline';
        $params[] = 'app/phpstan-baseline.neon';
    }

    // The Symfony extension is installed into the phpstan tool sandbox, not
    // app/composer.json — that is not where this phpstan runs from.
    // containerXmlPath is deliberately NOT configured: it would require a
    // warmed debug cache and break analysis on a clean checkout.
    phpstan($params, extraDependencies: [
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

#[AsTask(name: 'install', namespace: '', description: 'Install everything: app dependencies, php-qa tools, shim, agents/commands, scheduler')]
function install(): void
{
    // App dependencies (incl. dev tools such as PHPUnit), then the qa tools
    // (phpstan / php-cs-fixer) are fetched on first use by the qa:* tasks.
    run(
        ['composer', 'install'],
        context()->withWorkingDirectory(__DIR__ . '/app'),
    );
    run(['./bin/install.sh']);
}
