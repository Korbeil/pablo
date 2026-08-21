<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Command\System\ProjectRemoveCommand;
use Pablo\Config\Config;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Store\Store;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ProjectRemoveCommandTest extends TestCase
{
    use UsesGlobalConfig;

    private string $tmp;
    private string $projectsDir;
    private Store $store;
    private FakeGenerateAgentsCommand $fakeGenerateAgents;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-project-remove-'.uniqid();
        $this->projectsDir = $this->tmp.'/projects';
        mkdir($this->projectsDir, 0o777, true);
        $this->store = new Store($this->tmp.'/state');
        $this->fakeGenerateAgents = new FakeGenerateAgentsCommand();

        $this->writeGlobalConfig(<<<'YAML'
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
YAML);

        putenv('PABLO_PROJECTS_DIR='.$this->projectsDir);

        $this->writeProject('acme.yaml', 'acme', 'jira');
        $this->writeProject('wallet-kit.yaml', 'wallet-kit', 'github');
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
        $this->unsetGlobalConfig();
        $this->removeDir($this->tmp);
    }

    public function testUnknownNameFailsWithoutTouchingAnything(): void
    {
        $tester = $this->runRemove(['name' => 'nope']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("unknown project 'nope'", $tester->getDisplay());
        $this->assertFileExists($this->projectsDir.'/acme.yaml');
        $this->assertSame(0, $this->fakeGenerateAgents->runs);
    }

    public function testRefusesWhileActiveTasksExist(): void
    {
        $task = new Task('acme', 'xxx-123', $this->tmp.'/wt/xxx-123', State::InProgress);
        $this->store->save($task);

        $tester = $this->runRemove(['name' => 'acme']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('still has active task(s)', $tester->getDisplay());
        $this->assertStringContainsString('pablo task:close', $tester->getDisplay());
        $this->assertFileExists($this->projectsDir.'/acme.yaml');
        $this->assertSame(0, $this->fakeGenerateAgents->runs);
    }

    public function testRemovesConfigAndRegeneratesAgents(): void
    {
        $tester = $this->runRemove(['name' => 'acme']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->projectsDir.'/acme.yaml');
        $this->assertSame(1, $this->fakeGenerateAgents->runs, 'agents must be re-generated after removal');
        $this->assertStringContainsString('Removed:', $tester->getDisplay());

        // The surviving project must still load, and the final output says
        // cleanup stays task:close's job.
        $projects = Config::loadProjects($this->projectsDir);
        $this->assertArrayNotHasKey('acme', $projects);
        $this->assertArrayHasKey('wallet-kit', $projects);
        $this->assertStringContainsString('pablo task:close', $tester->getDisplay());
        $this->assertStringContainsString('left untouched', $tester->getDisplay());
    }

    /** The file name may differ from the project name — resolve via the YAML "name" key. */
    public function testResolvesByProjectNameNotFileName(): void
    {
        rename($this->projectsDir.'/acme.yaml', $this->projectsDir.'/some-weird-filename.yaml');

        $tester = $this->runRemove(['name' => 'acme']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->projectsDir.'/some-weird-filename.yaml');
        $this->assertSame(1, $this->fakeGenerateAgents->runs);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runRemove(array $input): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ProjectRemoveCommand($this->store));
        $application->addCommand($this->fakeGenerateAgents);

        $tester = new CommandTester($application->find('project:remove'));
        $tester->execute($input);

        return $tester;
    }

    private function writeProject(string $fileName, string $name, string $provider): void
    {
        file_put_contents($this->projectsDir.'/'.$fileName, <<<YAML
name: {$name}
type: work
repo:
  path: {$this->tmp}/{$name}
  primary_branch: main
issue_tracker:
  provider: {$provider}
  identity: u@e.com
  project_key: XX
YAML);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
