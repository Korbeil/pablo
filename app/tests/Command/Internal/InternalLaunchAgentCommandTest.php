<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Internal;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Command\Internal\InternalLaunchAgentCommand;
use Pablo\Tests\Command\CommandTestBed;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command runs the OpenChamber backend against a canned FakeProcessRunner
 * (session create → sessionId, everything else ok). PABLO_SHIM points spawnWatcher's
 * detached self-reinvocation at /bin/true and PABLO_STATE_DIR/PABLO_AGENTS_DIR keep
 * all state writes inside the tmp dir — nothing real is ever launched or touched.
 */
final class InternalLaunchAgentCommandTest extends CommandTestBed
{
    private string $agentsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agentsDir = $this->tmp.'/agents';
        mkdir($this->agentsDir, 0o777, true);
        putenv('PABLO_AGENTS_DIR='.$this->agentsDir);
        putenv('PABLO_STATE_DIR='.$this->tmp.'/state');
        putenv('PABLO_SHIM=/bin/true');
    }

    protected function tearDown(): void
    {
        putenv('PABLO_AGENTS_DIR');
        putenv('PABLO_STATE_DIR');
        putenv('PABLO_SHIM');
        parent::tearDown();
    }

    public function testResolvesModelFromProjectConfig(): void
    {
        $calls = [];
        $this->runner->onRun = function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            if (\in_array('create', $argv, true)) {
                return (string) json_encode(['ok' => true, 'result' => ['sessionId' => 'ses_x', 'directory' => $this->wt, 'title' => 'pablo:test']]);
            }

            return (string) json_encode(['ok' => true, 'result' => []]);
        };
        // The factory the command uses must build its launcher on the same fake
        // runner — the bed's default factory carries a real ProcessRunner.
        $factory = new AgentLauncherFactory($this->globalConfig, $this->runner);

        $command = new InternalLaunchAgentCommand($this->analytics, $this->time, $this->store, $this->projectsLoader, $factory, $this->agents);
        $tester = new CommandTester($command);
        $tester->execute([
            '--backend' => 'openchamber',
            '--worktree' => $this->wt,
            '--agent' => 'task-analyst',
            '--prompt' => 'Analyze issue #45',
            '--project' => 'wallet-kit',
            '--branch' => 'wk-45',
        ]);
        $this->assertSame(0, $tester->getStatusCode());

        $create = array_values(array_filter($calls, static fn (array $argv): bool => \in_array('create', $argv, true)))[0];
        $modelKey = array_search('--model', $create, true);
        $this->assertIsInt($modelKey, 'session create must carry --model');
        $this->assertSame('openrouter/test/model', $create[$modelKey + 1]);
    }

    public function testUnknownProjectFallsBackToNullModel(): void
    {
        $calls = [];
        $this->runner->onRun = function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            if (\in_array('create', $argv, true)) {
                return (string) json_encode(['ok' => true, 'result' => ['sessionId' => 'ses_x', 'directory' => $this->wt, 'title' => 'pablo:test']]);
            }

            return (string) json_encode(['ok' => true, 'result' => []]);
        };
        $factory = new AgentLauncherFactory($this->globalConfig, $this->runner);

        $command = new InternalLaunchAgentCommand($this->analytics, $this->time, $this->store, $this->projectsLoader, $factory, $this->agents);
        $tester = new CommandTester($command);
        $tester->execute([
            '--backend' => 'openchamber',
            '--worktree' => $this->wt,
            '--agent' => 'task-analyst',
            '--prompt' => 'Analyze issue #45',
            '--project' => 'nope',
            '--branch' => 'wk-45',
        ]);
        $this->assertSame(0, $tester->getStatusCode());

        $create = array_values(array_filter($calls, static fn (array $argv): bool => \in_array('create', $argv, true)))[0];
        // repo agent files (opencode/agents/task-analyst.md) still carry the
        // shared frontmatter model — that is the fallback the launch rides on.
        $modelKey = array_search('--model', $create, true);
        $this->assertIsInt($modelKey, 'frontmatter fallback must still supply a model');
        $this->assertNotSame('openrouter/test/model', $create[$modelKey + 1]);
    }
}
