<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\App\ConsoleApplication;
use Pablo\App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleTest extends TestCase
{
    use RestoresErrorHandlers;
    use UsesGlobalConfig;

    private const DEFAULTS = <<<'YAML'
sync:
  strategy: rebase
  auto_apply: false
  interval_minutes: 30
state_polling:
  interval_minutes: 10
review:
  bot_whitelist: []
ci:
  ignore_checks: []
default_model: openrouter/test/model
pr_description_locale: en
YAML;

    private const PROJECT = <<<'YAML'
name: wallet-kit
type: open-source
repo:
  path: /tmp/pablo-console/wallet-kit
  primary_branch: main
worktrees_root: /tmp/pablo-console/wt
issue_tracker:
  provider: github
  identity: octocat
  project_key: WK
YAML;

    private string $projectsDir = '';

    private ?Kernel $kernel = null;

    protected function setUp(): void
    {
        $this->projectsDir = TempDir::create('projects');
        $this->writeGlobalConfig(self::DEFAULTS);
        file_put_contents($this->projectsDir.'/wallet-kit.yaml', self::PROJECT);
        putenv('PABLO_PROJECTS_DIR='.$this->projectsDir);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        $this->restoreErrorHandlers();
        putenv('PABLO_PROJECTS_DIR');
        $this->unsetGlobalConfig();
    }

    private function app(): ConsoleApplication
    {
        $this->snapshotErrorHandlers();

        // debug:true, always: only a debug kernel checks its cached container
        // in var/cache/test for freshness. With debug:false a rebase that
        // reorders a constructor's arguments keeps the old container and the
        // test fails with a TypeError from var/cache/test until it is deleted.
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();

        $app = $this->kernel->getContainer()->get(ConsoleApplication::class);
        if (!$app instanceof ConsoleApplication) {
            throw new \RuntimeException('Container did not return a ConsoleApplication');
        }
        $app->setAutoExit(false);

        return $app;
    }

    public function testRegistersAllCommands(): void
    {
        $app = $this->app();
        $names = array_keys($app->all());
        foreach ([
            'task:start', 'sync:run', 'sync:log', 'task:list', 'project:list',
            'project:new', 'project:remove',
            'system:doctor', 'system:dispatch', 'system:poll', 'task:state', 'task:relaunch', 'task:waiting',
            'task:skip-ci', 'task:retrigger-ci', 'task:close', 'task:precommit-check', 'task:info', 'task:history', 'stats',
        ] as $name) {
            $this->assertContains($name, $names, "missing command {$name}");
        }
    }

    public function testProjectsExitsZero(): void
    {
        $tester = new CommandTester($this->app()->find('project:list'));
        $tester->execute([]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testPrecommitCheckOutsideWorktreeIsInvalid(): void
    {
        $tester = new CommandTester($this->app()->find('task:precommit-check'));
        $tester->execute([]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function testPrecommitCheckAcceptsJsonFlag(): void
    {
        $tester = new CommandTester($this->app()->find('task:precommit-check'));
        $tester->execute(['--json' => true]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
