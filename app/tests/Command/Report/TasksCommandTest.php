<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Report;

use Pablo\Agents\SessionInfo;
use Pablo\Command\Report\TasksCommand;
use Pablo\Dispatch\Stamps;
use Pablo\Domain\DisplayCache;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Listing\Listing;
use Pablo\Provider\Gh\PrInfo;
use Pablo\Tests\Command\CommandTestBed;

/**
 * task:list's --project / --type / --state filters and --format=json.
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

    public function testJsonFromTheDisplayCache(): void
    {
        $task = $this->getTask();
        $task->state = State::RequestChanges;
        $task->displayCache = new DisplayCache('In Progress', '📝 draft #7', 1, '💭 1', '2026-11-12T10:00:00Z');
        $this->store->save($task);
        $this->gh->onPrForBranch = function (): never {
            $this->fail('a cached listing makes no gh call');
        };

        $tester = $this->list(['--format' => 'json', '--project' => 'wallet-kit']);

        $this->assertSame(0, $tester->getStatusCode());
        $payload = $this->json($tester->getDisplay());
        $this->assertCount(1, $payload['tasks']);
        $row = $payload['tasks'][0];
        $this->assertSame('wallet-kit', $row['project']);
        $this->assertSame('wk-45', $row['branch']);
        $this->assertSame('request-changes', $row['state']);
        $this->assertSame('request-changes', $row['state_label']);
        $this->assertTrue($row['waiting_for_feedback']);
        $this->assertSame('In Progress', $row['tracker_status']);
        $this->assertSame('45', $row['issue']['key']);
        $this->assertSame(['number' => 7, 'url' => 'https://github.com/acme/wallet-kit/pull/7', 'state' => 'draft', 'draft' => true], $row['pr']);
        $this->assertSame(['red' => false, 'ignored' => false], $row['ci']);
        $this->assertSame(['total' => 1, 'running' => 0, 'waiting' => 1], $row['agents']);
        $this->assertSame($this->wt, $row['worktree_path']);
        $this->assertSame('2026-11-12T10:00:00Z', $row['cached_at']);
        $this->assertArrayHasKey('state_entered_at', $row);
    }

    public function testJsonRespectsTheFilters(): void
    {
        $branches = fn (array $input): array => array_column($this->json($this->list(['--format' => 'json'] + $input)->getDisplay())['tasks'], 'branch');

        $this->assertEqualsCanonicalizing(['wk-45', 'hl-1', 'hl-2'], $branches([]));
        $this->assertEqualsCanonicalizing(['hl-1', 'hl-2'], $branches(['--project' => 'home-lab']));
        $this->assertSame(['wk-45'], $branches(['--type' => 'open-source']));
        $this->assertSame(['hl-2'], $branches(['--project' => 'home-lab', '--state' => ['ci-red']]));
        $this->assertSame([], $branches(['--type' => 'work']));
    }

    public function testRefreshFetchesAndPersistsBeforeEmittingJson(): void
    {
        $this->gh->prByBranch['wk-45'] = new PrInfo(7, 'PR', 'OPEN', false, 'u', null);
        $this->agents->bulk[$this->wt] = [new SessionInfo('h', 'running')];

        $tester = $this->list(['--format' => 'json', '--refresh' => true, '--project' => 'wallet-kit']);

        $row = $this->json($tester->getDisplay())['tasks'][0];
        $this->assertSame('open', $row['pr']['state']);
        $this->assertFalse($row['pr']['draft']);
        $this->assertSame('todo', $row['tracker_status']);
        $this->assertSame(['total' => 1, 'running' => 1, 'waiting' => 0], $row['agents']);
        $this->assertSame('📖 open #7', $this->getTask()->displayCache->prState);
    }

    public function testNoTasksIsAnEmptyList(): void
    {
        $this->assertSame(['tasks' => []], $this->json($this->list(['--format' => 'json', '--type' => 'work'])->getDisplay()));
    }

    public function testTableStaysTheDefault(): void
    {
        $tester = $this->list([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('🔨 in-progress', $tester->getDisplay());
    }

    public function testAnUnknownFormatIsRefused(): void
    {
        $tester = $this->list(['--format' => 'yaml']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("invalid --format 'yaml'; expected one of: table, json", $tester->getErrorOutput());
    }

    /** @return array<string, mixed> */
    private function json(string $display): array
    {
        $payload = json_decode($display, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    /** @param array<string, mixed> $input */
    private function list(array $input): \Symfony\Component\Console\Tester\CommandTester
    {
        $listing = new Listing(new Stamps(), $this->gh, $this->providers, $this->repoSlug, $this->stateMachine, $this->time);

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
