<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;

use function Castor\context;
use function Castor\PHPQa\php_cs_fixer;
use function Castor\PHPQa\phpstan;
use function Castor\run;

/**
 * PABLO QA tasks, powered by the remote `castor-php/php-qa` package
 * (see castor.composer.json). Runs against the Symfony Console app in app/.
 */

#[AsTask(name: 'cs-fixer', namespace: 'qa', description: 'Fix coding standards in app/src and app/tests')]
function qa_cs_fixer(): void
{
    php_cs_fixer([
        'fix', '--config', __DIR__ . '/app/.php-cs-fixer.php',
        '--path-mode', 'intersection',
        __DIR__ . '/app/src', __DIR__ . '/app/tests',
    ]);
}

#[AsTask(name: 'phpstan', namespace: 'qa', description: 'Run PHPStan static analysis on app/src and app/tests', aliases: ['phpstan'])]
function qa_phpstan(bool $generateBaseline = false): void
{
    $params = ['analyse', '--configuration', __DIR__ . '/app/phpstan.neon', '--memory-limit=-1', '-v'];
    if ($generateBaseline) {
        $params[] = '--generate-baseline';
        $params[] = 'app/phpstan-baseline.neon';
    }

    phpstan($params);
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
