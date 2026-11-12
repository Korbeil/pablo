<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Report;

use Pablo\Command\Report\TasksCommand;
use Pablo\Dispatch\Stamps;
use Pablo\Domain\DisplayCache;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Listing\Listing;
use Pablo\Tests\Command\CommandTestBed;

/**
 * task:list's --project / --type / --state filters.
 */
final class TasksCommandTest extends CommandTestBed
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('PABLO_STAMPS_DIR='.$this->tmp.'/stamps');
        mkdir($this->tmp.'/repo2', 0o777, true);
        file_put_contents($this->projectsDir.'/home-lab.yaml', <<<YAML
            name: home-lab
            type: personal
            repo:
              path: {$this->tmp}/repo2
              primary_branch: main
            worktrees_root: {$this->tmp}/wt2
            issue_tracker:
              provider: github
              identity: octocat
              project_key: HL
            YAML);
        $this->store->save($this->cached(new Task('home-lab', 'hl-1', $this->tmp.'/wt2/hl-1', State::Draft)));
        $this->store->save($this->cached(new Task('home-lab', 'hl-2', $this->tmp.'/wt2/hl-2', State::CiRed)));
        $wk = $this->getTask();
        $this->store->save($this->cached($wk));
    }

    protected function tearDown(): void
    {
        putenv('PABLO_STAMPS_DIR');
        parent::tearDown();
    }

    public function testNoFilterListsEverything(): void
    {
        $display = $this->list([])->getDisplay();
        $this->assertStringContainsString('wk-45', $display);
        $this->assertStringContainsString('hl-1', $display);
        $this->assertStringContainsString('hl-2', $display);
    }

    public function testProjectFilter(): void
    {
        $display = $this->list(['--project' => 'home-lab'])->getDisplay();
        $this->assertStringNotContainsString('wk-45', $display);
        $this->assertStringContainsString('hl-1', $display);
        $this->assertStringContainsString('hl-2', $display);
    }

    public function testUnknownProjectFails(): void
    {
        $tester = $this->list(['--project' => 'nope']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("unknown --project 'nope'; configured: home-lab, wallet-kit", $tester->getErrorOutput());
    }

    public function testTypeFilter(): void
    {
        $display = $this->list(['--type' => 'open-source'])->getDisplay();
        $this->assertStringContainsString('wk-45', $display);
        $this->assertStringNotContainsString('hl-1', $display);

        $this->assertSame('no active tasks', trim($this->list(['--type' => 'work'])->getDisplay()));
    }

    public function testInvalidTypeFails(): void
    {
        $tester = $this->list(['--type' => 'hobby']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("invalid --type 'hobby'", $tester->getErrorOutput());
    }

    public function testRepeatableStateFilter(): void
    {
        $display = $this->list(['--state' => ['draft', 'in-progress']])->getDisplay();
        $this->assertStringContainsString('wk-45', $display);
        $this->assertStringContainsString('hl-1', $display);
        $this->assertStringNotContainsString('hl-2', $display);
    }

    public function testFiltersCombine(): void
    {
        $display = $this->list(['--project' => 'home-lab', '--state' => ['ci-red']])->getDisplay();
        $this->assertStringNotContainsString('wk-45', $display);
        $this->assertStringNotContainsString('hl-1', $display);
        $this->assertStringContainsString('hl-2', $display);
    }

    public function testInvalidStateFails(): void
    {
        $tester = $this->list(['--state' => ['nope']]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("invalid --state 'nope'; expected one of: in-progress", $tester->getErrorOutput());
    }

    /** @param array<string, mixed> $input */
    private function list(array $input): \Symfony\Component\Console\Tester\CommandTester
    {
        $listing = new Listing(new Stamps(), $this->gh, $this->git, $this->providers, $this->repoSlug, $this->stateMachine, $this->time);

        return $this->runCommand(
            new TasksCommand($listing, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents),
            $input,
            ['capture_stderr_separately' => true],
        );
    }

    /** A polled task: the listing renders it without any live fetch. */
    private function cached(Task $task): Task
    {
        $task->displayCache = new DisplayCache(trackerStatus: 'Open', prState: '-', agentCount: 0, agentActivity: '-', at: $this->time->utcnow());

        return $task;
    }
}
